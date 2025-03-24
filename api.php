<?php
namespace ulrischa/rssfeedmanager;

header('Content-Type: application/json');
// Set CORS headers if the API is accessed from other domains.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Include required files.
require_once 'StorageInterface.php';
require_once 'FTPStorage.php';
require_once 'RssFeedManager.php';

// Log file for detailed error messages (internal only)
$log_file = __DIR__ . '/api_errors.log';
function log_error($message) {
    global $log_file;
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, $log_file);
}

// Load configuration file
$config_file = 'config.php';

// Define FTP credentials (can also be placed in a separate config)
$ftp_host = 'ftp.example.com';
$ftp_user = 'ftp_user';
$ftp_pass = 'ftp_password';
// Option to use TLS/SSL (true to enable)
$ftp_use_tls = false;

// Define temporary directory for file uploads (customizable)
$temp_dir = __DIR__ . '/temp';
if (!is_dir($temp_dir)) {
    mkdir($temp_dir, 0777, true);
}

try {
    $storage = new FTPStorage($ftp_host, $ftp_user, $ftp_pass, $ftp_use_tls);
} catch (\Exception $e) {
    log_error("FTP connection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error.']);
    exit;
}

$manager = new RssFeedManager($storage, $config_file, $temp_dir);

// Retrieve feed ID and API key via GET parameters.
$feed_id = isset($_GET['feed']) ? $_GET['feed'] : null;
$api_key = isset($_GET['key']) ? $_GET['key'] : null;
if (!$feed_id || !$api_key) {
    http_response_code(400);
    echo json_encode(['error' => 'Feed ID and API key are required.']);
    exit;
}

try {
    $feed_config = $manager->get_feed_config($feed_id);
} catch (\Exception $e) {
    log_error("Configuration error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid feed configuration.']);
    exit;
}

// Validate API key.
if ($feed_config['api_key'] !== $api_key) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Read operation: Expects an entry ID via GET.
            if (isset($_GET['id'])) {
                $entry = $manager->read_entry($feed_id, $_GET['id']);
                if ($entry) {
                    echo json_encode($entry);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Entry not found.']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'No entry ID provided.']);
            }
            break;
        case 'POST':
            // Create a new entry – data passed as POST parameters.
            $entry_data = $_POST;
            // Validate required fields.
            if (empty($entry_data['title']) || empty($entry_data['link'])) {
                http_response_code(400);
                echo json_encode(['error' => "Required fields missing: 'title' and 'link' are necessary."]);
                exit;
            }
            $image_path = null;
            // Check if a Base64-encoded image blob is provided.
            if (isset($_POST['image_blob'])) {
                $image_blob = $_POST['image_blob'];
                $image_data = base64_decode($image_blob);
                if ($image_data === false) {
                    throw new \Exception("Invalid Base64 data for image.");
                }
                $temp_image_path = tempnam($temp_dir, 'img_');
                if (file_put_contents($temp_image_path, $image_data) === false) {
                    throw new \Exception("Temporary image file could not be written.");
                }
                $image_path = $temp_image_path;
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $image_path = $_FILES['image']['tmp_name'];
            }
            try {
                $manager->create_entry($feed_id, $entry_data, $image_path);
            } finally {
                // Ensure temporary files are deleted.
                if (isset($temp_image_path) && file_exists($temp_image_path)) {
                    unlink($temp_image_path);
                }
            }
            echo json_encode(['success' => true]);
            break;
        case 'PUT':
            // Update an entry – PUT data is read from the input stream.
            parse_str(file_get_contents("php://input"), $put_vars);
            if (!isset($put_vars['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'No entry ID provided for update.']);
                exit;
            }
            $entry_id = $put_vars['id'];
            unset($put_vars['id']);
            $image_path = null;
            if (isset($put_vars['image_blob'])) {
                $image_blob = $put_vars['image_blob'];
                $image_data = base64_decode($image_blob);
                if ($image_data === false) {
                    throw new \Exception("Invalid Base64 data for image.");
                }
                $temp_image_path = tempnam($temp_dir, 'img_');
                if (file_put_contents($temp_image_path, $image_data) === false) {
                    throw new \Exception("Temporary image file could not be written.");
                }
                $image_path = $temp_image_path;
                // Remove the image_blob key so it is not processed as a regular field.
                unset($put_vars['image_blob']);
            }
            try {
                $manager->update_entry($feed_id, $entry_id, $put_vars, $image_path);
            } finally {
                if (isset($temp_image_path) && file_exists($temp_image_path)) {
                    unlink($temp_image_path);
                }
            }
            echo json_encode(['success' => true]);
            break;
        case 'DELETE':
            // Delete an entry: The entry ID is provided via GET.
            if (isset($_GET['id'])) {
                $manager->delete_entry($feed_id, $_GET['id']);
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'No entry ID provided for deletion.']);
            }
            break;
        case 'OPTIONS':
            // For CORS pre-flight requests.
            http_response_code(200);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed.']);
            break;
    }
} catch (\Exception $e) {
    log_error("API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error.']);
}
?>
