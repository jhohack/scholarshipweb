<?php
if (session_status() === PHP_SESSION_NONE) {
    // Enable URL-based session IDs to allow multiple accounts in the same browser (multitasking)
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    session_name('scholarship_admin');
    session_start();
}

// Destroy the session to log the user out
session_unset();
session_destroy();

// Redirect to the admin login page
header("Location: login.php");
exit();
?>