<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

$where = "WHERE 1=1";
$params = [];

// Handle Download Request (before header output if possible, but here we might have already outputted header)
// Actually, downloads should be a separate script or handled before includes/header.php if outputting binary.
// But we can redirect to a download handler.

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$caller_id = $_GET['caller_id'] ?? '';
$called_number = $_GET['called_number'] ?? '';
$hangup_cause = $_GET['hangup_cause'] ?? '';

// Apply filters
if ($start_date && $end_date) {
    // SQLite date comparison
    $where .= " AND date(cdr.start_time) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
} elseif ($start_date) {
    $where .= " AND date(cdr.start_time) = ?";
    $params[] = $start_date;
}

if ($caller_id !== '') {
    $where .= " AND cdr.caller_id LIKE ?";
    $params[] = "%$caller_id%";
}

if ($called_number !== '') {
    $where .= " AND cdr.called_number LIKE ?";
    $params[] = "%$called_number%";
}

if ($hangup_cause !== '') {
    $where .= " AND cdr.hangup_cause LIKE ?";
    $params[] = "%$hangup_cause%";
}

// Check for export
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'txt'])) {
    // Clear buffer to avoid HTML
    if (ob_get_level()) ob_end_clean();
    
    $sql = "SELECT cdr.*, t.name as trunk_name 
            FROM cdrs cdr 
            LEFT JOIN trunks t ON cdr.trunk_id = t.id 
            $where 
            ORDER BY cdr.start_time DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    if ($_GET['export'] == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cdr_export_' . date('Ymd_His') . '.csv"');
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Time', 'Trunk', 'Caller ID', 'Called Number', 'Duration', 'Billsec', 'Hangup Cause']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($fp, [
                $row['start_time'],
                $row['trunk_name'],
                $row['caller_id'],
                $row['called_number'],
                $row['duration'],
                $row['billsec'],
                $row['hangup_cause']
            ]);
        }
        fclose($fp);
    } elseif ($_GET['export'] == 'txt') {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="cdr_numbers_' . date('Ymd_His') . '.txt"');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo $row['called_number'] . "\n";
        }
    }
    exit;
}

// Pagination
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if (!in_array($limit, [5, 10, 20, 50, 100, 500])) $limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Count
$count_sql = "SELECT COUNT(*) FROM cdrs cdr $where";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_rows = $stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Data
$sql = "SELECT cdr.*, t.name as trunk_name 
        FROM cdrs cdr 
        LEFT JOIN trunks t ON cdr.trunk_id = t.id 
        $where 
        ORDER BY cdr.start_time DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$cdrs = $stmt->fetchAll();

$query_params = $_GET;
unset($query_params['page']);
$query_string = http_build_query($query_params);
?>

<h2>Call Detail Records (CDR)</h2>

<div class="card">
    <form method="GET" id="filterForm" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group">
            <label>From Date</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>">
        </div>
        <div class="form-group">
            <label>To Date</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>">
        </div>
        <div class="form-group">
            <label>Caller ID</label>
            <input type="text" name="caller_id" id="caller_id_input" value="<?php echo htmlspecialchars($caller_id); ?>" onkeyup="debouncedSubmit()">
        </div>
        <div class="form-group">
            <label>Called Number</label>
            <input type="text" name="called_number" id="called_number_input" value="<?php echo htmlspecialchars($called_number); ?>" onkeyup="debouncedSubmit()">
        </div>
        <div class="form-group">
            <label>Hangup Cause</label>
            <input type="text" name="hangup_cause" value="<?php echo htmlspecialchars($hangup_cause); ?>">
        </div>
        
        <div class="form-group">
            <label>Rows</label>
            <select name="limit" onchange="this.form.submit()">
                <?php foreach ([5, 10, 20, 50, 100, 500] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php if ($limit == $opt) echo 'selected'; ?>><?php echo $opt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>&nbsp;</label>
            <button type="submit">Filter</button>
            <a href="?" class="button" style="background:#666; padding: 5px 10px; text-decoration:none; color:white; border-radius:3px;">Reset</a>
        </div>
    </form>
    
    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
        <strong>Download Current Selection:</strong>
        <button type="button" onclick="download('csv')">Full Report (CSV)</button>
        <button type="button" onclick="download('txt')">Numbers Only (TXT)</button>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Time</th>
            <th>Trunk</th>
            <th>Caller ID</th>
            <th>Called Number</th>
            <th>Duration</th>
            <th>Billsec</th>
            <th>Hangup Cause</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($cdrs) > 0): ?>
            <?php foreach ($cdrs as $cdr): ?>
            <tr>
                <td><?php echo $cdr['start_time']; ?></td>
                <td><?php echo htmlspecialchars($cdr['trunk_name']); ?></td>
                <td><?php echo htmlspecialchars($cdr['caller_id']); ?></td>
                <td><?php echo htmlspecialchars($cdr['called_number']); ?></td>
                <td><?php echo $cdr['duration']; ?></td>
                <td><?php echo $cdr['billsec']; ?></td>
                <td><?php echo htmlspecialchars($cdr['hangup_cause']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7">No records found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
<div class="pagination" style="margin-top: 20px; text-align: center;">
    <?php if ($page > 1): ?>
        <a href="?<?php echo $query_string; ?>&page=1">&laquo; First</a>
        <a href="?<?php echo $query_string; ?>&page=<?php echo $page - 1; ?>">Prev</a>
    <?php endif; ?>
    <span style="margin: 0 10px;">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (Total: <?php echo $total_rows; ?>)</span>
    <?php if ($page < $total_pages): ?>
        <a href="?<?php echo $query_string; ?>&page=<?php echo $page + 1; ?>">Next</a>
        <a href="?<?php echo $query_string; ?>&page=<?php echo $total_pages; ?>">Last &raquo;</a>
    <?php endif; ?>
</div>
<style>
.pagination a { padding: 5px 10px; border: 1px solid #ddd; margin: 0 2px; text-decoration: none; color: #333; border-radius: 3px; }
.pagination a:hover { background-color: #f5f5f5; }
</style>
<?php endif; ?>

<script>
let timeout = null;
function debouncedSubmit() {
    clearTimeout(timeout);
    timeout = setTimeout(function() {
        // We actually want to keep focus, so full reload is annoying.
        // Ideally we'd use AJAX, but user asked for real-time filter.
        // A full page reload on every keypress is bad UX.
        // But for "Sensitive any digit", usually implies autocomplete or instant table refresh.
        // Given PHP architecture, we'll let them type and hit enter, OR auto-submit after 1s delay.
        document.getElementById('filterForm').submit();
    }, 1000);
}

function download(type) {
    // Clone current params
    const params = new URLSearchParams(window.location.search);
    params.set('export', type);
    // Remove page/limit for export (we want all matching rows usually? Or just current page?
    // User said "filtered data at that moment", usually implies ALL rows matching filter, not just page 1.
    // Our PHP code handles export by ignoring LIMIT if export is set.
    window.location.href = '?' + params.toString();
}
</script>

<?php require_once 'includes/footer.php'; ?>
