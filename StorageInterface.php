<?php
namespace ulrischa/rssfeedmanager;

interface StorageInterface {
    /**
     * Downloads a file from the remote storage to a local path.
     *
     * @param string $remote_path
     * @param string $local_path
     * @return bool
     */
    public function download_file(string $remote_path, string $local_path): bool;
    
    /**
     * Uploads a local file to the remote storage.
     *
     * @param string $local_path
     * @param string $remote_path
     * @return bool
     */
    public function upload_file(string $local_path, string $remote_path): bool;
    
    /**
     * Deletes a file from the remote storage.
     *
     * @param string $remote_path
     * @return bool
     */
    public function delete_file(string $remote_path): bool;
    
    /**
     * Creates a directory in the remote storage.
     *
     * @param string $directory
     * @return bool
     */
    public function create_directory(string $directory): bool;
}
?>
