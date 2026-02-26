<?php
class ESLClient {
    private $host;
    private $port;
    private $password;
    private $socket;

    public function __construct($host = '127.0.0.1', $port = 8021, $password = 'ClueCon') {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
    }

    public function connect() {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new Exception("Could not connect: $errstr ($errno)");
        }
        
        // Read initial banner
        $this->read_response();

        // Auth
        fwrite($this->socket, "auth {$this->password}\n\n");
        $auth_resp = $this->read_response();
        
        if (strpos($auth_resp, "+OK accepted") === false) {
             // Maybe needs another read?
             $auth_resp .= $this->read_response();
             if (strpos($auth_resp, "+OK accepted") === false) {
                 throw new Exception("Auth Failed");
             }
        }
    }

    public function send($cmd) {
        fwrite($this->socket, "$cmd\n\n");
    }

    public function api($cmd) {
        fwrite($this->socket, "api $cmd\n\n");
        $resp = $this->read_response();
        // Body is after double newline
        $parts = preg_split('/(\r\n\r\n|\n\n)/', $resp, 2);
        return isset($parts[1]) ? trim($parts[1]) : "";
    }

    public function bgapi($cmd) {
        fwrite($this->socket, "bgapi $cmd\n\n");
        $resp = $this->read_response();
        // Usually returns Job-UUID immediately
        return $resp;
    }

    // Blocking read for next full event
    public function recvEvent() {
        if (!$this->socket) return false;
        
        // 1. Read Headers until blank line
        $headers = "";
        $contentLen = 0;
        
        while (($line = fgets($this->socket)) !== false) {
            $headers .= $line;
            if (trim($line) == "") {
                // End of headers
                break;
            }
            if (preg_match('/Content-Length: (\d+)/', $line, $matches)) {
                $contentLen = intval($matches[1]);
            }
        }
        
        if ($headers === "") return false; // Socket closed?

        // 2. Read Body
        $body = "";
        if ($contentLen > 0) {
            $left = $contentLen;
            while ($left > 0 && !feof($this->socket)) {
                $chunk = fread($this->socket, $left);
                $body .= $chunk;
                $left -= strlen($chunk);
            }
        }
        
        // 3. Return parsed event (array) or raw string?
        // Let's assume JSON mode was requested
        $event = json_decode($body, true);
        return $event;
    }

    private function read_response() {
        // Simple read until double newline (end of headers) + content-length body if present
        // But for command responses, usually just headers + body
        
        $response = "";
        $contentLen = 0;
        
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if (trim($line) == "") {
                if (preg_match('/Content-Length: (\d+)/', $response, $matches)) {
                    $contentLen = intval($matches[1]);
                    $response .= fread($this->socket, $contentLen);
                }
                break;
            }
        }
        return $response;
    }

    public function disconnect() {
        if ($this->socket) fclose($this->socket);
    }
}
?>