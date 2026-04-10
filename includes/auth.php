<?php
if (session_status() === PHP_SESSION_NONE) {
    // Use a separate session name for Admin pages to allow multitasking (Admin + Student login on same browser)
    if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
        session_name('scholarship_admin');
    }
    session_start();
}

require_once __DIR__ . '/db.php';

/**
 * Checks if the current user is logged in as an admin.
 *
 * @return bool
 */
function isAdminLoggedIn(): bool
{
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

/**
 * Alias for isAdminLoggedIn for consistency with other files.
 *
 * @return bool
 */
if (!function_exists('isAdmin')) {
    function isAdmin(): bool
    {
        return isAdminLoggedIn();
    }
}

/**
 * Fetches the total number of applicants.
 * Placeholder function.
 *
 * @return int
 */
function getTotalApplicants(): int
{
    global $pdo;
    // This counts the total number of applications submitted.
    return (int) $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
}

/**
 * Fetches the total number of applications with a 'Pending' status.
 *
 * @return int
 */
function getTotalPendingReviews(): int
{
    global $pdo;
    return (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Pending'")->fetchColumn();
}

/**
 * Fetches the total number of active scholarships.
 *
 * @return int
 */
function getTotalScholarships(): int
{
    global $pdo;
    return (int) $pdo->query("SELECT COUNT(*) FROM scholarships")->fetchColumn();
}