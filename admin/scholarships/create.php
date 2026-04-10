<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$errors = [];
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $requirements = filter_input(INPUT_POST, 'requirements', FILTER_SANITIZE_STRING);
    $deadline = filter_input(INPUT_POST, 'deadline', FILTER_SANITIZE_STRING);
    $available_slots = filter_input(INPUT_POST, 'available_slots', FILTER_VALIDATE_INT);

    if (empty($name) || empty($description) || empty($requirements) || empty($deadline) || $available_slots === false) {
        $errors[] = "All fields are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO scholarships (name, description, requirements, deadline, available_slots) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $description, $requirements, $deadline, $available_slots]);
            $success = true;
        } catch (PDOException $e) {
            $errors[] = "A database error occurred. Please try again later.";
            // Log the error in production: error_log($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Scholarship</title>
    <link rel="stylesheet" href="../../public/assets/css/style.css">
</head>
<body>
    <div class="container">
        <h2>Create New Scholarship</h2>

        <?php if ($success): ?>
            <div class="alert alert-success">Scholarship created successfully!</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="create.php" method="post">
            <div class="form-group">
                <label for="name">Scholarship Name</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" required></textarea>
            </div>
            <div class="form-group">
                <label for="requirements">Requirements</label>
                <textarea id="requirements" name="requirements" required></textarea>
            </div>
            <div class="form-group">
                <label for="deadline">Deadline</label>
                <input type="date" id="deadline" name="deadline" required>
            </div>
            <div class="form-group">
                <label for="available_slots">Available Slots</label>
                <input type="number" id="available_slots" name="available_slots" required>
            </div>
            <button type="submit" class="button-primary">Create Scholarship</button>
        </form>
    </div>
</body>
</html>