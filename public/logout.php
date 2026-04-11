<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/includes/functions.php';

// Destroy the session to log the user out
session_unset();
session_destroy();
clearCurrentSessionCookie();

// Redirect to the homepage
header("Location: index.php");
exit();
?>
