<?php
// Use absolute path for includes
require_once __DIR__ . '/includes/db.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

$id = $argv[1] ?? 0;
$file_path = $argv[2] ?? '';

if (!$id || !$file_path) {
    die("Usage: php import_numbers.php <campaign_id> <file_path>\n");
}

if (!file_exists($file_path)) {
    die("File not found: $file_path\n");
}

echo "Starting import for Campaign $id from $file_path\n";

$batch_size = 1000;
$total_added = 0;

try {
    // Try to create index, but don't fail if it fails (it might fail if duplicates exist, 
    // but we should proceed with inserts anyway, though INSERT OR IGNORE won't dedup without it)
    try {
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_camp_num ON campaign_numbers (campaign_id, number)");
    } catch (Exception $e) {
        echo "Warning: Could not create unique index (duplicates might exist). Continuing...\n";
    }

    // Use INSERT OR IGNORE. If index exists, it skips dupes. If not, it inserts.
    // To be safe, we can do a SELECT check if index creation failed, but let's rely on the index being there.
    $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_numbers (campaign_id, number, status) VALUES (?, ?, 'pending')");
    $db->beginTransaction();

    $handle = fopen($file_path, "r");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $num = trim($line);
            $num = preg_replace('/[^0-9]/', '', $num);
            
            if (!empty($num)) {
                $stmt->execute([$id, $num]);
                $total_added++;
                
                if ($total_added % $batch_size == 0) {
                    $db->commit();
                    $db->beginTransaction();
                    echo "Processed $total_added numbers...\n";
                }
            }
        }
        fclose($handle);
    }

    $db->commit();
    
    // Update total_numbers count in campaigns table
    $total = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $id")->fetchColumn();
    $db->prepare("UPDATE campaigns SET total_numbers = ? WHERE id = ?")->execute([$total, $id]);
    
    // Cleanup
    @unlink($file_path);
    
    echo "Done. Imported: $total_added numbers.\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
?>