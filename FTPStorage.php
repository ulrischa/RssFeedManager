<?php
namespace ulrischa/rssfeedmanager;

class FTPStorage implements StorageInterface {
    private $conn;
    private $timeout = 90; // Timeout in seconds

    /**
     * Constructor – establishes the FTP connection.
     *
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param bool $use_tls Enable explicit TLS/SSL if true (default false)
     * @throws \Exception on connection or login failure
     */
    public function __construct(string $host, string $user, string $pass, bool $use_tls = false) {
        if ($use_tls) {
            $this->conn = ftp_ssl_connect($host);
        } else {
            $this->conn = ftp_connect($host);
        }
        if (!$this->conn) {
            throw new \Exception("Could not establish FTP connection.");
        }
        ftp_set_option($this->conn, FTP_TIMEOUT_SEC, $this->timeout);
        if (!ftp_login($this->conn, $user, $pass)) {
            throw new \Exception("FTP login failed.");
        }
        ftp_pasv($this->conn, true);
    }
    
    public function download_file(string $remote_path, string $local_path): bool {
        return @ftp_get($this->conn, $local_path, $remote_path, FTP_BINARY);
    }
    
    public function upload_file(string $local_path, string $remote_path): bool {
        return @ftp_put($this->conn, $remote_path, $local_path, FTP_BINARY);
    }
    
    public function delete_file(string $remote_path): bool {
        return @ftp_delete($this->conn, $remote_path);
    }
    
    public function create_directory(string $directory): bool {
        return @ftp_mkdir($this->conn, $directory) !== false;
    }
    
    public function __destruct() {
        if ($this->conn) {
            ftp_close($this->conn);
        }
    }
}
?>
