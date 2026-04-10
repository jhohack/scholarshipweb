<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$errors = [];
$success = '';
$scholarship_id = $_GET['id'] ?? null;

if (!$scholarship_id) {
    header("Location: list.php");
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM scholarships WHERE id = ?");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch();

    if (!$scholarship) {
        $errors[] = "Scholarship not found.";
    }

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $requirements = filter_input(INPUT_POST, 'requirements', FILTER_SANITIZE_STRING);
        $deadline = filter_input(INPUT_POST, 'deadline', FILTER_SANITIZE_STRING);
        $available_slots = filter_input(INPUT_POST, 'available_slots', FILTER_VALIDATE_INT);

        if (empty($name) || empty($description) || empty($requirements) || empty($deadline) || $available_slots === false) {
            $errors[] = "All fields are required.";
        } else {
            $stmt = $pdo->prepare("UPDATE scholarships SET name = ?, description = ?, requirements = ?, deadline = ?, available_slots = ? WHERE id = ?");
            if ($stmt->execute([$name, $description, $requirements, $deadline, $available_slots, $scholarship_id])) {
                $success = "Scholarship updated successfully.";
                $scholarship = [
                    'name' => $name,
                    'description' => $description,
                    'requirements' => $requirements,
                    'deadline' => $deadline,
                    'available_slots' => $available_slots,
                ];
            } else {
                $errors[] = "Failed to update scholarship.";
            }
        }
    }
} catch (PDOException $e) {
    $errors[] = "A database error occurred. Please try again later.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Scholarship</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <div class="container">
        <h2>Edit Scholarship</h2>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <form action="edit.php?id=<?php echo htmlspecialchars($scholarship_id); ?>" method="post">
            <div class="form-group">
                <label for="name">Scholarship Name</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($scholarship['name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" required><?php echo htmlspecialchars($scholarship['description']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="requirements">Requirements</label>
                <textarea id="requirements" name="requirements" required><?php echo htmlspecialchars($scholarship['requirements']); ?></textarea>
            </div>
            <div class="form-group">
                <label for="deadline">Deadline</label>
                <input type="date" id="deadline" name="deadline" value="<?php echo htmlspecialchars($scholarship['deadline']); ?>" required>
            </div>
            <div class="form-group">
                <label for="available_slots">Available Slots</label>
                <input type="number" id="available_slots" name="available_slots" value="<?php echo htmlspecialchars($scholarship['available_slots']); ?>" required>
            </div>
            <button type="submit" class="button-primary">Update Scholarship</button>
        </form>
    </div>
</body>
</html>