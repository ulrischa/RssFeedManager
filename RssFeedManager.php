<?php
namespace ulrischa/rssfeedmanager;

class RssFeedManager {
    private $storage;
    private $config;
    private $temp_dir;

    /**
     * Constructor.
     *
     * @param StorageInterface $storage Instance of the storage abstraction (e.g. FTPStorage)
     * @param string $config_file Path to the configuration file
     * @param string|null $temp_dir Directory for temporary files (defaults to system temp dir)
     */
    public function __construct(StorageInterface $storage, string $config_file, string $temp_dir = null) {
        $this->storage   = $storage;
        $this->config    = include($config_file);
        $this->temp_dir  = $temp_dir ?: sys_get_temp_dir();
    }

    /**
     * Retrieves the feed configuration by feed ID.
     *
     * @param string $feed_id
     * @return array
     * @throws \Exception if the configuration is not found
     */
    public function get_feed_config(string $feed_id): array {
        if (isset($this->config['feeds'][$feed_id])) {
            return $this->config['feeds'][$feed_id];
        }
        throw new \Exception("Feed configuration not found for ID: " . $feed_id);
    }

    /**
     * Returns the path to the XML file based on feed ID.
     *
     * @param string $feed_id
     * @return string
     */
    private function get_feed_xml_path(string $feed_id): string {
        return $feed_id . '.xml';
    }

    /**
     * Returns the media folder path.
     *
     * @param string $feed_id
     * @return string
     */
    private function get_feed_media_folder(string $feed_id): string {
        return $feed_id . '_media/';
    }

    /**
     * Loads the RSS XML file from storage.
     * If it does not exist, creates a basic structure.
     *
     * @param string $feed_id
     * @return \DOMDocument
     * @throws \Exception
     */
    private function load_xml(string $feed_id): \DOMDocument {
        $temp_local_file = tempnam($this->temp_dir, 'rss_' . $feed_id . '_');
        try {
            $remote_path = $this->get_feed_xml_path($feed_id);
            if (!$this->storage->download_file($remote_path, $temp_local_file)) {
                // If the file does not exist, create a basic RSS structure
                $xml_content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel></channel></rss>";
                file_put_contents($temp_local_file, $xml_content);
            }
            $doc = new \DOMDocument();
            $doc->load($temp_local_file);
        } catch (\Exception $e) {
            error_log("Error loading XML: " . $e->getMessage());
            throw $e;
        } finally {
            if (file_exists($temp_local_file)) {
                unlink($temp_local_file);
            }
        }
        return $doc;
    }

    /**
     * Saves the XML file back to storage.
     *
     * @param string $feed_id
     * @param \DOMDocument $doc
     * @throws \Exception
     */
    private function save_xml(string $feed_id, \DOMDocument $doc) {
        $temp_local_file = tempnam($this->temp_dir, 'rss_' . $feed_id . '_');
        try {
            $doc->formatOutput = true;
            $doc->save($temp_local_file);
            $remote_path = $this->get_feed_xml_path($feed_id);
            if (!$this->storage->upload_file($temp_local_file, $remote_path)) {
                throw new \Exception("XML file could not be uploaded to storage.");
            }
        } catch (\Exception $e) {
            error_log("Error saving XML: " . $e->getMessage());
            throw $e;
        } finally {
            if (file_exists($temp_local_file)) {
                unlink($temp_local_file);
            }
        }
    }

    /**
     * Creates a new RSS feed entry.
     *
     * @param string $feed_id Feed identifier
     * @param array $entry_data Associative array with entry data (must include at least title and link)
     * @param string|null $image_file_path Local path to the image (optional)
     * @return bool
     * @throws \Exception
     */
    public function create_entry(string $feed_id, array $entry_data, string $image_file_path = null): bool {
        // Validate required fields
        if (empty($entry_data['title']) || empty($entry_data['link'])) {
            throw new \Exception("Required fields missing: 'title' and 'link' are necessary.");
        }
        $doc = $this->load_xml($feed_id);
        $channel = $doc->getElementsByTagName('channel')->item(0);
        if (!$channel) {
            throw new \Exception("Invalid RSS file: No <channel> element found.");
        }
        $feed_config = $this->get_feed_config($feed_id);
        $max_entries = $feed_config['max_entries'] ?? 10;
        $items = $channel->getElementsByTagName('item');
        if ($items->length >= $max_entries) {
            throw new \Exception("Maximum number of entries reached for feed: " . $feed_id);
        }
        $item = $doc->createElement('item');
        foreach ($entry_data as $key => $value) {
            $node = $doc->createElement($key, htmlspecialchars($value));
            $item->appendChild($node);
        }
        if ($item->getElementsByTagName('guid')->length === 0) {
            $guid = $doc->createElement('guid', uniqid());
            $item->appendChild($guid);
        }
        if ($image_file_path && file_exists($image_file_path)) {
            $media_folder = $this->get_feed_media_folder($feed_id);
            $this->storage->create_directory($media_folder);
            $image_file_name = time() . '_' . basename($image_file_path);
            $remote_image_path = $media_folder . $image_file_name;
            if (!$this->storage->upload_file($image_file_path, $remote_image_path)) {
                throw new \Exception("Image could not be uploaded to storage.");
            }
            $enclosure = $doc->createElement('enclosure');
            $enclosure->setAttribute('url', $remote_image_path);
            $enclosure->setAttribute('type', mime_content_type($image_file_path));
            $item->appendChild($enclosure);
        }
        $channel->appendChild($item);
        $this->save_xml($feed_id, $doc);
        return true;
    }

    /**
     * Reads an entry by its GUID.
     *
     * @param string $feed_id
     * @param string $entry_id GUID of the entry
     * @return array|null Associative array with entry data or null if not found
     */
    public function read_entry(string $feed_id, string $entry_id): ?array {
        $doc = $this->load_xml($feed_id);
        $items = $doc->getElementsByTagName('item');
        foreach ($items as $item) {
            $guid_nodes = $item->getElementsByTagName('guid');
            if ($guid_nodes->length > 0 && $guid_nodes->item(0)->nodeValue === $entry_id) {
                $entry = [];
                foreach ($item->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $entry[$child->nodeName] = $child->nodeValue;
                    }
                }
                return $entry;
            }
        }
        return null;
    }

    /**
     * Updates an existing entry.
     *
     * @param string $feed_id
     * @param string $entry_id GUID of the entry to update
     * @param array $entry_data Array with fields to update
     * @param string|null $image_file_path New image path (optional, supports blob uploads)
     * @return bool
     * @throws \Exception
     */
    public function update_entry(string $feed_id, string $entry_id, array $entry_data, string $image_file_path = null): bool {
        // If 'title' is provided, it must not be empty.
        if (isset($entry_data['title']) && empty($entry_data['title'])) {
            throw new \Exception("The 'title' field must not be empty.");
        }
        $doc = $this->load_xml($feed_id);
        $channel = $doc->getElementsByTagName('channel')->item(0);
        $found = false;
        $items = $channel->getElementsByTagName('item');
        foreach ($items as $item) {
            $guid_nodes = $item->getElementsByTagName('guid');
            if ($guid_nodes->length > 0 && $guid_nodes->item(0)->nodeValue === $entry_id) {
                foreach ($entry_data as $key => $value) {
                    // 'image_blob' is processed separately.
                    if ($key === 'image_blob') {
                        continue;
                    }
                    $node_list = $item->getElementsByTagName($key);
                    if ($node_list->length > 0) {
                        $node_list->item(0)->nodeValue = htmlspecialchars($value);
                    } else {
                        $node = $doc->createElement($key, htmlspecialchars($value));
                        $item->appendChild($node);
                    }
                }
                if ($image_file_path && file_exists($image_file_path)) {
                    $media_folder = $this->get_feed_media_folder($feed_id);
                    $this->storage->create_directory($media_folder);
                    $image_file_name = time() . '_' . basename($image_file_path);
                    $remote_image_path = $media_folder . $image_file_name;
                    if (!$this->storage->upload_file($image_file_path, $remote_image_path)) {
                        throw new \Exception("New image could not be uploaded to storage.");
                    }
                    // Remove existing image reference if available.
                    $enclosure_nodes = $item->getElementsByTagName('enclosure');
                    if ($enclosure_nodes->length > 0) {
                        $old_enclosure = $enclosure_nodes->item(0);
                        $item->removeChild($old_enclosure);
                    }
                    $enclosure = $doc->createElement('enclosure');
                    $enclosure->setAttribute('url', $remote_image_path);
                    $enclosure->setAttribute('type', mime_content_type($image_file_path));
                    $item->appendChild($enclosure);
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \Exception("Entry with ID $entry_id not found.");
        }
        $this->save_xml($feed_id, $doc);
        return true;
    }

    /**
     * Deletes an entry by its GUID.
     *
     * @param string $feed_id
     * @param string $entry_id GUID of the entry to delete
     * @return bool
     * @throws \Exception
     */
    public function delete_entry(string $feed_id, string $entry_id): bool {
        $doc = $this->load_xml($feed_id);
        $channel = $doc->getElementsByTagName('channel')->item(0);
        $found = false;
        $items = $channel->getElementsByTagName('item');
        for ($i = $items->length - 1; $i >= 0; $i--) {
            $item = $items->item($i);
            $guid_nodes = $item->getElementsByTagName('guid');
            if ($guid_nodes->length > 0 && $guid_nodes->item(0)->nodeValue === $entry_id) {
                $enclosure_nodes = $item->getElementsByTagName('enclosure');
                if ($enclosure_nodes->length > 0) {
                    $url = $enclosure_nodes->item(0)->getAttribute('url');
                    $this->storage->delete_file($url);
                }
                $channel->removeChild($item);
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \Exception("Entry with ID $entry_id not found.");
        }
        $this->save_xml($feed_id, $doc);
        return true;
    }
}
?>
