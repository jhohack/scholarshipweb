<?php
if (session_status() === PHP_SESSION_NONE) {
    // Start with the admin session name to access admin credentials
    session_name('scholarship_admin');
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// 1. Verify the current user is an administrator
if (!isAdmin()) {
    die("Access Denied. You must be an administrator to perform this action.");
}

$student_user_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$student_user_id) {
    die("Invalid student ID provided.");
}

try {
    // 2. Fetch the student's details to impersonate
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, role FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$student_user_id]);
    $student_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student_user) {
        die("Student user not found or the specified user is not a student.");
    }

    // 3. Store the admin's original session details for the return trip
    $admin_original_session = [
        'user_id' => $_SESSION['user_id'],
        'name' => $_SESSION['name'],
        'role' => $_SESSION['role'],
        'is_admin' => true,
        'session_name' => session_name() // Store the admin session name ('scholarship_admin')
    ];

    // 4. Destroy the current admin session completely
    session_destroy();

    // 5. Start a new, clean session for the student (uses default session name)
    session_start();
    
    // 6. Populate the new session with the student's data AND the stored admin data
    $_SESSION['user_id'] = $student_user['id'];
    $_SESSION['name'] = trim($student_user['first_name'] . ' ' . $student_user['last_name']);
    $_SESSION['role'] = $student_user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['admin_original_session'] = $admin_original_session; // This is the key to returning

    // 7. Redirect to the student's dashboard
    header("Location: ../student/dashboard.php");
    exit();

} catch (PDOException $e) {
    die("Database error during impersonation: " . $e->getMessage());
}