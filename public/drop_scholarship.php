<?php
$base_path = dirname(__DIR__);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

// Ensure only students can access this
if (!isStudent()) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);
    $user_id = $_SESSION['user_id'];

    if ($application_id) {
        try {
            // 1. Get Student ID
            $stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $student_id = $stmt->fetchColumn();

            if ($student_id) {
                // 2. Verify the application belongs to the student
                $check_stmt = $pdo->prepare("
                    SELECT id, status 
                    FROM applications 
                    WHERE id = ? AND student_id = ?
                ");
                $check_stmt->execute([$application_id, $student_id]);
                $application = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($application) {
                    // 3. Update status to 'Dropped'
                    // This status allows the student to apply for new scholarships immediately
                    $update_stmt = $pdo->prepare("UPDATE applications SET status = 'Dropped', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $update_stmt->execute([$application_id]);

                    // Update user to New Applicant so they can apply again as new
                    $user_update = $pdo->prepare("UPDATE users SET student_type = 'New Applicant' WHERE id = ?");
                    $user_update->execute([$user_id]);

                    // Redirect with success
                    header("Location: ../student/dashboard.php?msg=dropped");
                    exit();
                }
            }
        } catch (PDOException $e) {
            error_log("Drop Error: " . $e->getMessage());
        }
    }
}

// Fallback redirect if something failed
header("Location: ../student/dashboard.php?error=drop_failed");
exit();
