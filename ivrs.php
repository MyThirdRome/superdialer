<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['upload'])) {
        $name = $_POST['name'];
        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
            $filename = $_FILES['file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $allowed = ['wav', 'mp3'];
            
            if (in_array($ext, $allowed)) {
                $new_filename = uniqid() . '.' . $ext;
                $target_dir = 'uploads/ivrs/';
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                
                if (move_uploaded_file($_FILES['file']['tmp_name'], $target_dir . $new_filename)) {
                    $stmt = $db->prepare("INSERT INTO ivrs (name, filename) VALUES (?, ?)");
                    $stmt->execute([$name, $new_filename]);
                    echo "<p class='success'>IVR Uploaded Successfully</p>";
                } else {
                    echo "<p class='error'>Failed to move uploaded file.</p>";
                }
            } else {
                echo "<p class='error'>Invalid file type. Only WAV and MP3 allowed.</p>";
            }
        }
    } elseif (isset($_POST['delete'])) {
        $id = $_POST['delete_id'];
        $stmt = $db->prepare("SELECT filename FROM ivrs WHERE id = ?");
        $stmt->execute([$id]);
        $filename = $stmt->fetchColumn();
        
        if ($filename && file_exists('uploads/ivrs/' . $filename)) {
            unlink('uploads/ivrs/' . $filename);
        }
        
        $stmt = $db->prepare("DELETE FROM ivrs WHERE id = ?");
        $stmt->execute([$id]);
        echo "<p class='success'>IVR Deleted.</p>";
    }
}

$ivrs = $db->query("SELECT * FROM ivrs")->fetchAll();
?>

<h2>Manage IVRs</h2>

<div class="card">
    <h3>Upload IVR</h3>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" required>
        </div>
        <div class="form-group">
            <label>File (WAV/MP3)</label>
            <input type="file" name="file" required accept=".wav,.mp3">
        </div>
        <button type="submit" name="upload">Upload</button>
    </form>
</div>

<h3>IVR List</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Filename</th>
            <th>Play</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ivrs as $ivr): ?>
        <tr>
            <td><?php echo $ivr['id']; ?></td>
            <td><?php echo htmlspecialchars($ivr['name']); ?></td>
            <td><?php echo htmlspecialchars($ivr['filename']); ?></td>
            <td>
                <?php 
                $file_path = 'uploads/ivrs/' . $ivr['filename'];
                if (file_exists($file_path)): 
                ?>
                <audio controls style="height: 30px; width: 200px;">
                    <source src="<?php echo $file_path; ?>" type="audio/<?php echo pathinfo($ivr['filename'], PATHINFO_EXTENSION) == 'mp3' ? 'mpeg' : 'wav'; ?>">
                    Your browser does not support the audio element.
                </audio>
                <?php else: ?>
                <span style="color: red;">File Missing</span>
                <?php endif; ?>
            </td>
            <td>
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this IVR?');" style="display:inline;">
                    <input type="hidden" name="delete_id" value="<?php echo $ivr['id']; ?>">
                    <button type="submit" name="delete" style="background-color: #d9534f; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once 'includes/footer.php'; ?>
