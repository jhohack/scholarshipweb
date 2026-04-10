<?php
require_once __DIR__ . '/../../includes/config.php';

// Fetch all scholarships from the database
$stmt = $pdo->prepare("SELECT id, name, description, requirements, deadline, available_slots FROM scholarships");
$stmt->execute();
$scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scholarship List</title>
    <link rel="stylesheet" href="../../public/assets/css/style.css">
</head>
<body>
    <div class="admin-container">
        <h2>Available Scholarships</h2>
        <a href="create.php" class="button-primary">Create New Scholarship</a>
        
        <?php if (empty($scholarships)): ?>
            <p>No scholarships available.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Requirements</th>
                        <th>Deadline</th>
                        <th>Available Slots</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scholarships as $scholarship): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($scholarship['name']); ?></td>
                            <td><?php echo htmlspecialchars($scholarship['description']); ?></td>
                            <td><?php echo htmlspecialchars($scholarship['requirements']); ?></td>
                            <td><?php echo htmlspecialchars($scholarship['deadline']); ?></td>
                            <td><?php echo htmlspecialchars($scholarship['available_slots']); ?></td>
                            <td>
                                <a href="edit.php?id=<?php echo $scholarship['id']; ?>">Edit</a>
                                <a href="archive.php?id=<?php echo $scholarship['id']; ?>">Archive</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>