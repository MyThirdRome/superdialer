<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $id = $_POST['id'];
        $action = $_POST['action'];
        
        if ($action == 'delete') {
            $db->exec("DELETE FROM campaigns WHERE id = $id");
            $db->exec("DELETE FROM campaign_numbers WHERE campaign_id = $id");
        } elseif ($action == 'pause') {
            $db->exec("UPDATE campaigns SET status = 'paused' WHERE id = $id");
        } elseif ($action == 'resume') {
            $db->exec("UPDATE campaigns SET status = 'running' WHERE id = $id");
        } elseif ($action == 'stop') {
             $db->exec("UPDATE campaigns SET status = 'stopped' WHERE id = $id");
        }
    }
}

// Fetch campaigns
$campaigns = $db->query("SELECT c.*, t.name as trunk_name FROM campaigns c LEFT JOIN trunks t ON c.trunk_id = t.id ORDER BY c.created_at DESC")->fetchAll();

// Dynamic Progress Calculation
foreach ($campaigns as &$camp) {
    $camp_id = $camp['id'];
    $total = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $camp_id")->fetchColumn();
    $processed = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $camp_id AND status != 'pending'")->fetchColumn();
    
    $camp['total_numbers'] = $total;
    $camp['progress'] = $processed;
}
unset($camp); // Break reference
?>

<h2>Campaigns</h2>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Trunk</th>
            <th>App</th>
            <th>Progress</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($campaigns as $camp): ?>
        <?php
            $percent = 0;
            if ($camp['total_numbers'] > 0) {
                $percent = round(($camp['progress'] / $camp['total_numbers']) * 100);
            }
        ?>
        <tr>
            <td><?php echo $camp['id']; ?></td>
            <td><?php echo htmlspecialchars($camp['name']); ?></td>
            <td><?php echo htmlspecialchars($camp['trunk_name']); ?></td>
            <td><?php echo $camp['app_type']; ?></td>
            <td id="progress-<?php echo $camp['id']; ?>">
                <div style="background-color: #333; width: 100px; height: 10px; margin-bottom: 5px;">
                    <div class="progress-bar" style="background-color: #0f0; width: <?php echo $percent; ?>%; height: 10px;"></div>
                </div>
                <span class="progress-text"><?php echo $percent; ?>% (<?php echo $camp['progress']; ?>/<?php echo $camp['total_numbers']; ?>)</span>
            </td>
            <td><?php echo strtoupper($camp['status']); ?></td>
            <td>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="id" value="<?php echo $camp['id']; ?>">
                    <a href="edit_campaign.php?id=<?php echo $camp['id']; ?>" class="button" style="text-decoration: none; font-size: 14px; padding: 2px 5px; background-color: #007bff; color: white; border: none; cursor: pointer;">Edit</a>
                    <?php if ($camp['status'] == 'running'): ?>
                        <button type="submit" name="action" value="pause">Pause</button>
                    <?php elseif ($camp['status'] == 'paused' || $camp['status'] == 'pending'): ?>
                        <button type="submit" name="action" value="resume">Start/Resume</button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="delete" onclick="return confirm('Delete this campaign?');" style="background-color: red;">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
function updateProgress() {
    $.getJSON('api.php?action=progress', function(data) {
        $.each(data, function(id, info) {
            var container = $('#progress-' + id);
            if (container.length) {
                container.find('.progress-bar').css('width', info.percent + '%');
                container.find('.progress-text').text(info.text);
            }
        });
    });
}

setInterval(updateProgress, 5000);
</script>

<?php require_once 'includes/footer.php'; ?>
