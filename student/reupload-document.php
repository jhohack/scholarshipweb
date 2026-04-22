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
$application_documents = [];
$generic_reupload_mode = false;
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

                $doc_stmt = $pdo->prepare("
                    SELECT id, file_name, file_path, uploaded_at
                    FROM documents
                    WHERE application_id = ?
                    ORDER BY id ASC
                ");
                $doc_stmt->execute([(int) $application['id']]);
                $application_documents = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
                $generic_reupload_mode = strcasecmp((string) ($application['status'] ?? ''), 'Under Review') === 0 && empty($request_group);
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

        if ($request_group) {
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
                    flashMessage('Your updated files were saved successfully. The previous copies were replaced, and the admin will review them shortly.');
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
        } else {
            $applicationStatus = strtolower(trim((string) ($application['status'] ?? '')));

            if ($applicationStatus !== 'under review') {
                $errors[] = 'This re-upload request has already been completed or closed. Please return to your applications list.';
            } elseif (empty($application_documents)) {
                $errors[] = 'No documents were found for this application to re-upload.';
            } else {
                $replacement_docs = [];
                $removed_docs = [];

                foreach ($application_documents as $document) {
                    $documentId = (int) ($document['id'] ?? 0);
                    if ($documentId <= 0) {
                        continue;
                    }

                    $fileName = $_FILES['replacement_files']['name'][$documentId] ?? '';
                    $fileError = $_FILES['replacement_files']['error'][$documentId] ?? UPLOAD_ERR_NO_FILE;
                    $removeRequested = !empty($_POST['remove_files'][$documentId]);
                    $hasUpload = $fileError !== UPLOAD_ERR_NO_FILE && trim((string) $fileName) !== '';

                    if ($hasUpload) {
                        $removeRequested = false;
                    }

                    if (!$hasUpload) {
                        if ($removeRequested) {
                            $removed_docs[] = $documentId;
                        }
                        continue;
                    }

                    if ($fileError !== UPLOAD_ERR_OK) {
                        $errors[] = 'One or more selected files could not be uploaded. Please try again with PDF files only.';
                        break;
                    }

                    $replacement_docs[] = [
                        'document_id' => $documentId,
                        'file' => [
                            'name' => $fileName,
                            'type' => $_FILES['replacement_files']['type'][$documentId] ?? '',
                            'tmp_name' => $_FILES['replacement_files']['tmp_name'][$documentId] ?? '',
                            'error' => $fileError,
                            'size' => $_FILES['replacement_files']['size'][$documentId] ?? 0,
                        ],
                    ];
                }

                if (empty($errors)) {
                    if (empty($replacement_docs) && empty($removed_docs)) {
                        $errors[] = 'Please upload a replacement PDF or select a file to remove.';
                    } else {
                        $uploadedPaths = [];
                        $removedPaths = [];

                        try {
                            $pdo->beginTransaction();

                            foreach ($replacement_docs as $item) {
                                $result = replaceApplicationDocument(
                                    $pdo,
                                    (int) $item['document_id'],
                                    $item['file'],
                                    $user_id,
                                    (int) $application['id'],
                                    $base_path
                                );

                                if (empty($result['success'])) {
                                    throw new RuntimeException($result['error'] ?? 'Failed to replace one of the selected documents.');
                                }

                                $uploadedPaths[] = $result['path'] ?? '';
                            }

                            foreach ($removed_docs as $documentId) {
                                $result = removeApplicationDocumentFile(
                                    $pdo,
                                    (int) $documentId,
                                    $user_id,
                                    (int) $application['id'],
                                    $base_path
                                );

                                if (empty($result['success'])) {
                                    throw new RuntimeException($result['error'] ?? 'Failed to remove one of the selected documents.');
                                }

                                if (!empty($result['previous_path'])) {
                                    $removedPaths[] = $result['previous_path'];
                                }
                            }

                            $pdo->commit();

                            foreach ($uploadedPaths as $path) {
                                deleteStoredFileByPath($pdo, $path, $base_path);
                            }
                            foreach ($removedPaths as $path) {
                                deleteStoredFileByPath($pdo, $path, $base_path);
                            }

                            flashMessage('Your selected file changes were saved successfully. The previous copies were replaced, and the admin will review them shortly.');
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
    }
}

$page_title = 'Update Files';
include 'header.php';
displayFlashMessages();
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Update Files</h1>
    <p class="text-muted mb-0">Replace or clear only the files that need attention. Your other application details stay unchanged.</p>
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
<?php elseif ($request_group): ?>
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
                    Upload only the requested PDF files below.
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
                                                <div class="fw-bold mb-1 text-break"><?php echo htmlspecialchars($docName); ?></div>
                                                <?php if (!empty($request['created_at'])): ?>
                                                    <div class="small text-muted mb-1">Requested on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($request['created_at']))); ?></div>
                                                <?php endif; ?>
                                                <div class="small text-muted">
                                                    <?php if (!empty($currentFile['url'])): ?>
                                                        <a href="<?php echo htmlspecialchars($currentFile['url']); ?>" target="_blank" rel="noopener">Open current file</a>
                                                    <?php else: ?>
                                                        Current copy unavailable.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-warning text-dark">Needs re-upload</span>
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label fw-bold">Replace this file with a PDF</label>
                                            <input type="file" class="form-control reupload-file-input" name="replacement_files[<?php echo (int) $request['request_id']; ?>]" accept=".pdf,application/pdf" required>
                                            <div class="form-text">PDF only. This will replace the current file in the same slot.</div>
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
                            <i class="bi bi-check2-circle me-1"></i> Save Changes
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
<?php elseif ($generic_reupload_mode && !empty($application_documents)): ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-block" data-aos="fade-up">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <span class="badge bg-info text-dark mb-2">Under Review</span>
                        <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($application['scholarship_name']); ?></h3>
                        <div class="text-muted">Current application status: <?php echo htmlspecialchars(formatApplicationStatus($application['status'])); ?></div>
                    </div>
                    <a href="applications.php" class="btn btn-outline-secondary">Back to Applications</a>
                </div>

                <div class="alert alert-info border-info">
                    <i class="bi bi-upload me-1"></i>
                    Your application is under review. You can replace or clear any file below without filling out the full form again.
                </div>

                <div class="alert alert-light border">
                    <div class="fw-bold mb-1">How this works</div>
                    <div class="text-muted">Leave any file blank if it does not need to change. Use the clear option if you want to remove a current file first.</div>
                </div>

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
                        <?php foreach ($application_documents as $document): ?>
                            <?php
                                $documentId = (int) ($document['id'] ?? 0);
                                $docName = formatDocumentDisplayName($document['file_name'] ?? '');
                                $currentPath = $document['file_path'] ?? '';
                                $currentFile = describeStoredFile($pdo, $currentPath, $base_path);
                                $currentAvailable = !empty($currentFile['url']);
                            ?>
                            <div class="col-12">
                                <div class="card border-info shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-bold mb-1 text-break"><?php echo htmlspecialchars($docName); ?></div>
                                                <?php if (!empty($document['uploaded_at'])): ?>
                                                    <div class="small text-muted mb-1">Latest saved <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($document['uploaded_at']))); ?></div>
                                                <?php endif; ?>
                                                <div class="small text-muted">
                                                    <?php if ($currentAvailable): ?>
                                                        <a href="<?php echo htmlspecialchars($currentFile['url']); ?>" target="_blank" rel="noopener">Open current file</a>
                                                    <?php else: ?>
                                                        Current copy unavailable.
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="badge bg-info text-dark">Optional replacement</span>
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label fw-bold">Replace this file with a PDF</label>
                                            <input type="file" class="form-control reupload-file-input" name="replacement_files[<?php echo $documentId; ?>]" accept=".pdf,application/pdf">
                                            <div class="form-text">Upload only if this file needs to change. It will overwrite the current copy.</div>
                                        </div>
                                        <?php if ($currentAvailable): ?>
                                            <div class="form-check mt-3">
                                                <input class="form-check-input remove-file-toggle" type="checkbox" name="remove_files[<?php echo $documentId; ?>]" id="remove-file-<?php echo $documentId; ?>" value="1">
                                                <label class="form-check-label fw-semibold text-danger" for="remove-file-<?php echo $documentId; ?>">
                                                    Remove current file
                                                </label>
                                                <div class="form-text">Use this if you want to clear the slot before uploading a replacement.</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-info text-dark fw-bold">
                            <i class="bi bi-check2-circle me-1"></i> Save Changes
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
                        <li>Choose the file(s) that need correction.</li>
                        <li>Your profile, responses, and other details stay untouched.</li>
                        <li>The admin reviews the updated documents after you submit.</li>
                    </ol>
                </div>
                <div class="p-3 bg-light border rounded">
                    <div class="fw-bold mb-2">Need help?</div>
                    <p class="small text-muted mb-0">If you are unsure which file should change, message the scholarship office and we will point you to the right document.</p>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($application && strcasecmp((string) ($application['status'] ?? ''), 'Under Review') === 0): ?>
    <div class="content-block" data-aos="fade-up">
        <div class="alert alert-info border-info shadow-sm mb-0">
            <div class="fw-bold mb-1">Under Review</div>
            <div class="mb-2">This application is under review, but no files are currently available to replace.</div>
            <a href="applications.php" class="btn btn-outline-primary">Back to Applications</a>
        </div>
    </div>
<?php else: ?>
    <div class="content-block" data-aos="fade-up">
        <div class="alert alert-info border-info shadow-sm">
            <div class="fw-bold mb-1">This request is no longer active</div>
            <div class="mb-2">The documents may already have been submitted, reviewed, or the request may have been closed. Check your Applications page for the latest update.</div>
            <a href="applications.php" class="btn btn-outline-primary">Back to Applications</a>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('change', function (event) {
    const fileInput = event.target.closest('.reupload-file-input');
    if (fileInput) {
        const card = fileInput.closest('.card');
        const removeToggle = card ? card.querySelector('.remove-file-toggle') : null;
        if (removeToggle && fileInput.files && fileInput.files.length > 0) {
            removeToggle.checked = false;
        }
    }

    const removeToggle = event.target.closest('.remove-file-toggle');
    if (removeToggle) {
        const card = removeToggle.closest('.card');
        const fileInput = card ? card.querySelector('.reupload-file-input') : null;
        if (fileInput && removeToggle.checked) {
            fileInput.value = '';
        }
    }
});
</script>

<?php include 'footer.php'; ?>
