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
$success_message = getFlashMessage();
$csrf_token = generate_csrf_token();

// Handle document actions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Your session expired. Please refresh the page and try again.";
    } elseif (isset($_POST['replace_missing_document'])) {
        $document_id = filter_input(INPUT_POST, 'document_id', FILTER_SANITIZE_NUMBER_INT);
        $replacement_file = $_FILES['replacement_document'] ?? null;

        if (!$document_id || !$replacement_file) {
            $errors[] = "Please choose the missing document and attach a replacement PDF.";
        } else {
            $replace_result = replaceLegacyMissingDocument(
                $pdo,
                $document_id,
                $replacement_file,
                $user_id,
                null,
                dirname(__DIR__)
            );

            if (!empty($replace_result['success'])) {
                flashMessage("Your missing legacy document was replaced successfully.");
                header("Location: documents.php");
                exit();
            }

            $errors[] = $replace_result['error'] ?? "Unable to replace the missing document right now.";
        }
    } elseif (isset($_FILES['document'])) {
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
                flashMessage("Document uploaded successfully.");
                header("Location: documents.php");
                exit();
            } catch (PDOException $e) {
                deleteStoredFileByPath($pdo, $upload_result['path'] ?? '', dirname(__DIR__));
                $errors[] = "Error saving document: " . $e->getMessage();
            }
        } else {
            $errors[] = $upload_result['error'] ?? "Invalid file type. Only PDF files are allowed.";
        }
    }
}

// Fetch user's documents
try {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $documents = $stmt->fetchAll();
    foreach ($documents as &$document) {
        $fileInfo = describeStoredFile($pdo, $document['file_path'] ?? '', dirname(__DIR__));
        $document['file_url'] = $fileInfo['url'];
        $document['file_exists'] = $fileInfo['exists'];
        $document['file_status'] = $fileInfo['reason'];
    }
    unset($document);
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
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <p class="mb-0"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>

        <form action="documents.php" method="post" enctype="multipart/form-data" class="mb-5">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                <?php if (!empty($document['file_exists'])): ?>
                    <a href="<?php echo htmlspecialchars($document['file_url'] ?? ''); ?>" target="_blank" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>
                        <?php echo htmlspecialchars($document['file_name']); ?>
                    </a>
                <?php else: ?>
                    <div class="list-group-item">
                        <i class="bi bi-file-earmark-x-fill text-warning me-2"></i>
                        <span class="fw-semibold"><?php echo htmlspecialchars($document['file_name']); ?></span>
                        <?php if (($document['file_status'] ?? '') === 'legacy_upload_missing'): ?>
                            <div class="small text-warning mt-1">This legacy file is missing from the current storage. You can re-upload a replacement PDF below.</div>
                            <form action="documents.php" method="post" enctype="multipart/form-data" class="mt-3 pt-3 border-top">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="replace_missing_document" value="1">
                                <input type="hidden" name="document_id" value="<?php echo (int) ($document['id'] ?? 0); ?>">
                                <label class="form-label fw-semibold small" for="replace-document-<?php echo (int) ($document['id'] ?? 0); ?>">Replacement PDF</label>
                                <div class="d-flex flex-column flex-md-row gap-2">
                                    <input
                                        type="file"
                                        class="form-control"
                                        id="replace-document-<?php echo (int) ($document['id'] ?? 0); ?>"
                                        name="replacement_document"
                                        accept=".pdf,application/pdf"
                                        required
                                    >
                                    <button type="submit" class="btn btn-outline-primary">
                                        <i class="bi bi-arrow-repeat me-1"></i>Replace Missing File
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="small text-warning mt-1">This file is not available in the current storage right now.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
