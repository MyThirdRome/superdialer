<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

$id = $_GET['id'] ?? 0;
if (!$id) {
    echo "<p>Invalid ID</p>";
    require_once 'includes/footer.php';
    exit;
}

$camp = $db->query("SELECT * FROM campaigns WHERE id = $id")->fetch();
if (!$camp) {
    echo "<p>Campaign not found</p>";
    require_once 'includes/footer.php';
    exit;
}

// Ensure cid_mode exists in fetched array, default to 'manual' if null (though DB default handles new rows)
$cid_mode = $camp['cid_mode'] ?? 'manual';

$trunks = $db->query("SELECT * FROM trunks")->fetchAll();
$ivrs = $db->query("SELECT * FROM ivrs")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update'])) {
        $name = $_POST['name'];
        $trunk_id = $_POST['trunk_id'];
        $max_ports = $_POST['max_ports'];
        $wait_time = $_POST['wait_time'];
        
        $cid_mode_post = $_POST['cid_mode'];
        $caller_id = $_POST['caller_id'];
        
        $app_type = $_POST['app_type'];
        $ivr_ids = isset($_POST['ivr_ids']) ? implode(',', $_POST['ivr_ids']) : '';
        $duration = $_POST['duration'];
        
        // Handle Caller ID File Upload
        $caller_id_file = $camp['caller_id_file']; // Default to existing
        if (isset($_FILES['caller_id_file_upload']) && $_FILES['caller_id_file_upload']['error'] == 0) {
            $filename = "cid_" . time() . "_" . mt_rand() . ".txt";
            $target = "/var/www/html/autodialer/uploads/cids/" . $filename;
            if (!is_dir("/var/www/html/autodialer/uploads/cids/")) {
                mkdir("/var/www/html/autodialer/uploads/cids/", 0777, true);
            }
            if (move_uploaded_file($_FILES['caller_id_file_upload']['tmp_name'], $target)) {
                $caller_id_file = "uploads/cids/" . $filename;
            }
        }
        
        // If switching to manual, maybe we don't clear the file, just ignore it.
        // But the user wants an option to switch.
        
        $sql = "UPDATE campaigns SET name = ?, trunk_id = ?, max_ports = ?, wait_time = ?, caller_id = ?, app_type = ?, ivr_ids = ?, duration = ?, caller_id_file = ?, cid_mode = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$name, $trunk_id, $max_ports, $wait_time, $caller_id, $app_type, $ivr_ids, $duration, $caller_id_file, $cid_mode_post, $id]);
        
        echo "<p class='success'>Campaign updated! New settings will apply immediately.</p>";
        $camp = $db->query("SELECT * FROM campaigns WHERE id = $id")->fetch();
        $cid_mode = $camp['cid_mode']; // Refresh
        
    } elseif (isset($_POST['add_numbers'])) {
        // Upload File
        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
            $target_file = "/tmp/upload_" . time() . "_" . mt_rand() . ".txt";
            if (move_uploaded_file($_FILES['file']['tmp_name'], $target_file)) {
                 $cmd = "nohup /usr/bin/php /var/www/html/autodialer/import_numbers.php $id $target_file > /var/log/autodialer_import_nohup.log 2>&1 &";
                 exec($cmd);
                 echo "<p class='success'>File uploaded. Import started in background.</p>";
            } else {
                 echo "<p class='error'>Upload failed.</p>";
            }
        }
        
        // Generate
        if (!empty($_POST['gen_prefix']) && !empty($_POST['gen_count'])) {
            $prefix = $_POST['gen_prefix'];
            $count = (int)$_POST['gen_count'];
            $safe_prefix = escapeshellarg($prefix);
            $safe_count = escapeshellarg($count);
            $safe_id = escapeshellarg($id);
            $cmd = "nohup /usr/bin/php /var/www/html/autodialer/background_generator.php $safe_id $safe_prefix $safe_count > /var/log/autodialer_gen_nohup.log 2>&1 &";
            exec($cmd);
            echo "<p class='success'>Generation started in background ($count numbers).</p>";
        }
    }
}

$selected_ivrs = explode(',', $camp['ivr_ids']);
?>

<h2>Edit Campaign: <?php echo htmlspecialchars($camp['name']); ?></h2>

<div style="display: flex; gap: 20px; flex-wrap: wrap;">
    <div class="card" style="flex: 1; min-width: 300px;">
        <h3>Configuration</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($camp['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Trunk</label>
                <select name="trunk_id">
                    <?php foreach ($trunks as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php if ($camp['trunk_id'] == $t['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($t['name']); ?> (<?php echo htmlspecialchars($t['ip']); ?>) - Max: <?php echo $t['max_ports'] == 0 ? 'UL' : $t['max_ports']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Caller ID Mode</label>
                <select name="cid_mode" onchange="toggleCidFields(this.value)">
                    <option value="manual" <?php if ($cid_mode == 'manual') echo 'selected'; ?>>Manual (Single Number)</option>
                    <option value="file" <?php if ($cid_mode == 'file') echo 'selected'; ?>>File (Random Rotation)</option>
                </select>
            </div>
            
            <div id="cid_manual_group" class="form-group" style="<?php echo $cid_mode == 'manual' ? '' : 'display:none;'; ?>">
                <label>Caller ID (Number)</label>
                <input type="text" name="caller_id" value="<?php echo htmlspecialchars($camp['caller_id']); ?>" placeholder="Single Caller ID">
            </div>

            <div id="cid_file_group" class="form-group" style="<?php echo $cid_mode == 'file' ? '' : 'display:none;'; ?>">
                <label>Caller ID File</label>
                <?php if (!empty($camp['caller_id_file'])): ?>
                    <p>Current file: <?php echo htmlspecialchars(basename($camp['caller_id_file'])); ?></p>
                <?php endif; ?>
                <input type="file" name="caller_id_file_upload">
                <small>Upload TXT file with one Caller ID per line</small>
            </div>

            <div class="form-group">
                <label>Application Type</label>
                <select name="app_type" id="app_type" onchange="toggleAppFields()">
                    <option value="CC" <?php if ($camp['app_type'] == 'CC') echo 'selected'; ?>>CC (Call Center / IVR)</option>
                    <option value="MC" <?php if ($camp['app_type'] == 'MC') echo 'selected'; ?>>MC (Missed Call)</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <div class="form-group" style="flex:1">
                    <label>Max Ports (Simultaneous)</label>
                    <input type="number" name="max_ports" value="<?php echo $camp['max_ports']; ?>" required>
                </div>
                <div class="form-group" style="flex:1">
                    <label>Wait Time (sec)</label>
                    <input type="number" name="wait_time" value="<?php echo $camp['wait_time']; ?>" required>
                </div>
            </div>
            
            <div id="cc_fields" style="<?php echo $camp['app_type'] == 'CC' ? '' : 'display:none;'; ?>">
                <div class="form-group">
                    <label>IVRs (Hold Ctrl/Cmd to select multiple)</label>
                    <select name="ivr_ids[]" multiple style="height: 100px;">
                        <?php foreach ($ivrs as $ivr): ?>
                            <option value="<?php echo $ivr['id']; ?>" <?php if (in_array($ivr['id'], $selected_ivrs)) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($ivr['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Duration (Seconds to play IVR)</label>
                    <input type="number" name="duration" value="<?php echo $camp['duration']; ?>">
                </div>
            </div>
            
            <button type="submit" name="update">Save Changes</button>
        </form>
    </div>
    
    <div class="card" style="flex: 1; min-width: 300px;">
        <h3>Add More Numbers</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Upload File (TXT, one per line)</label>
                <input type="file" name="file">
            </div>
            
            <hr>
            <p>OR Generate</p>
            
            <div class="form-group">
                <label>Pattern (e.g. 96655######)</label>
                <input type="text" name="gen_prefix" placeholder="96655######">
            </div>
            <div class="form-group">
                <label>Count</label>
                <input type="number" name="gen_count" value="100">
            </div>
            
            <button type="submit" name="add_numbers">Add Numbers</button>
        </form>
    </div>
</div>

<script>
function toggleAppFields() {
    var appType = document.getElementById('app_type').value;
    var ccFields = document.getElementById('cc_fields');
    if (appType === 'CC') {
        ccFields.style.display = 'block';
    } else {
        ccFields.style.display = 'none';
    }
}

function toggleCidFields(mode) {
    if (mode === 'manual') {
        document.getElementById('cid_manual_group').style.display = 'block';
        document.getElementById('cid_file_group').style.display = 'none';
    } else {
        document.getElementById('cid_manual_group').style.display = 'none';
        document.getElementById('cid_file_group').style.display = 'block';
    }
}
</script>

<p><a href="campaigns.php">Back to Campaigns</a></p>

<?php require_once 'includes/footer.php'; ?>
