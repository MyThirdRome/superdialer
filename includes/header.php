<?php
session_start();
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'index.php') {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FS Autodialer</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/jquery.min.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>FS Autodialer <span style="font-size: 0.5em;">v1.0</span></h1>
            <?php if (isset($_SESSION['user_id'])): ?>
            <nav>
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="trunks.php">Trunks</a></li>
                    <li><a href="ivrs.php">IVRs</a></li>
                    <li><a href="dial.php">Dial/New Campaign</a></li>
                    <li><a href="campaigns.php">Campaigns</a></li>
                    <li><a href="live.php">Live Calls</a></li>
                    <li><a href="cdr.php">CDR</a></li>
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
            <?php endif; ?>
        </header>
        <div class="content">
