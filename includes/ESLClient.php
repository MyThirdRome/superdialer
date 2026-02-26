<?php
class ESLClient {
    private $host;
    private $port;
    private $password;
    private $socket;
    private $authenticated = false;

    public function __construct($host = '127.0.0.1', $port = 8021, $password = 'ClueCon') {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
    }

    public function connect() {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new Exception("Could not connect to FreeSwitch Event Socket: $errstr ($errno)");
        }

        // Read the initial banner
        $this->read(); 

        // Authenticate
        $authCmd = "auth " . $this->password . "\n\n";
        fwrite($this->socket, $authCmd);
        
        $response = $this->read();
        if (strpos($response, "+OK accepted") === false) {
             // Try reading one more time just in case
             $response .= $this->read();
             if (strpos($response, "+OK accepted") === false) {
                throw new Exception("Authentication failed: " . $response);
             }
        }
        $this->authenticated = true;
    }

    public function send($cmd) {
        if (!$this->socket) return false;
        
        fwrite($this->socket, $cmd . "\n\n");
        // For 'events', we typically don't read immediately if we want to stream
        // But for commands we do.
        if (strpos($cmd, "events") === 0) {
            return $this->read(); // Read the command response (+OK)
        }
        return $this->read();
    }

    public function bgapi($cmd) {
        $response = $this->send("bgapi " . $cmd);
        return $this->parseBody($response);
    }

    public function api($cmd) {
        $response = $this->send("api " . $cmd);
        return $this->parseBody($response);
    }
    
    private function parseBody($response) {
        $parts = preg_split('/(\r\n\r\n|\n\n)/', $response, 2);
        return isset($parts[1]) ? trim($parts[1]) : trim($response);
    }
    
    // Improved read for event streaming
    public function read() {
        if (!$this->socket) return false;
        
        $response = "";
        $contentLength = 0;
        
        while ($line = fgets($this->socket)) {
            $response .= $line;
            if (trim($line) == "") {
                // End of headers
                if (preg_match('/Content-Length: (\d+)/', $response, $matches)) {
                    $contentLength = intval($matches[1]);
                    $response .= fread($this->socket, $contentLength);
                }
                break;
            }
        }
        
        return $response;
    }

    public function disconnect() {
        if ($this->socket) {
            fwrite($this->socket, "exit\n\n");
            fclose($this->socket);
            $this->socket = null;
            $this->authenticated = false;
        }
    }
}
?>