<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add'])) {
        $name = $_POST['name'];
        $ip = $_POST['ip'];
        $port = $_POST['port'] ?: 5060;
        $max_ports = $_POST['max_ports'] ?: 0;
        $prefix = $_POST['prefix'];

        $stmt = $db->prepare("INSERT INTO trunks (name, ip, port, max_ports, prefix) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ip, $port, $max_ports, $prefix]);
        echo "<p class='success'>Trunk Added</p>";
        
    } elseif (isset($_POST['update'])) {
        $id = $_POST['id'];
        $name = $_POST['name'];
        $ip = $_POST['ip'];
        $port = $_POST['port'];
        $max_ports = $_POST['max_ports'];
        $prefix = $_POST['prefix'];
        
        // Check for running campaigns
        $active_camps = $db->query("SELECT COUNT(*) FROM campaigns WHERE trunk_id = $id AND status = 'running'")->fetchColumn();
        
        if ($active_camps > 0) {
            echo "<p class='error'>Cannot edit trunk while it is being used by running campaigns. Please pause associated campaigns first.</p>";
        } else {
            $stmt = $db->prepare("UPDATE trunks SET name = ?, ip = ?, port = ?, max_ports = ?, prefix = ? WHERE id = ?");
            $stmt->execute([$name, $ip, $port, $max_ports, $prefix, $id]);
            echo "<p class='success'>Trunk Updated</p>";
        }
        
    } elseif (isset($_POST['delete'])) {
        $id = $_POST['id'];
        $active_camps = $db->query("SELECT COUNT(*) FROM campaigns WHERE trunk_id = $id AND status = 'running'")->fetchColumn();
        if ($active_camps > 0) {
             echo "<p class='error'>Cannot delete trunk while it is being used by running campaigns.</p>";
        } else {
            $stmt = $db->prepare("DELETE FROM trunks WHERE id = ?");
            $stmt->execute([$id]);
            echo "<p class='success'>Trunk Deleted</p>";
        }
    }
}

$trunks = $db->query("SELECT * FROM trunks")->fetchAll();
$edit_id = isset($_GET['edit']) ? $_GET['edit'] : 0;
?>

<h2>Manage Trunks</h2>

<div class="card">
    <h3>Add Trunk</h3>
    <form method="POST">
        <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>IP</label>
                <input type="text" name="ip" required>
            </div>
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="port" value="5060">
            </div>
            <div class="form-group">
                <label>Max Ports (0=UL)</label>
                <input type="number" name="max_ports" value="0">
            </div>
            <div class="form-group">
                <label>Prefix</label>
                <input type="text" name="prefix">
            </div>
            <div class="form-group">
                <button type="submit" name="add">Save</button>
            </div>
        </div>
    </form>
</div>

<h3>Trunk List</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>IP</th>
            <th>Port</th>
            <th>Max Ports</th>
            <th>Prefix</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($trunks as $trunk): ?>
        <tr>
            <?php if ($edit_id == $trunk['id']): ?>
                <!-- Edit Mode -->
                <form method="POST">
                    <td><?php echo $trunk['id']; ?> <input type="hidden" name="id" value="<?php echo $trunk['id']; ?>"></td>
                    <td><input type="text" name="name" value="<?php echo htmlspecialchars($trunk['name']); ?>" style="width: 100px;"></td>
                    <td><input type="text" name="ip" value="<?php echo htmlspecialchars($trunk['ip']); ?>" style="width: 120px;"></td>
                    <td><input type="number" name="port" value="<?php echo $trunk['port']; ?>" style="width: 60px;"></td>
                    <td><input type="number" name="max_ports" value="<?php echo $trunk['max_ports']; ?>" style="width: 60px;"></td>
                    <td><input type="text" name="prefix" value="<?php echo htmlspecialchars($trunk['prefix']); ?>" style="width: 60px;"></td>
                    <td>
                        <button type="submit" name="update" style="background-color: #2ecc71; color: white;">Save</button>
                        <a href="trunks.php" style="background-color: #95a5a6; color: white; padding: 6px 10px; border-radius: 4px; font-size: 14px; text-decoration: none;">Cancel</a>
                    </td>
                </form>
            <?php else: ?>
                <!-- View Mode -->
                <td><?php echo $trunk['id']; ?></td>
                <td><?php echo htmlspecialchars($trunk['name']); ?></td>
                <td><?php echo htmlspecialchars($trunk['ip']); ?></td>
                <td><?php echo $trunk['port']; ?></td>
                <td><?php echo $trunk['max_ports'] == 0 ? 'Unlimited' : $trunk['max_ports']; ?></td>
                <td><?php echo htmlspecialchars($trunk['prefix']); ?></td>
                <td>
                    <a href="?edit=<?php echo $trunk['id']; ?>" style="background-color: #3498db; color: white; padding: 6px 10px; border-radius: 4px; font-size: 14px; text-decoration: none; margin-right: 5px;">Edit</a>
                    
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this trunk?');">
                        <input type="hidden" name="id" value="<?php echo $trunk['id']; ?>">
                        <button type="submit" name="delete" style="background-color: #e74c3c; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer;">Delete</button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once 'includes/footer.php'; ?>
