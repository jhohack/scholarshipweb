<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy the session to log the user out
session_unset();
session_destroy();

// Redirect to the homepage
header("Location: index.php");
exit();
?>