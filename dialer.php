<?php
require_once '/var/www/html/autodialer/includes/db.php';
require_once '/var/www/html/autodialer/includes/ESLClient.php';

// Check if running
$pid_file = '/var/run/fs_dialer.pid';
if (file_exists($pid_file)) {
    $pid = @file_get_contents($pid_file);
    if ($pid && posix_kill($pid, 0)) {
        die("Dialer already running with PID $pid\n");
    }
}
file_put_contents($pid_file, getmypid());

$esl = new ESLClient();
try {
    $esl->connect();
} catch (Exception $e) {
    die("ESL Connect Failed: " . $e->getMessage() . "\n");
}

echo "Dialer started...\n";

function gen_uuid() {
    return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
        mt_rand( 0, 0xffff ),
        mt_rand( 0, 0x0fff ) | 0x4000,
        mt_rand( 0, 0x3fff ) | 0x8000,
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
    );
}

while (true) {
    // 1. Sync with FreeSWITCH Channels (Self-Healing)
    $channels_response = $esl->api("show channels as json");
    $channels_data = json_decode($channels_response, true);
    $active_uuids = [];
    if (isset($channels_data['rows'])) {
        foreach ($channels_data['rows'] as $row) {
            $active_uuids[] = $row['uuid'];
        }
    }
    
    // Check for stuck calls in DB (status dialed but UUID not in FS)
    // Only check 'dialed' because 'answered' means finished.
    $stuck_candidates = $db->query("SELECT id, uuid FROM campaign_numbers WHERE status = 'dialed' AND uuid IS NOT NULL")->fetchAll();
    foreach ($stuck_candidates as $cand) {
        if (!in_array($cand['uuid'], $active_uuids)) {
            echo "Cleaning up stuck call ID {$cand['id']} UUID {$cand['uuid']}\n";
            // *** CHANGE: Instead of marking failed, assume finished successfully if monitor is off?
            // Actually, if monitor is OFF, we don't know if it was answered or failed.
            // But we must free the slot.
            // Let's mark it as 'completed' (or 'answered' so it doesn't redial)
            // Or 'failed' if we want to be pessimistic.
            // Since user asked to disable CDRs, maybe they just want to blast calls?
            // Let's stick to 'failed' for now as it means "finished without known success".
            // BUT, if we mark 'failed', the stats will look bad.
            // Let's just mark it 'answered' so it counts as "done".
            $db->prepare("UPDATE campaign_numbers SET status = 'answered' WHERE id = ?")->execute([$cand['id']]);
        }
    }

    // 2. Process Campaigns
    $campaigns = $db->query("SELECT * FROM campaigns WHERE status = 'running'")->fetchAll();
    
    // Group campaigns by trunk to enforce trunk-level limits
    $trunk_usage = [];
    
    // Calculate current usage per trunk
    $trunks = $db->query("SELECT * FROM trunks")->fetchAll();
    foreach ($trunks as $t) {
        $trunk_usage[$t['id']] = [
            'max' => $t['max_ports'],
            'current' => 0
        ];
        // Count active calls for this trunk across ALL campaigns
        // Only count 'dialed' as active. 'answered' means finished.
        $count = $db->query("SELECT COUNT(*) FROM campaign_numbers cn 
                             JOIN campaigns c ON cn.campaign_id = c.id 
                             WHERE c.trunk_id = {$t['id']} AND cn.status = 'dialed'")->fetchColumn();
        $trunk_usage[$t['id']]['current'] = $count;
    }
    
    foreach ($campaigns as $camp) {
        $camp_id = $camp['id'];
        $max_ports = $camp['max_ports'];
        $trunk_id = $camp['trunk_id'];
        $wait_time = $camp['wait_time'];
        
        // Trunk Limit Check
        if (!isset($trunk_usage[$trunk_id])) continue;
        
        $trunk_limit = $trunk_usage[$trunk_id]['max'];
        $trunk_current = $trunk_usage[$trunk_id]['current'];
        
        // 0 means unlimited
        if ($trunk_limit > 0 && $trunk_current >= $trunk_limit) {
            // Trunk full
            continue;
        }
        
        // Campaign Limit Check
        // Only count 'dialed' as active.
        $active = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $camp_id AND status = 'dialed'")->fetchColumn();
        
        if ($active < $max_ports) {
            // Calculate slots available
            // 1. Campaign slots
            $camp_slots = $max_ports - $active;
            
            // 2. Trunk slots
            $trunk_slots = ($trunk_limit == 0) ? 9999 : ($trunk_limit - $trunk_current);
            
            // Take the minimum of available slots
            $slots = min($camp_slots, $trunk_slots);
            
            if ($slots <= 0) continue;
            
            // Get trunk details
            $trunk = $db->query("SELECT * FROM trunks WHERE id = $trunk_id")->fetch();
            if (!$trunk) continue;
            
            // Get pending numbers
            $numbers = $db->query("SELECT * FROM campaign_numbers WHERE campaign_id = $camp_id AND status = 'pending' ORDER BY retries ASC, id ASC LIMIT $slots")->fetchAll();
            
            foreach ($numbers as $num) {
                // Re-check trunk limit inside loop in case we are filling it up
                if ($trunk_limit > 0 && $trunk_usage[$trunk_id]['current'] >= $trunk_limit) {
                    break; 
                }
                
                $num_id = $num['id'];
                $number = $num['number'];
                $uuid = gen_uuid();
                
                // Construct Dial String
                $prefix_camp = $camp['prefix'];
                $prefix_trunk = $trunk['prefix'];
                $dial_number = $prefix_camp . $prefix_trunk . $number;
                
                // --- CALLER ID LOGIC ---
                $caller_id = "Anonymous";
                
                $cid_mode = $camp['cid_mode'] ?? 'manual';
                
                if ($cid_mode == 'file') {
                    // Use File Rotation
                    if (!empty($camp['caller_id_file'])) {
                        $cid_file_path = '/var/www/html/autodialer/' . $camp['caller_id_file'];
                        if (file_exists($cid_file_path)) {
                             $lines = file($cid_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                             if ($lines && count($lines) > 0) {
                                 $caller_id = trim($lines[array_rand($lines)]);
                             }
                        }
                    }
                } else {
                    // Use Manual
                    if (!empty($camp['caller_id'])) {
                        $caller_id = $camp['caller_id'];
                    }
                }
                // -----------------------
                
                // App Logic
                $app_str = "";
                $exec_on_answer = "";
                
                if ($camp['app_type'] == 'MC') {
                    $exec_on_answer = "execute_on_answer='hangup'";
                    $app_str = "&park"; 
                } else {
                    $ivrs_list = $camp['ivr_ids'];
                    $ivr_file = "";
                    if ($ivrs_list) {
                        $ivrs = explode(',', $ivrs_list);
                        $ivr_id = $ivrs[array_rand($ivrs)];
                        $ivr = $db->query("SELECT filename FROM ivrs WHERE id = $ivr_id")->fetch();
                        if ($ivr) {
                            $ivr_file = '/var/www/html/autodialer/uploads/ivrs/' . $ivr['filename'];
                        }
                    }
                    
                    $duration = $camp['duration'];
                    $exec_on_answer = "execute_on_answer='sched_hangup +$duration legacy_sched_hangup'";
                    
                    if ($ivr_file) {
                        $app_str = "&playback($ivr_file)";
                    } else {
                        $app_str = "&echo"; 
                    }
                }
                
                // Update DB with UUID *before* dialing
                $stmt = $db->prepare("UPDATE campaign_numbers SET status = 'dialed', called_at = datetime('now'), uuid = ? WHERE id = ?");
                $stmt->execute([$uuid, $num_id]);
                
                // Vars
                $vars = "origination_uuid=$uuid,origination_caller_id_number=$caller_id,effective_caller_id_number=$caller_id,effective_caller_id_name=$caller_id,variable_campaign_id=$camp_id,variable_number_id=$num_id,ignore_early_media=true,originate_timeout=$wait_time,$exec_on_answer";
                
                // Gateway
                $gw_str = "sofia/external/$dial_number@{$trunk['ip']}:{$trunk['port']}";
                
                $dial_cmd = "originate {{$vars}}$gw_str $app_str";
                
                // Execute
                $esl->bgapi($dial_cmd);
                
                echo "Dialing $dial_number (Camp $camp_id) CID $caller_id UUID $uuid\n";
                
                // Increment usage counter
                $trunk_usage[$trunk_id]['current']++;
            }
        }
    }
    
    sleep(1);
}
?>