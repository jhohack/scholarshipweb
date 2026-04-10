<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "Running scholarship table alteration...\n";

try {
    // Add columns for specifying applicant type availability
    $sql = "
        ALTER TABLE `scholarships`
        ADD COLUMN `accepting_new_applicants` BOOLEAN NOT NULL DEFAULT TRUE AFTER `category`,
        ADD COLUMN `accepting_renewal_applicants` BOOLEAN NOT NULL DEFAULT TRUE AFTER `accepting_new_applicants`;
    ";
    $pdo->exec($sql);
    echo "SUCCESS: `scholarships` table altered successfully. Added `accepting_new_applicants` and `accepting_renewal_applicants` columns.\n";
} catch (PDOException $e) {
    // Check if columns already exist to prevent error on re-run
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "INFO: Columns already exist in the `scholarships` table.\n";
    } else {
        die("ERROR: Could not alter `scholarships` table: " . $e->getMessage() . "\n");
    }
}