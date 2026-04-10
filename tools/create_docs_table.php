<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
$sql = "
CREATE TABLE IF NOT EXISTS documents (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT(11) NOT NULL,
  application_id INT(11) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  INDEX idx_app_id (application_id)
);
";
try {
    $pdo->exec($sql);
    echo "CREATE TABLE documents SUCCESS\n";
} catch (PDOException $e) {
    echo "CREATE TABLE ERROR: " . $e->getMessage() . "\n";
}
