<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
require_once $base_path . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

function scholarship_api_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function scholarship_changed_by(PDO $pdo): string
{
    if (!empty($_SESSION['name'])) {
        return trim((string) $_SESSION['name']);
    }

    $user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($user_id > 0) {
        $stmt = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($user['email'])) {
            return (string) $user['email'];
        }
        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        if ($name !== '') {
            return $name;
        }
    }

    return 'portal';
}

function scholarship_insert_history(PDO $pdo, int $application_id, ?string $old_status, string $new_status, ?string $remarks, string $changed_by): void
{
    $stmt = $pdo->prepare("
        INSERT INTO application_status_history (
            application_id,
            old_status,
            new_status,
            remarks,
            changed_by,
            changed_via
        ) VALUES (?, ?, ?, ?, ?, 'portal')
    ");
    $stmt->execute([
        $application_id,
        $old_status !== null ? trim($old_status) : null,
        trim($new_status),
        $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
        $changed_by,
    ]);
}

function scholarship_sync_student_record(PDO $pdo, int $student_id): void
{
    if ($student_id <= 0) {
        return;
    }

    if (dbIsPgsql($pdo)) {
        $stmt = $pdo->prepare("
            UPDATE students AS s
            SET
                school_id_number = COALESCE(NULLIF(s.school_id_number, ''), NULLIF(u.school_id, '')),
                email = COALESCE(NULLIF(s.email, ''), NULLIF(u.email, '')),
                phone = COALESCE(NULLIF(s.phone, ''), NULLIF(u.contact_number, '')),
                date_of_birth = COALESCE(s.date_of_birth, u.birthdate),
                updated_at = CURRENT_TIMESTAMP
            FROM users AS u
            WHERE s.user_id = u.id AND s.id = ?
        ");
        $stmt->execute([$student_id]);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE students s
        JOIN users u ON u.id = s.user_id
        SET
            s.school_id_number = COALESCE(NULLIF(s.school_id_number, ''), NULLIF(u.school_id, '')),
            s.email = COALESCE(NULLIF(s.email, ''), NULLIF(u.email, '')),
            s.phone = COALESCE(NULLIF(s.phone, ''), NULLIF(u.contact_number, '')),
            s.date_of_birth = COALESCE(s.date_of_birth, u.birthdate),
            s.updated_at = CURRENT_TIMESTAMP
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
}

$allowed_transitions = [
    'Pending' => ['Under Review', 'Approved', 'Rejected'],
    'Pending Exam' => ['Under Review', 'Approved', 'Rejected'],
    'Under Review' => ['Approved', 'Rejected', 'Incomplete'],
    'Incomplete' => ['Under Review', 'Rejected'],
    'Approved' => ['Active', 'Rejected'],
    'Active' => ['For Renewal', 'Dropped'],
    'For Renewal' => ['Active', 'Rejected'],
    'Renewal Request' => ['Active', 'Rejected'],
];

$occupied_statuses = ['Approved', 'Active', 'Accepted', 'For Renewal', 'Renewal Request', 'Drop Requested'];
$transition_targets = array_values(array_unique(array_merge(...array_values($allowed_transitions))));

$application_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);
$new_status = trim((string) ($_POST['new_status'] ?? ''));
$remarks = trim((string) ($_POST['remarks'] ?? ''));
$scholarship_percentage_raw = $_POST['scholarship_percentage'] ?? null;
$scholarship_amount_raw = $_POST['scholarship_amount'] ?? null;

if (!$application_id) {
    scholarship_api_response(400, ['success' => false, 'error' => 'Invalid application ID']);
}

if (!in_array($new_status, $transition_targets, true)) {
    scholarship_api_response(400, ['success' => false, 'error' => 'Unsupported target status']);
}

if ($new_status === 'Rejected' && $remarks === '') {
    scholarship_api_response(422, ['success' => false, 'error' => 'Decline remarks are required']);
}

$scholarship_percentage = null;
if ($scholarship_percentage_raw !== null && $scholarship_percentage_raw !== '') {
    $scholarship_percentage = (float) $scholarship_percentage_raw;
}

$scholarship_amount = null;
if ($scholarship_amount_raw !== null && $scholarship_amount_raw !== '') {
    $scholarship_amount = (float) $scholarship_amount_raw;
}

$changed_by = scholarship_changed_by($pdo);
$auto_reject_note = "\nSystem: Auto-rejected due to approval in another scholarship.";

try {
    $pdo->beginTransaction();

    $application_stmt = $pdo->prepare("
        SELECT
            a.id,
            a.student_id,
            a.scholarship_id,
            a.status AS current_status,
            a.applicant_type,
            a.scholarship_percentage,
            a.scholarship_amount,
            s.name AS scholarship_name,
            s.available_slots,
            s.amount AS base_amount,
            s.amount_type,
            s.accepting_new_applicants,
            stu.student_name,
            stu.email
        FROM applications a
        JOIN scholarships s ON s.id = a.scholarship_id
        JOIN students stu ON stu.id = a.student_id
        WHERE a.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $application_stmt->execute([$application_id]);
    $application = $application_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        scholarship_api_response(404, ['success' => false, 'error' => 'Application not found']);
    }

    $scholarship_lock_stmt = $pdo->prepare("SELECT id FROM scholarships WHERE id = ? FOR UPDATE");
    $scholarship_lock_stmt->execute([(int) ($application['scholarship_id'] ?? 0)]);

    $current_status = trim((string) ($application['current_status'] ?? ''));
    if (!isset($allowed_transitions[$current_status]) || !in_array($new_status, $allowed_transitions[$current_status], true)) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        scholarship_api_response(409, [
            'success' => false,
            'error' => "Cannot change application status from {$current_status} to {$new_status}.",
        ]);
    }

    if ($scholarship_percentage !== null && ($scholarship_percentage < 0 || $scholarship_percentage > 100)) {
        throw new RuntimeException('Percentage must be between 0 and 100.');
    }

    if ($scholarship_amount !== null && $scholarship_amount < 0) {
        throw new RuntimeException('Amount must be zero or greater.');
    }

    $base_amount = (float) ($application['base_amount'] ?? 0);
    $amount_type = (string) ($application['amount_type'] ?? 'Peso');

    if ($amount_type === 'Peso' && $scholarship_amount !== null && $base_amount > 0 && $scholarship_amount > $base_amount) {
        throw new RuntimeException('Amount cannot exceed the base scholarship amount.');
    }

    if ($amount_type === 'Percentage' && $scholarship_percentage !== null && $base_amount > 0 && $scholarship_percentage > $base_amount) {
        throw new RuntimeException('Percentage cannot exceed the scholarship limit.');
    }

    $is_capacity_occupied = in_array($current_status, $occupied_statuses, true);
    $is_new_capacity_gain = $new_status === 'Approved'
        && !$is_capacity_occupied
        && trim((string) ($application['applicant_type'] ?? '')) === 'New';

    if ($is_new_capacity_gain) {
        $capacity = getScholarshipCapacitySummary(
            $pdo,
            (int) ($application['scholarship_id'] ?? 0),
            (int) ($application['available_slots'] ?? 0)
        );

        if (!empty($capacity['is_full'])) {
            throw new RuntimeException('This scholarship has reached its slot limit. Add more slots before approving another new applicant.');
        }

        $duplicate_stmt = $pdo->prepare("
            SELECT a.id
            FROM applications a
            WHERE a.student_id = ? AND a.scholarship_id = ? AND a.id <> ? AND a.status IN ('Approved', 'Active')
            LIMIT 1
        ");
        $duplicate_stmt->execute([
            (int) ($application['student_id'] ?? 0),
            (int) ($application['scholarship_id'] ?? 0),
            (int) $application_id,
        ]);

        if ($duplicate_stmt->fetchColumn()) {
            throw new RuntimeException('This student already has an approved scholarship record for this program.');
        }
    }

    scholarship_sync_student_record($pdo, (int) ($application['student_id'] ?? 0));

    $update_stmt = $pdo->prepare("
        UPDATE applications
        SET
            status = ?,
            remarks = ?,
            scholarship_percentage = ?,
            scholarship_amount = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $update_stmt->execute([
        $new_status,
        $remarks !== '' ? $remarks : null,
        $scholarship_percentage,
        $scholarship_amount,
        (int) $application_id,
    ]);

    scholarship_insert_history(
        $pdo,
        (int) $application_id,
        $current_status,
        $new_status,
        $remarks !== '' ? $remarks : null,
        $changed_by
    );

    $auto_rejected_ids = [];
    if ($new_status === 'Approved') {
        $other_apps_stmt = $pdo->prepare("
            SELECT id, status, remarks
            FROM applications
            WHERE student_id = ? AND id <> ? AND status IN ('Pending', 'Pending Exam', 'Under Review')
            ORDER BY id DESC
        ");
        $other_apps_stmt->execute([
            (int) ($application['student_id'] ?? 0),
            (int) $application_id,
        ]);
        $other_apps = $other_apps_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reject_stmt = $pdo->prepare("
            UPDATE applications
            SET
                status = 'Rejected',
                remarks = CONCAT(COALESCE(remarks, ''), ?),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        foreach ($other_apps as $other_app) {
            $reject_stmt->execute([$auto_reject_note, (int) ($other_app['id'] ?? 0)]);
            $auto_rejected_ids[] = (int) ($other_app['id'] ?? 0);

            scholarship_insert_history(
                $pdo,
                (int) ($other_app['id'] ?? 0),
                (string) ($other_app['status'] ?? 'Pending'),
                'Rejected',
                trim((string) (($other_app['remarks'] ?? '') . $auto_reject_note)),
                'system'
            );
        }

        $capacity_after = getScholarshipCapacitySummary(
            $pdo,
            (int) ($application['scholarship_id'] ?? 0),
            (int) ($application['available_slots'] ?? 0)
        );
        if (!empty($capacity_after['is_full'])) {
            $close_stmt = $pdo->prepare("UPDATE scholarships SET accepting_new_applicants = 0 WHERE id = ?");
            $close_stmt->execute([(int) ($application['scholarship_id'] ?? 0)]);
        }
    }

    $notification_title = 'Application Status Update';
    if ($new_status === 'Under Review') {
        $notification_message = 'Your application for ' . ($application['scholarship_name'] ?? 'this scholarship') . ' is now under review.';
    } elseif ($new_status === 'Incomplete') {
        $notification_message = 'Your application for ' . ($application['scholarship_name'] ?? 'this scholarship') . ' needs additional updates before approval.';
    } else {
        $notification_message = 'Your application for ' . ($application['scholarship_name'] ?? 'this scholarship') . ' has been updated to: ' . $new_status . '.';
    }
    if ($remarks !== '') {
        $notification_message .= ' Remarks: ' . $remarks;
    }

    $notification_stmt = $pdo->prepare("
        INSERT INTO notifications (student_id, title, message)
        VALUES (?, ?, ?)
    ");
    $notification_stmt->execute([
        (int) ($application['student_id'] ?? 0),
        $notification_title,
        $notification_message,
    ]);

    $pdo->commit();

    $email_error = null;
    $recipient_email = trim((string) ($application['email'] ?? ''));
    if ($recipient_email !== '') {
        $mail = new PHPMailer(true);
        try {
            configureSmtpMailer($mail, 'DVC Scholarship Hub');
            $mail->addAddress($recipient_email, (string) ($application['student_name'] ?? 'Scholarship Applicant'));
            $mail->isHTML(true);
            $mail->Subject = 'Application Status Update: ' . ($application['scholarship_name'] ?? 'Scholarship');

            $body = '<p>Dear ' . htmlspecialchars((string) ($application['student_name'] ?? 'Applicant'), ENT_QUOTES, 'UTF-8') . ',</p>';
            $body .= '<p>Your scholarship application for <strong>' . htmlspecialchars((string) ($application['scholarship_name'] ?? 'this scholarship'), ENT_QUOTES, 'UTF-8') . '</strong> has been updated to <strong>' . htmlspecialchars($new_status, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
            if ($remarks !== '') {
                $body .= '<p><strong>Remarks:</strong> ' . nl2br(htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            $body .= '<p>Please log in to your scholarship portal for the latest details.</p>';
            $body .= '<p>Regards,<br>DVC Scholarship Hub</p>';

            $mail->Body = $body;
            $mail->AltBody = "Your scholarship application for " . ($application['scholarship_name'] ?? 'this scholarship') . " has been updated to {$new_status}." . ($remarks !== '' ? " Remarks: {$remarks}" : '');
            $mail->send();
        } catch (Throwable $mail_error) {
            $email_error = $mail_error->getMessage();
            error_log('Scholarship application status email failed: ' . $email_error);
        }
    }

    scholarship_api_response(200, [
        'success' => true,
        'message' => $new_status === 'Rejected' ? 'Application declined successfully' : 'Application updated successfully',
        'data' => [
            'application_id' => (int) $application_id,
            'previous_status' => $current_status,
            'new_status' => $new_status,
            'remarks' => $remarks !== '' ? $remarks : null,
            'auto_rejected_application_ids' => $auto_rejected_ids,
            'email_error' => $email_error,
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    scholarship_api_response(500, [
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>
