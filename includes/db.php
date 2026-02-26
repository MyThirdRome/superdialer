<?php
try {
    $db_path = '/var/www/db/autodialer.sqlite';
    if (!file_exists(dirname($db_path))) {
        mkdir(dirname($db_path), 0777, true);
    }
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>