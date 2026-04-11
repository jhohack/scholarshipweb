<?php
if (session_status() === PHP_SESSION_NONE) {
    // Keep admin and student logins separate by using a dedicated admin session.
    session_name('scholarship_admin');
    session_start();
}
require_once dirname(__DIR__) . '/includes/functions.php';

// Destroy the session to log the user out
session_unset();
session_destroy();
clearCurrentSessionCookie();

// Redirect to the admin login page
header("Location: login.php");
exit();
?>
