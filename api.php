<?php
require_once 'includes/db.php';
require_once 'includes/ESLClient.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action == 'live') {
    // 1. Get Live Channels from FreeSWITCH
    $esl = new ESLClient();
    $channels = [];
    try {
        $esl->connect();
        $response = $esl->api("show channels as json");
        $json = json_decode($response, true);
        if (isset($json['rows'])) {
            $channels = $json['rows'];
        }
        $esl->disconnect();
    } catch (Exception $e) {
        // Fallback or error
    }
    
    // 2. Enrich with DB info
    $live_data = [];
    
    foreach ($channels as $chan) {
        $uuid = $chan['uuid'];
        $cid_num = $chan['cid_num']; // This is Caller ID
        $dest = $chan['dest'];
        $call_state = $chan['callstate'];
        $created_epoch = $chan['created_epoch'];
        
        // Check if this is a campaign call
        $db_call = $db->prepare("SELECT cn.id, c.name as campaign_name, t.name as trunk_name 
                                 FROM campaign_numbers cn 
                                 JOIN campaigns c ON cn.campaign_id = c.id 
                                 LEFT JOIN trunks t ON c.trunk_id = t.id 
                                 WHERE cn.uuid = ?");
        $db_call->execute([$uuid]);
        $info = $db_call->fetch();
        
        if ($info) {
            $duration = time() - $created_epoch;
            
            $live_data[] = [
                'id' => $info['id'],
                'campaign_name' => $info['campaign_name'],
                'trunk_name' => $info['trunk_name'] ?? 'Unknown',
                'caller_id' => $cid_num, // Added Caller ID
                'number' => $dest, 
                'status' => $call_state, 
                'duration' => $duration,
                'called_at' => date('Y-m-d H:i:s', $created_epoch),
                'uuid' => $uuid
            ];
        }
    }
    
    $total_live_calls = count($live_data);
    
    echo json_encode([
        'total_calls' => $total_live_calls,
        'calls' => $live_data
    ]);
} elseif ($action == 'progress') {
    // ... existing progress code ...
    $campaigns = $db->query("SELECT id, total_numbers, progress FROM campaigns")->fetchAll();
    $progress_data = [];
    foreach ($campaigns as $camp) {
        $camp_id = $camp['id'];
        $total = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $camp_id")->fetchColumn();
        $processed = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $camp_id AND status != 'pending'")->fetchColumn();
        
        $percent = 0;
        if ($total > 0) {
            $percent = round(($processed / $total) * 100);
        }
        
        $progress_data[$camp_id] = [
            'percent' => $percent,
            'text' => "$percent% ($processed/$total)"
        ];
    }
    echo json_encode($progress_data);
}
?>