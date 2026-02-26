<?php
// require_once '/var/www/html/autodialer/includes/ESLClient.php'; // REMOVED THIS LINE - CONFLICT
require_once '/var/www/html/autodialer/includes/db.php';

$logfile = '/var/log/fs_monitor_debug.log';

function log_debug($msg) {
    global $logfile;
    file_put_contents($logfile, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

// Check if running
$pid_file = '/var/run/fs_monitor.pid';
if (file_exists($pid_file)) {
    $pid = @file_get_contents($pid_file);
    if ($pid && posix_kill($pid, 0)) {
        die("Monitor already running with PID $pid\n");
    }
}
file_put_contents($pid_file, getmypid());

// Use robust client
require_once '/var/www/html/autodialer/includes/ESLClient_Robust.php';
$esl = new ESLClient();

try {
    $esl->connect();
    log_debug("Connected to ESL");
} catch (Exception $e) {
    log_debug("ESL Connect Failed: " . $e->getMessage());
    die();
}

// Subscribe to JSON events
$esl->send("events json CHANNEL_HANGUP_COMPLETE");
log_debug("Subscribed to events");

while (true) {
    $event = $esl->recvEvent();
    
    if ($event) {
        $event_name = $event['Event-Name'] ?? 'Unknown';
        
        if ($event_name == 'CHANNEL_HANGUP_COMPLETE') {
            $uuid = $event['Unique-ID'] ?? '';
            
            // Try to get campaign ID from variables
            $camp_id = $event['variable_campaign_id'] ?? $event['variable_variable_campaign_id'] ?? 0;
            $num_id = $event['variable_number_id'] ?? $event['variable_variable_number_id'] ?? 0;
            
            if ($camp_id && $num_id) {
                log_debug("Processing HANGUP | UUID: $uuid | Camp: $camp_id | Num: $num_id");
                
                $start_time = urldecode($event['variable_start_stamp'] ?? '');
                $end_time = urldecode($event['variable_end_stamp'] ?? '');
                $duration = $event['variable_duration'] ?? 0;
                $billsec = $event['variable_billsec'] ?? 0;
                $hangup_cause = $event['Hangup-Cause'] ?? 'UNKNOWN';
                $caller_id = $event['Caller-Caller-ID-Number'] ?? '';
                $called_number = $event['Caller-Destination-Number'] ?? '';
                
                // Get Trunk ID
                $trunk_id = 0;
                // Retry logic for trunk ID fetch as well
                $max_retries = 10;
                $retry_count = 0;
                $got_trunk = false;
                
                while ($retry_count < $max_retries && !$got_trunk) {
                    try {
                        $camp_stmt = $db->query("SELECT trunk_id FROM campaigns WHERE id = $camp_id");
                        if ($camp_stmt) {
                            $camp_info = $camp_stmt->fetch();
                            $trunk_id = $camp_info['trunk_id'] ?? 0;
                        }
                        $got_trunk = true;
                    } catch (Exception $e) {
                         if (strpos($e->getMessage(), 'database is locked') !== false) {
                            $retry_count++;
                            usleep(200000);
                        } else {
                            break;
                        }
                    }
                }
                
                // Insert CDR
                // Retry logic for "database is locked"
                $retry_count = 0;
                $inserted = false;
                
                while ($retry_count < $max_retries && !$inserted) {
                    try {
                        $stmt = $db->prepare("INSERT INTO cdrs (
                            uuid, campaign_id, trunk_id, caller_id, called_number, 
                            start_time, end_time, duration, billsec, hangup_cause
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        
                        $stmt->execute([
                            $uuid, $camp_id, $trunk_id, $caller_id, $called_number,
                            $start_time, $end_time, $duration, $billsec, $hangup_cause
                        ]);
                        $inserted = true;
                        log_debug("CDR Inserted for $uuid");
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'database is locked') !== false) {
                            $retry_count++;
                            log_debug("DB Locked, retrying insert ($retry_count/$max_retries)...");
                            usleep(500000); // 500ms - Increased delay
                        } else {
                            log_debug("CDR Insert Error: " . $e->getMessage());
                            break; // Fatal error
                        }
                    }
                }
                
                // Update Campaign Numbers Status
                $new_status = ($billsec > 0) ? 'answered' : 'failed';
                
                $retry_count = 0;
                $updated = false;
                while ($retry_count < $max_retries && !$updated) {
                     try {
                        $upd = $db->prepare("UPDATE campaign_numbers SET status = ?, uuid = NULL WHERE id = ?");
                        $upd->execute([$new_status, $num_id]);
                        $updated = true;
                     } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'database is locked') !== false) {
                            $retry_count++;
                            usleep(500000); // 500ms - Increased delay
                        } else {
                            break;
                        }
                     }
                }
            }
        }
    } else {
        // Socket closed or timeout? Reconnect logic could go here
        sleep(1);
    }
}
?>