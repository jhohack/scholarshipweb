<?php
// Utility functions for the Scholarship Portal

/**
 * Sanitize input data to prevent XSS and other attacks.
 *
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a specified URL.
 *
 * @param string $url
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Check if the user is logged in.
 *
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if the user is logged in as a student.
 *
 * @return bool
 */
function isStudent() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

/**
 * Check if the user is logged in as an admin.
 *
 * @return bool
 */
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
}

/**
 * Get the current user's role.
 *
 * @return string|null
 */
function getUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Flash a message to the session for one-time display.
 *
 * @param string $message
 */
function flashMessage($message) {
    $_SESSION['flash_message'] = $message;
}

/**
 * Retrieve and clear the flash message.
 *
 * @return string|null
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Validate email format.
 *
 * @param string $email
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate a unique token for CSRF protection.
 *
 * @return string
 */
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Verify CSRF token.
 *
 * @param string $token
 * @return bool
 */
if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Generates Bootstrap 5 pagination HTML.
 *
 * @param int $current_page The current page number.
 * @param int $total_pages The total number of pages.
 * @param array $params Additional query parameters to include in the links.
 * @return string The HTML for the pagination component.
 */
function generatePagination($current_page, $total_pages, $params = []) {
    if ($total_pages <= 1) {
        return '';
    }

    $query_string = http_build_query($params);
    $html = '<nav aria-label="Page navigation" class="mt-4 d-flex justify-content-center">';
    $html .= '<ul class="pagination">';

    // Previous button
    $prev_disabled = ($current_page <= 1) ? 'disabled' : '';
    $html .= "<li class='page-item {$prev_disabled}'><a class='page-link' href='?page=" . ($current_page - 1) . "&{$query_string}'>Previous</a></li>";

    // Page numbers
    for ($i = 1; $i <= $total_pages; $i++) {
        $active_class = ($i == $current_page) ? 'active' : '';
        $html .= "<li class='page-item {$active_class}'><a class='page-link' href='?page={$i}&{$query_string}'>{$i}</a></li>";
    }

    // Next button
    $next_disabled = ($current_page >= $total_pages) ? 'disabled' : '';
    $html .= "<li class='page-item {$next_disabled}'><a class='page-link' href='?page=" . ($current_page + 1) . "&{$query_string}'>Next</a></li>";

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Retrieves and displays a flash message in a Bootstrap alert.
 * This centralizes the flash message display logic.
 */
function displayFlashMessages() {
    $message = getFlashMessage();
    if ($message) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert" data-aos="fade-up">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

/**
 * Securely retrieves the application progress for the logged-in student.
 * This ensures students only see their own applications.
 *
 * @param PDO $pdo The database connection object.
 * @param int $user_id The ID of the logged-in user.
 * @return array An array of applications associated with the student.
 */
function getStudentApplications($pdo, $user_id) {
    // Get the student ID linked to the user account
    $stmt = $pdo->prepare("SELECT id FROM students WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student_id = $stmt->fetchColumn();

    if (!$student_id) {
        return [];
    }

    // Fetch applications for this specific student
    $sql = "SELECT a.*, s.name as scholarship_name, s.amount 
            FROM applications a 
            JOIN scholarships s ON a.scholarship_id = s.id 
            WHERE a.student_id = ? 
            ORDER BY a.submitted_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns a consistent display status for applications.
 * Maps 'Active' to 'Approved' for user-facing views.
 *
 * @param string $db_status The status stored in the database.
 * @return string The status to display to the user.
 */
function formatApplicationStatus($db_status) {
    $status_map = [
        'Active' => 'Approved',
        'Accepted' => 'Approved',
        'For Renewal' => 'For Renewal',
    ];
    return $status_map[$db_status] ?? $db_status;
}

/**
 * Checks if the session has expired due to inactivity.
 * 
 * @param int $timeout_duration Duration in seconds (default 30 mins).
 */
function checkSessionTimeout($timeout_duration = 3000) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        // Determine redirect URL based on context (Admin vs Public)
        $redirect_url = BASE_URL . "/public/login.php?timeout=1";
        
        // Check if the current script is inside the 'admin' directory or user was admin
        if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
            $redirect_url = BASE_URL . "/admin/login.php?timeout=1";
        }

        session_unset();
        session_destroy();
        header("Location: " . $redirect_url);
        exit();
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Checks for scholarships that have passed their end_of_term date and processes them.
 * Sets active scholars to 'For Renewal' and deactivates the scholarship.
 *
 * @param PDO $pdo The database connection object.
 * @return array An array containing counts of processed scholarships and students.
 */
function processExpiredScholarships($pdo) {
    $processed_scholarships = 0;
    $processed_students = 0;

    try {
        // Find active scholarships where the end_of_term date has passed.
        // Modified to only select scholarships that actually have Active students to expire.
        // This prevents the system from repeatedly processing the same scholarship if we don't set it to inactive.
        $stmt = $pdo->prepare("
            SELECT s.id FROM scholarships s 
            WHERE s.end_of_term IS NOT NULL AND s.end_of_term <= CURDATE() AND s.status = 'active'
            AND EXISTS (SELECT 1 FROM applications a WHERE a.scholarship_id = s.id AND a.status = 'Active')
        ");
        $stmt->execute();
        $expired_scholarships = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($expired_scholarships)) {
            return ['scholarships' => 0, 'students' => 0];
        }

        $pdo->beginTransaction();

        foreach ($expired_scholarships as $scholarship_id) {
            // Update applications from 'Active' to 'For Renewal'
            $app_update_stmt = $pdo->prepare("UPDATE applications SET status = 'For Renewal' WHERE scholarship_id = ? AND status = 'Active'");
            $app_update_stmt->execute([$scholarship_id]);
            $processed_students += $app_update_stmt->rowCount();

            // We do NOT set the scholarship to inactive, allowing renewals to proceed.
            $processed_scholarships++;
        }

        $pdo->commit();

        return ['scholarships' => $processed_scholarships, 'students' => $processed_students];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Log error silently in a production environment
        error_log("Error processing expired scholarships: " . $e->getMessage());
        return ['scholarships' => 0, 'students' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Get unread message count for the current user.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @return int
 */
function getUnreadMessageCount($pdo, $user_id) {
    try {
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
             $stmt = $pdo->prepare("
                SELECT COUNT(m.id) 
                FROM messages m 
                JOIN conversations c ON m.conversation_id = c.id 
                WHERE c.student_user_id = ? AND m.sender_id != ? AND m.is_read = 0
            ");
            $stmt->execute([$user_id, $user_id]);
            return (int)$stmt->fetchColumn();
        } elseif (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'staff')) {
             $stmt = $pdo->query("
                SELECT COUNT(m.id) 
                FROM messages m 
                JOIN conversations c ON m.conversation_id = c.id 
                WHERE m.sender_id = c.student_user_id AND m.is_read = 0
            ");
            return (int)$stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        return 0;
    }
    return 0;
}

?>