<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

if (!isStudent()) {
    header("Location: ../public/login.php");
    exit();
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$student_id = null;
$application = null;
$open_reupload_requests = [];
$request_group = null;
$errors = [];

try {
    $student_stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
    $student_stmt->execute([$user_id]);
    $student_id = (int) $student_stmt->fetchColumn();

    if ($student_id) {
        $application_id = filter_input(INPUT_GET, 'application_id', FILTER_SANITIZE_NUMBER_INT);
        if (!$application_id) {
            $application_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);
        }

        if ($application_id) {
            $app_stmt = $pdo->prepare("
                SELECT a.id, a.status, a.submitted_at, a.updated_at, s.name as scholarship_name, s.id as scholarship_id
                FROM applications a
                JOIN scholarships s ON a.scholarship_id = s.id
                WHERE a.id = ? AND a.student_id = ?
                LIMIT 1
            ");
            $app_stmt->execute([$application_id, $student_id]);
            $application = $app_stmt->fetch(PDO::FETCH_ASSOC);

            if ($application) {
                $open_reupload_requests = getOpenDocumentReuploadRequests($pdo, (int) $student_id, (int) $application['id']);
                $request_group = $open_reupload_requests[0] ?? null;
            }
        }
    }
} catch (PDOException $e) {
    error_log('Re-upload page error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reupload'])) {
    $submitted_application_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } elseif (!$application || !$submitted_application_id || (int) $submitted_application_id !== (int) $application['id']) {
        $errors[] = 'No open re-upload request was found for this application.';
    } else {
        $open_reupload_requests = getOpenDocumentReuploadRequests($pdo, (int) $student_id, (int) $application['id']);
        $request_group = $open_reupload_requests[0] ?? null;

        if (!$request_group) {
            $errors[] = 'This re-upload request has already been completed or closed. Please return to your applications list.';
        } else {
            $requests = $request_group['documents'] ?? [];
            $missingFiles = [];
            foreach ($requests as $request) {
                $reqId = (int) ($request['request_id'] ?? 0);
                $fileName = $_FILES['replacement_files']['name'][$reqId] ?? '';
                $fileError = $_FILES['replacement_files']['error'][$reqId] ?? UPLOAD_ERR_NO_FILE;
                if ($reqId <= 0 || $fileError !== UPLOAD_ERR_OK || trim((string) $fileName) === '') {
                    $missingFiles[] = trim((string) ($request['document_name'] ?? 'Document'));
                }
            }

            if (!empty($missingFiles)) {
                $errors[] = 'Please upload a PDF for each requested file: ' . implode(', ', $missingFiles) . '.';
            } else {
                $uploadedPaths = [];
                try {
                    $pdo->beginTransaction();

                    $stmt_complete = $pdo->prepare("
                        UPDATE application_reupload_requests
                        SET status = 'completed', resolved_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND application_id = ? AND status = 'pending'
                    ");

                    foreach ($requests as $request) {
                        $reqId = (int) ($request['request_id'] ?? 0);
                        $documentId = (int) ($request['document_id'] ?? 0);
                        $file = [
                            'name' => $_FILES['replacement_files']['name'][$reqId] ?? '',
                            'type' => $_FILES['replacement_files']['type'][$reqId] ?? '',
                            'tmp_name' => $_FILES['replacement_files']['tmp_name'][$reqId] ?? '',
                            'error' => $_FILES['replacement_files']['error'][$reqId] ?? UPLOAD_ERR_NO_FILE,
                            'size' => $_FILES['replacement_files']['size'][$reqId] ?? 0,
                        ];

                        $result = replaceApplicationDocument($pdo, $documentId, $file, $user_id, (int) $application['id'], $base_path);
                        if (empty($result['success'])) {
                            throw new RuntimeException($result['error'] ?? 'Failed to replace one of the requested documents.');
                        }

                        $uploadedPaths[] = $result['path'] ?? '';
                        $stmt_complete->execute([$reqId, $application['id']]);
                    }

                    $pdo->commit();
                    flashMessage('Your re-uploaded files were submitted successfully. The admin will review them shortly.');
                    header("Location: applications.php");
                    exit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    foreach ($uploadedPaths as $path) {
                        deleteStoredFileByPath($pdo, $path, $base_path);
                    }
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

$page_title = 'Re-upload Requested Files';
include 'header.php';
displayFlashMessages();
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Re-upload Requested Files</h1>
    <p class="text-muted mb-0">Upload only the documents that the admin asked you to replace. Your other application details stay unchanged.</p>
</div>

<?php if (!$application): ?>
    <div class="content-block" data-aos="fade-up">
        <div class="alert alert-info mb-0">
            This re-upload link is no longer active. Please open your Applications page to see the latest status.
        </div>
        <div class="mt-3">
            <a href="applications.php" class="btn btn-outline-primary">Back to Applications</a>
        </div>
    </div>
<?php elseif (!$request_group): ?>
    <div class="content-block" data-aos="fade-up">
        <div class="alert alert-info border-info shadow-sm">
            <div class="fw-bold mb-1">This request is no longer active</div>
            <div class="mb-2">The documents may already have been submitted, reviewed, or the request may have been closed. Check your Applications page for the latest update.</div>
            <a href="applications.php" class="btn btn-outline-primary">Back to Applications</a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-block" data-aos="fade-up">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <span class="badge bg-warning text-dark mb-2">Action Required</span>
                        <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($application['scholarship_name']); ?></h3>
                        <div class="text-muted">Current application status: <?php echo htmlspecialchars(formatApplicationStatus($application['status'])); ?></div>
                    </div>
                    <a href="applications.php" class="btn btn-outline-secondary">Back to Applications</a>
                </div>

                <div class="alert alert-warning border-warning">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Upload only the requested PDF files below. The rest of your application stays the same.
                </div>

                <?php if (!empty($request_group['note'])): ?>
                    <div class="alert alert-light border">
                        <div class="fw-bold mb-1">Admin note</div>
                        <div class="text-muted"><?php echo htmlspecialchars($request_group['note']); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($request_group['created_at'])): ?>
                    <div class="small text-muted mb-3">
                        Requested on <?php echo htmlspecialchars(date('F j, Y g:i A', strtotime($request_group['created_at']))); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="submit_reupload" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>">

                    <div class="row g-3">
                        <?php foreach ($request_group['documents'] as $request): ?>
                            <?php
                                $docName = $request['document_name'] ?? 'Document';
                                $currentPath = $request['document_path'] ?? '';
                                $currentFile = describeStoredFile($pdo, $currentPath, $base_path);
                            ?>
                            <div class="col-12">
                                <div class="card border-warning shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-bold mb-1"><?php echo htmlspecialchars($docName); ?></div>
                                                <div class="small text-muted">
                                                    <?php if (!empty($currentFile['url'])): ?>
                                                        <a href="<?php echo htmlspecialchars($currentFile['url']); ?>" target="_blank" rel="noopener">Open current file</a>
                                                    <?php else: ?>
                                                        Current file not available.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-warning text-dark">Needs re-upload</span>
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label fw-bold">Upload replacement PDF</label>
                                            <input type="file" class="form-control" name="replacement_files[<?php echo (int) $request['request_id']; ?>]" accept=".pdf,application/pdf" required>
                                            <div class="form-text">PDF only. Upload the clearest copy you have.</div>
                                        </div>
                                        <?php if (!empty($request['note'])): ?>
                                            <div class="small text-warning mt-3">
                                                <i class="bi bi-chat-square-text me-1"></i><?php echo htmlspecialchars($request['note']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-warning fw-bold">
                            <i class="bi bi-upload me-1"></i> Submit Re-upload Files
                        </button>
                        <a href="applications.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="content-block h-100" data-aos="fade-up" data-aos-delay="100">
                <h3 class="fw-bold">What happens next</h3>
                <div class="alert alert-info border-0">
                    <ol class="mb-0 ps-3">
                        <li>Upload only the requested PDF file(s).</li>
                        <li>We keep your essay, profile, and other fields untouched.</li>
                        <li>The admin reviews the updated files after you submit.</li>
                    </ol>
                </div>
                <div class="p-3 bg-light border rounded">
                    <div class="fw-bold mb-2">Need help?</div>
                    <p class="small text-muted mb-0">If you are unsure which file to upload, open the admin note above or message the scholarship office from the portal chat.</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
