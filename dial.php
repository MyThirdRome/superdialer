<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

$trunks = $db->query("SELECT * FROM trunks")->fetchAll();
$ivrs = $db->query("SELECT * FROM ivrs")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $trunk_id = $_POST['trunk_id'];
    $app_type = $_POST['app_type'];
    $duration = isset($_POST['duration']) ? $_POST['duration'] : 0;
    $prefix = $_POST['prefix'];
    $caller_id_type = $_POST['caller_id_type'];
    $dest_type = $_POST['dest_type'];
    $max_retry = $_POST['max_retry'];
    $wait_time = $_POST['wait_time'];
    $max_ports = $_POST['max_ports'];
    $randomize = isset($_POST['randomize']) ? 1 : 0;
    
    // IVRs
    $ivr_ids = '';
    if (isset($_POST['ivr_ids']) && is_array($_POST['ivr_ids'])) {
        $ivr_ids = implode(',', $_POST['ivr_ids']);
    }

    // Handle Caller ID
    $caller_id_val = '';
    $caller_id_file = '';
    
    if ($caller_id_type == 'manual') {
        $caller_id_val = $_POST['caller_id_manual'];
    } elseif ($caller_id_type == 'file') {
        if (isset($_FILES['caller_id_file']) && $_FILES['caller_id_file']['error'] == 0) {
            $filename = uniqid() . '_cid.txt';
            if (!is_dir('uploads/cids')) {
                mkdir('uploads/cids', 0777, true);
            }
            move_uploaded_file($_FILES['caller_id_file']['tmp_name'], 'uploads/cids/' . $filename);
            $caller_id_file = 'uploads/cids/' . $filename;
        }
    }

    // Insert Campaign
    $stmt = $db->prepare("INSERT INTO campaigns (name, trunk_id, app_type, ivr_ids, duration, prefix, caller_id, caller_id_file, randomize_dest, max_retry, wait_time, max_ports, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))");
    $stmt->execute([$name, $trunk_id, $app_type, $ivr_ids, $duration, $prefix, $caller_id_val, $caller_id_file, $randomize, $max_retry, $wait_time, $max_ports]);
    $campaign_id = $db->lastInsertId();

    // Handle Destinations
    $numbers = [];
    if ($dest_type == 'file') {
        if (isset($_FILES['dest_file']) && $_FILES['dest_file']['error'] == 0) {
            $lines = file($_FILES['dest_file']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $num = trim($line);
                $num = preg_replace('/[^0-9]/', '', $num); // Sanitize
                if (!empty($num)) {
                    $numbers[] = $num;
                }
            }
        }
    } elseif ($dest_type == 'generate') {
        $patterns = $_POST['gen_pattern'];
        $counts = $_POST['gen_count'];
        
        for ($i = 0; $i < count($patterns); $i++) {
            $pat = $patterns[$i];
            $cnt = intval($counts[$i]);
            
            for ($j = 0; $j < $cnt; $j++) {
                $num = '';
                for ($k = 0; $k < strlen($pat); $k++) {
                    if ($pat[$k] == '#') {
                        $num .= mt_rand(0, 9);
                    } else {
                        $num .= $pat[$k];
                    }
                }
                $numbers[] = $num;
            }
        }
    }

    // Randomize if requested
    if ($randomize) {
        shuffle($numbers);
    }

    // Insert numbers
    // Use transaction for speed and INSERT OR IGNORE to handle duplicates
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_numbers (campaign_id, number) VALUES (?, ?)");
    foreach ($numbers as $num) {
        $stmt->execute([$campaign_id, $num]);
    }
    $db->commit();
    
    // Update total count from DB to be accurate (excluding duplicates)
    $total = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $campaign_id")->fetchColumn();
    $db->exec("UPDATE campaigns SET total_numbers = $total, status = 'running' WHERE id = $campaign_id");
    
    echo "<script>alert('Campaign Started with $total numbers'); window.location='campaigns.php';</script>";
}
?>

<h2>Create Campaign</h2>

<form method="POST" enctype="multipart/form-data" class="card">
    <div class="form-group">
        <label>Campaign Name</label>
        <input type="text" name="name" required>
    </div>

    <div class="form-group">
        <label>Trunk</label>
        <select name="trunk_id" required>
            <?php foreach ($trunks as $trunk): ?>
                <option value="<?php echo $trunk['id']; ?>"><?php echo htmlspecialchars($trunk['name']) . " (" . htmlspecialchars($trunk['ip']) . ")"; ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label>App Type</label>
        <label><input type="radio" name="app_type" value="MC" checked onclick="$('#cc_options').hide();"> MC (Hangup)</label>
        <label><input type="radio" name="app_type" value="CC" onclick="$('#cc_options').show();"> CC (Play IVR)</label>
    </div>

    <div id="cc_options" style="display: none; border: 1px dashed #0f0; padding: 10px; margin-bottom: 10px;">
        <div class="form-group">
            <label>IVRs (Select Multiple)</label>
            <select name="ivr_ids[]" multiple style="height: 100px;">
                <?php foreach ($ivrs as $ivr): ?>
                    <option value="<?php echo $ivr['id']; ?>"><?php echo htmlspecialchars($ivr['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Duration (Seconds)</label>
            <input type="number" name="duration" value="10">
        </div>
    </div>

    <div class="form-group">
        <label>Prefix (Optional)</label>
        <input type="text" name="prefix">
    </div>

    <div class="form-group">
        <label>Caller ID</label>
        <select name="caller_id_type" onchange="if(this.value=='manual'){$('#cid_manual').show();$('#cid_file').hide();}else{$('#cid_manual').hide();$('#cid_file').show();}">
            <option value="manual">Manual Input</option>
            <option value="file">Upload File</option>
        </select>
        <input type="text" name="caller_id_manual" id="cid_manual" placeholder="Enter Caller ID">
        <input type="file" name="caller_id_file" id="cid_file" style="display: none;">
    </div>

    <div class="form-group">
        <label>Destination Numbers</label>
        <select name="dest_type" onchange="if(this.value=='file'){$('#dest_file').show();$('#dest_gen').hide();}else{$('#dest_file').hide();$('#dest_gen').show();}">
            <option value="file">Upload File</option>
            <option value="generate">Generate</option>
        </select>
        
        <div id="dest_file">
            <input type="file" name="dest_file">
        </div>
        
        <div id="dest_gen" style="display: none;">
            <div id="gen_rows">
                <div class="gen-row">
                    <input type="text" name="gen_pattern[]" placeholder="Pattern e.g. 96655######">
                    <input type="number" name="gen_count[]" placeholder="Count e.g. 1000">
                </div>
            </div>
            <button type="button" onclick="$('#gen_rows').append('<div class=\'gen-row\'><input type=\'text\' name=\'gen_pattern[]\' placeholder=\'Pattern\'><input type=\'number\' name=\'gen_count[]\' placeholder=\'Count\'></div>');">Add More Generators</button>
        </div>
    </div>

    <div class="form-group">
        <label><input type="checkbox" name="randomize"> Randomize Order (Mix)</label>
    </div>

    <div class="form-group">
        <label>Max Retry</label>
        <input type="number" name="max_retry" value="1">
    </div>

    <div class="form-group">
        <label>Wait Time (Seconds)</label>
        <input type="number" name="wait_time" value="30">
    </div>

    <div class="form-group">
        <label>Max Ports (Simultaneous Calls)</label>
        <input type="number" name="max_ports" value="1" min="1">
    </div>

    <button type="submit">SEND (Start Campaign)</button>
</form>

<?php require_once 'includes/footer.php'; ?>
