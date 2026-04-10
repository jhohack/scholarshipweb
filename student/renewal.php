<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

// --- Authentication & Authorization ---
if (!isStudent()) {
    header("Location: ../public/login.php");
    exit();
}

$application_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$user_id = $_SESSION['user_id'];
$errors = [];
$success = false;

if (!$application_id) {
    header("Location: dashboard.php");
    exit();
}

try {
    // Security Check: Verify this application belongs to the logged-in user and is 'Active'
    $stmt = $pdo->prepare("
        SELECT a.id, a.scholarship_id, s.name as scholarship_name
        FROM applications a
        JOIN students st ON a.student_id = st.id
        JOIN scholarships s ON a.scholarship_id = s.id
        WHERE a.id = ? AND st.user_id = ? AND a.status IN ('Active', 'Approved', 'For Renewal')
    ");
    $stmt->execute([$application_id, $user_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        // Not found, not theirs, or not active. Redirect.
        flashMessage("You do not have permission to renew this scholarship or it is not active.", "danger");
        header("Location: dashboard.php");
        exit();
    }

    // Get student ID
    $student_stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $student_stmt->execute([$user_id]);
    $student_id = $student_stmt->fetchColumn();

} catch (PDOException $e) {
    $errors[] = "A database error occurred. Please try again later.";
    error_log("Renewal page error: " . $e->getMessage());
}

// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($errors)) {
    $document = $_FILES['gwa_document'] ?? null;

    if (empty($document) || $document['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "An updated GWA document is required for renewal.";
    } else {
        $uploaded_file = storeUploadedFile(
            $pdo,
            $document,
            'renewals',
            'GWA_' . $student_id . '_',
            ['application/pdf'],
            appUploadMaxBytes(),
            $base_path
        );

        if (!$uploaded_file['success']) {
            $errors[] = $uploaded_file['error'] ?? "Error uploading file.";
        }

        // --- Database Insertion ---
        if (empty($errors) && !empty($uploaded_file['path'])) {
            try {
                $pdo->beginTransaction();

                // 1. Insert a new application with 'Renewal Request' status
                // We copy over the submission date from the original to maintain context, but set a new status.
                $new_application_id = dbExecuteInsert(
                    $pdo,
                    "INSERT INTO applications (
                        student_id,
                        scholarship_id,
                        scholarship_name,
                        application_requirements,
                        status,
                        application_type,
                        applicant_type,
                        submitted_at,
                        updated_at,
                        year_program,
                        program,
                        year_level,
                        units_enrolled,
                        gwa,
                        student_status,
                        scholarship_percentage,
                        scholarship_amount,
                        remarks
                    )
                    SELECT
                        student_id,
                        scholarship_id,
                        scholarship_name,
                        application_requirements,
                        'Renewal Request',
                        'renewal',
                        'Renewal',
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP,
                        year_program,
                        program,
                        year_level,
                        units_enrolled,
                        gwa,
                        'Renewal Student',
                        scholarship_percentage,
                        scholarship_amount,
                        NULL
                    FROM applications
                    WHERE id = ?",
                    [$application_id]
                );

                // 2. Insert the uploaded document, linking it to the new application
                $doc_stmt = $pdo->prepare("INSERT INTO documents (user_id, application_id, file_name, file_path) VALUES (?, ?, ?, ?)");
                $doc_stmt->execute([$user_id, $new_application_id, $uploaded_file['name'], $uploaded_file['path']]);

                $pdo->commit();
                $success = true;

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = "A database error occurred while submitting your renewal. Please try again.";
                error_log("Renewal submission error: " . $e->getMessage());
            }
        }
    }
}

$page_title = 'Renew Scholarship';
include 'header.php';
?>

<div class="page-header" data-aos="fade-down">
    <h1 class="fw-bold">Renew Scholarship</h1>
    <p class="text-muted">Submit your renewal application for the "<?php echo htmlspecialchars($application['scholarship_name']); ?>" scholarship.</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success text-center" data-aos="zoom-in">
        <h4 class="alert-heading">Renewal Submitted!</h4>
        <p>Your renewal request has been received. You can track its status on your dashboard.</p>
        <hr>
        <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
    </div>
<?php else: ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" data-aos="fade-up">
            <?php foreach ($errors as $error): ?>
                <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="content-block" data-aos="fade-up" data-aos-delay="100">
        <form action="renewal.php?id=<?php echo $application_id; ?>" method="post" enctype="multipart/form-data">
            <div class="mb-4">
                <label for="gwa_document" class="form-label fw-bold">Upload Updated GWA (PDF Only)</label>
                <input type="file" class="form-control" id="gwa_document" name="gwa_document" accept=".pdf" required>
                <div class="form-text">Please provide your most recent Grade Weighted Average document.</div>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-arrow-repeat me-2"></i>Submit Renewal Request</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
