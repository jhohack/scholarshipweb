<?php
require_once __DIR__ . '/../../includes/config.php';

$errors = [];
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $scholarshipId = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);

    if (empty($scholarshipId)) {
        $errors[] = "Scholarship ID is required.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE scholarships SET status = 'archived' WHERE id = ?");
            $stmt->execute([$scholarshipId]);
            $success = true;
        } catch (PDOException $e) {
            $errors[] = "A database error occurred. Please try again later.";
            // Log the error in production: error_log($e->getMessage());
        }
    }
}

$scholarships = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM scholarships WHERE status != 'archived'");
    $scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Unable to fetch scholarships. Please try again later.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archive Scholarships</title>
    <link rel="stylesheet" href="../../public/assets/css/style.css">
</head>
<body>
    <div class="admin-container">
        <h2>Archive Scholarships</h2>

        <?php if ($success): ?>
            <div class="alert alert-success">Scholarship archived successfully.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="archive.php" method="post">
            <div class="form-group">
                <label for="scholarship_id">Select Scholarship to Archive</label>
                <select id="scholarship_id" name="scholarship_id" required>
                    <option value="">-- Select Scholarship --</option>
                    <?php foreach ($scholarships as $scholarship): ?>
                        <option value="<?php echo htmlspecialchars($scholarship['id']); ?>">
                            <?php echo htmlspecialchars($scholarship['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="button-primary">Archive Scholarship</button>
        </form>
    </div>
</body>
</html>