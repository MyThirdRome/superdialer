<?php
require_once 'includes/db.php';

// Create Users Table
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL
)");

// Create Default Admin
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $password = password_hash('admin', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password) VALUES ('admin', ?)");
    $stmt->execute([$password]);
}

// Create Trunks Table
$db->exec("CREATE TABLE IF NOT EXISTS trunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    ip TEXT NOT NULL,
    port INTEGER DEFAULT 5060,
    max_ports INTEGER DEFAULT 0,
    prefix TEXT
)");

// Create IVRs Table
$db->exec("CREATE TABLE IF NOT EXISTS ivrs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    filename TEXT NOT NULL
)");

// Create Campaigns Table
$db->exec("CREATE TABLE IF NOT EXISTS campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    trunk_id INTEGER,
    app_type TEXT CHECK(app_type IN ('MC', 'CC')),
    ivr_ids TEXT, 
    duration INTEGER,
    prefix TEXT,
    caller_id TEXT,
    caller_id_file TEXT,
    destination_file TEXT,
    randomize_dest INTEGER DEFAULT 0,
    max_retry INTEGER DEFAULT 1,
    wait_time INTEGER DEFAULT 30,
    max_ports INTEGER DEFAULT 1,
    status TEXT DEFAULT 'pending', 
    progress INTEGER DEFAULT 0,
    total_numbers INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Create Campaign Numbers Table
$db->exec("CREATE TABLE IF NOT EXISTS campaign_numbers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER,
    number TEXT NOT NULL,
    status TEXT DEFAULT 'pending', -- pending, dialed, answered, failed
    retries INTEGER DEFAULT 0,
    called_at DATETIME,
    FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
)");

// Create CDRs Table
$db->exec("CREATE TABLE IF NOT EXISTS cdrs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT UNIQUE,
    caller_id TEXT,
    called_number TEXT,
    start_time DATETIME,
    answer_time DATETIME,
    end_time DATETIME,
    duration INTEGER,
    billsec INTEGER,
    hangup_cause TEXT,
    trunk_id INTEGER,
    campaign_id INTEGER
)");

echo "Database installation complete.";
?>