<?php
require_once 'includes/ESLClient.php';

try {
    $esl = new ESLClient();
    $esl->connect();
    $response = $esl->api("show channels as json");
    echo "Connection Successful.\n";
    echo "Response: " . substr($response, 0, 100) . "...\n";
    $json = json_decode($response, true);
    echo "JSON Decode: " . (json_last_error() === JSON_ERROR_NONE ? "OK" : "Error") . "\n";
    echo "Row Count: " . ($json['row_count'] ?? 'N/A') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>