<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo "<script>window.location='index.php';</script>";
    exit;
}

$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    
    // Get current user password hash
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($current_pass, $user['password'])) {
        if ($new_pass === $confirm_pass) {
            if (strlen($new_pass) < 4) {
                 $error = "Password too short (min 4 chars).";
            } else {
                $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $upd = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($upd->execute([$new_hash, $user_id])) {
                    $message = "Password updated successfully!";
                } else {
                    $error = "Database error.";
                }
            }
        } else {
            $error = "New passwords do not match.";
        }
    } else {
        $error = "Incorrect current password.";
    }
}
?>

<h2>Profile / Change Password</h2>

<div class="card" style="max-width: 500px; margin: 0 auto;">
    <?php if ($message): ?>
        <div style="background: #dff0d8; color: #3c763d; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="background: #f2dede; color: #a94442; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required style="width: 100%; box-sizing: border-box; padding: 8px;">
        </div>
        
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required style="width: 100%; box-sizing: border-box; padding: 8px;">
        </div>
        
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required style="width: 100%; box-sizing: border-box; padding: 8px;">
        </div>
        
        <button type="submit" style="width: 100%; margin-top: 15px; padding: 10px;">Update Password</button>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>
