<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require $base_path . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$scholarship_id = filter_input(INPUT_POST, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid scholarship ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name FROM scholarships WHERE id = ? LIMIT 1");
    $stmt->execute([$scholarship_id]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Scholarship not found']);
        exit;
    }

    // Start transaction
    $pdo->beginTransaction();

    // 1. Get all active approved applications
    $approved_stmt = $pdo->prepare("
        SELECT a.id, a.student_id, s.email, s.student_name
        FROM applications a
        JOIN students s ON a.student_id = s.id
        WHERE a.scholarship_id = ? AND a.status = 'Approved'
    ");
    $approved_stmt->execute([$scholarship_id]);
    $approved_applications = $approved_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Get all pending/under review applications
    $pending_stmt = $pdo->prepare("
        SELECT id
        FROM applications
        WHERE scholarship_id = ? AND status IN ('Pending', 'Under Review')
    ");
    $pending_stmt->execute([$scholarship_id]);
    $pending_applications = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Update approved applications to 'For Renewal'
    $update_approved = $pdo->prepare("
        UPDATE applications
        SET status = 'For Renewal', applicant_type = 'Renewal'
        WHERE scholarship_id = ? AND status = 'Approved'
    ");
    $update_approved->execute([$scholarship_id]);

    // 4. Reject all pending applications
    $reject_pending = $pdo->prepare("
        UPDATE applications
        SET status = 'Rejected'
        WHERE scholarship_id = ? AND status IN ('Pending', 'Under Review')
    ");
    $reject_pending->execute([$scholarship_id]);

    // 5. Update scholarship status
    $update_scholarship = $pdo->prepare("
        UPDATE scholarships
        SET accepting_new_applicants = 0, end_of_term = NOW()
        WHERE id = ?
    ");
    $update_scholarship->execute([$scholarship_id]);

    $pdo->commit();

    // 6. Send emails (after successful transaction)
    $email_count = 0;
    try {
        foreach ($approved_applications as $app) {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = SMTP_HOST;
            $mailer->SMTPAuth = true;
            $mailer->Username = SMTP_USER;
            $mailer->Password = SMTP_PASS;
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port = SMTP_PORT;

            $mailer->setFrom(SENDER_EMAIL, SENDER_NAME);
            $mailer->addAddress($app['email'], $app['student_name']);
            $mailer->isHTML(true);
            $mailer->Subject = "Scholarship Term Ended - {$scholarship['name']}";
            $mailer->Body = "
                <p>Dear {$app['student_name']},</p>
                <p>The current term of the {$scholarship['name']} scholarship has ended.</p>
                <p>Your status has been updated to 'For Renewal' for the next application cycle.</p>
                <p>If you have any questions, please contact the scholarship office.</p>
            ";

            if ($mailer->send()) {
                $email_count++;
            }
        }
    } catch (Exception $e) {
        // Log email errors but don't fail the API response
        error_log("Email sending failed: " . $e->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Scholarship term ended successfully',
        'data' => [
            'scholarship_id' => $scholarship_id,
            'scholarship_name' => $scholarship['name'],
            'converted_to_renewal' => count($approved_applications),
            'rejected_pending' => count($pending_applications),
            'emails_sent' => $email_count
        ]
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
