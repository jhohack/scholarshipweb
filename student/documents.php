<?php
$base_path = dirname(__DIR__);
require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

checkSessionTimeout();

// Ensure the user is logged in as a student
if (!isStudent()) {
    header("Location: ../login.php");
    exit();
}

// Fetch user ID from session
$user_id = $_SESSION['user_id'];

// Initialize variables
$documents = [];
$errors = [];

// Handle document upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['document'])) {
    $file = $_FILES['document'];
    $upload_result = storeUploadedFile(
        $pdo,
        $file,
        'documents',
        'doc_' . $user_id . '_',
        ['application/pdf'],
        appUploadMaxBytes(),
        dirname(__DIR__)
    );

    if ($upload_result['success']) {
        try {
            $stmt = $pdo->prepare("INSERT INTO documents (user_id, file_name, file_path) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $upload_result['name'], $upload_result['path']]);
        } catch (PDOException $e) {
            $errors[] = "Error saving document: " . $e->getMessage();
        }
    } else {
        $errors[] = $upload_result['error'] ?? "Invalid file type. Only PDF files are allowed.";
    }
}

// Fetch user's documents
try {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $documents = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Error fetching documents: " . $e->getMessage();
}

$page_title = 'My Documents';
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">My Documents</h1>
    <p class="text-muted">Upload and manage your required scholarship documents.</p>
</div>

<div class="content-block" data-aos="fade-up" data-aos-delay="100">

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="documents.php" method="post" enctype="multipart/form-data" class="mb-5">
            <div class="mb-3">
                <label for="document" class="form-label fw-bold">Upload Document (PDF only)</label>
                <input type="file" class="form-control" id="document" name="document" accept=".pdf" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload-fill me-2"></i>Upload</button>
        </form>

        <h4 class="mb-3">Your Uploaded Documents</h4>
        <?php if (empty($documents)): ?>
            <p class="text-muted">No documents uploaded yet.</p>
        <?php else: ?>
            <div class="list-group">
            <?php foreach ($documents as $document): ?>
                <a href="<?php echo htmlspecialchars(storedFilePathToUrl($document['file_path'] ?? '')); ?>" target="_blank" class="list-group-item list-group-item-action">
                    <i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>
                    <?php echo htmlspecialchars($document['file_name']); ?>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
