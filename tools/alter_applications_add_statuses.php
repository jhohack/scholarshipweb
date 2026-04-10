<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "Running applications table alteration for new statuses...\n";

try {
    // Modify the 'status' column to include the new states
    $sql = "
        ALTER TABLE `applications`
        MODIFY COLUMN `status` ENUM('Pending', 'Under Review', 'Approved', 'Rejected', 'Active', 'Dropped', 'Renewal Request') NOT NULL DEFAULT 'Pending';
    ";
    $pdo->exec($sql);
    echo "SUCCESS: `applications` table 'status' column altered successfully.\n";
} catch (PDOException $e) {
    die("ERROR: Could not alter `applications` table: " . $e->getMessage() . "\n");
}

?>