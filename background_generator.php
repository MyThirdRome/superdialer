<?php
// Use absolute path for includes
require_once __DIR__ . '/includes/db.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

$id = $argv[1] ?? 0;
$prefix = $argv[2] ?? '';
$count = $argv[3] ?? 0;

if (!$id || !$prefix || !$count) {
    die("Usage: php background_generator.php <campaign_id> <prefix> <count>\n");
}

// Log start
file_put_contents('/var/log/autodialer_gen.log', date('Y-m-d H:i:s') . " Starting generation for Camp $id: $count numbers\n", FILE_APPEND);

echo "Starting generation for Campaign $id: $count numbers with pattern $prefix\n";

$batch_size = 1000;
$total_added = 0;
$duplicates_skipped = 0;

try {
    // Create index if not exists to speed up and enforce uniqueness
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_camp_num ON campaign_numbers (campaign_id, number)");

    $stmt = $db->prepare("INSERT OR IGNORE INTO campaign_numbers (campaign_id, number, status) VALUES (?, ?, 'pending')");
    $db->beginTransaction();

    for ($i = 0; $i < $count; $i++) {
        $num = $prefix;
        // Optimized random digit replacement
        $num_chars = str_split($num);
        foreach ($num_chars as &$char) {
            if ($char === '#') {
                $char = (string)mt_rand(0, 9);
            }
        }
        $num = implode('', $num_chars);
        
        $stmt->execute([$id, $num]);
        $total_added++;
        
        // Commit every batch
        if ($total_added % $batch_size == 0) {
            $db->commit();
            $db->beginTransaction();
            echo "Processed $total_added / $count...\n";
        }
    }

    $db->commit();
    
    // Update total_numbers count in campaigns table
    $total = $db->query("SELECT COUNT(*) FROM campaign_numbers WHERE campaign_id = $id")->fetchColumn();
    $db->prepare("UPDATE campaigns SET total_numbers = ? WHERE id = ?")->execute([$total, $id]);
    
    echo "Done. Processed: $total_added\n";
    file_put_contents('/var/log/autodialer_gen.log', date('Y-m-d H:i:s') . " Finished Camp $id. Added $total_added numbers.\n", FILE_APPEND);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    file_put_contents('/var/log/autodialer_gen.log', date('Y-m-d H:i:s') . " Error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Error: " . $e->getMessage() . "\n";
}
?>