<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

// Get counts
$trunks_count = $db->query("SELECT COUNT(*) FROM trunks")->fetchColumn();
$campaigns_count = $db->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();
$active_campaigns = $db->query("SELECT COUNT(*) FROM campaigns WHERE status = 'running'")->fetchColumn();
?>

<h2>Dashboard</h2>

<div style="display: flex; justify-content: space-between;">
    <div class="card" style="width: 30%;">
        <h3>Trunks</h3>
        <p style="font-size: 2em;"><?php echo $trunks_count; ?></p>
        <a href="trunks.php" class="btn">Manage</a>
    </div>
    <div class="card" style="width: 30%;">
        <h3>Campaigns</h3>
        <p style="font-size: 2em;"><?php echo $campaigns_count; ?></p>
        <p>Active: <?php echo $active_campaigns; ?></p>
        <a href="campaigns.php" class="btn">Manage</a>
    </div>
    <div class="card" style="width: 30%;">
        <h3>System</h3>
        <p>Status: ONLINE</p>
        <p>Date: <?php echo date("Y-m-d H:i"); ?></p>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
