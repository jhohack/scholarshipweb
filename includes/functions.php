<?php
// Utility functions for the Scholarship Portal
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/cache.php';

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

if (!function_exists('clearCurrentSessionCookie')) {
    function clearCurrentSessionCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $params = session_get_cookie_params();
        $path = $params['path'] ?? '/';
        $domain = $params['domain'] ?? '';
        $secure = (bool) ($params['secure'] ?? false);
        $httpOnly = (bool) ($params['httponly'] ?? true);

        setcookie(session_name(), '', time() - 3600, $path, $domain, $secure, $httpOnly);

        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httpOnly,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        unset($_COOKIE[session_name()]);
    }
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
 * @param string $type Bootstrap alert type, e.g. success, danger, warning, info
 */
function flashMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/**
 * Retrieve and clear the flash message payload.
 *
 * @return array{message:string,type:string}|null
 */
function getFlashMessageData() {
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }

    $message = $_SESSION['flash_message'];
    $type = $_SESSION['flash_type'] ?? 'success';

    unset($_SESSION['flash_message'], $_SESSION['flash_type']);

    return [
        'message' => is_string($message) ? $message : (string) $message,
        'type' => is_string($type) && $type !== '' ? $type : 'success',
    ];
}

/**
 * Retrieve and clear the flash message.
 *
 * @return string|null
 */
function getFlashMessage() {
    $flash = getFlashMessageData();
    return $flash['message'] ?? null;
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
    $flash = getFlashMessageData();
    if (!$flash) {
        return;
    }

    $type = $flash['type'] ?? 'success';
    $validTypes = ['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'light', 'dark'];
    if (!in_array($type, $validTypes, true)) {
        $type = 'success';
    }

    echo '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . ' alert-dismissible fade show" role="alert" data-aos="fade-up">'
        . htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8')
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
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
 * Turn a stored filename into a cleaner human-readable label.
 *
 * @param string|null $file_name
 * @param string $fallback
 * @return string
 */
if (!function_exists('formatDocumentDisplayName')) {
    function formatDocumentDisplayName(?string $file_name, string $fallback = 'Document'): string
    {
        $raw = trim((string) $file_name);
        if ($raw === '') {
            return $fallback;
        }

        $base = trim((string) pathinfo($raw, PATHINFO_FILENAME));
        if ($base === '') {
            return $fallback;
        }

        $tokens = preg_split('/[._\-\s]+/', $base, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return $fallback;
        }

        $cleanTokens = [];
        $started = false;
        foreach ($tokens as $token) {
            $normalized = preg_replace('/[^a-z0-9]/i', '', (string) $token);
            if ($normalized === '') {
                continue;
            }

            if (!$started) {
                $looksLikeNoise = preg_match('/^(doc|document|file|scan|upload|img|image)$/i', $normalized)
                    || preg_match('/^\d+$/', $normalized)
                    || preg_match('/^[a-f0-9]{8,}$/i', $normalized);

                if ($looksLikeNoise) {
                    continue;
                }

                $started = true;
            }

            $cleanTokens[] = $token;
        }

        if (empty($cleanTokens)) {
            return $fallback;
        }

        $display = trim(preg_replace('/\s+/', ' ', implode(' ', $cleanTokens)) ?? '');
        if ($display === '') {
            return $fallback;
        }

        if (function_exists('mb_convert_case')) {
            $display = mb_convert_case($display, MB_CASE_TITLE, 'UTF-8');
        } else {
            $display = ucwords(strtolower($display));
        }

        $acronyms = ['IT', 'GWA', 'PDF', 'BS', 'BEED', 'BA', 'AB', 'SHS', 'JHS', 'TVL', 'STEM', 'ICT', 'NSTP'];
        foreach ($acronyms as $acronym) {
            $display = preg_replace(
                '/\b' . preg_quote(ucfirst(strtolower($acronym)), '/') . '\b/u',
                $acronym,
                $display
            ) ?? $display;
        }

        $display = trim(preg_replace('/\s+/', ' ', $display) ?? '');
        if ($display === '') {
            return $fallback;
        }

        if (function_exists('mb_strimwidth')) {
            $display = mb_strimwidth($display, 0, 72, '…', 'UTF-8');
        } elseif (strlen($display) > 72) {
            $display = substr($display, 0, 69) . '...';
        }

        return $display;
    }
}

/**
 * Retrieve document re-upload request rows for a student or a single application.
 *
 * @param PDO $pdo
 * @param int|null $student_id
 * @param int|null $application_id
 * @param array<int, string>|null $statuses Optional status filter list.
 * @return array<int, array<string, mixed>>
 */
if (!function_exists('getDocumentReuploadRequestRows')) {
    function getDocumentReuploadRequestRows(PDO $pdo, ?int $student_id = null, ?int $application_id = null, ?array $statuses = null): array
    {
        if (function_exists('dbEnsureDocumentReviewSchema')) {
            dbEnsureDocumentReviewSchema($pdo);
        }

        $where = [];
        $params = [];

        if ($student_id !== null) {
            $where[] = 'a.student_id = ?';
            $params[] = $student_id;
        }

        if ($application_id !== null) {
            $where[] = 'a.id = ?';
            $params[] = $application_id;
        }

        if ($statuses !== null) {
            $statuses = array_values(array_filter(array_map(static function ($status) {
                $status = trim((string) $status);
                return $status !== '' ? $status : null;
            }, $statuses)));

            if (empty($statuses)) {
                return [];
            }

            $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "rr.status IN ({$statusPlaceholders})";
            foreach ($statuses as $status) {
                $params[] = $status;
            }
        }

        $sql = "
            SELECT
                rr.id as request_id,
                rr.application_id,
                rr.document_id,
                rr.note,
                rr.status as request_status,
                rr.created_at,
                rr.resolved_at,
                a.status as application_status,
                a.updated_at as application_updated_at,
                s.name as scholarship_name,
                d.file_name,
                d.file_path,
                d.uploaded_at
            FROM application_reupload_requests rr
            JOIN applications a ON rr.application_id = a.id
            JOIN scholarships s ON a.scholarship_id = s.id
            JOIN documents d ON rr.document_id = d.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY rr.created_at DESC, rr.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Retrieves open document re-upload requests for a student or a single application.
 *
 * @param PDO $pdo
 * @param int|null $student_id
 * @param int|null $application_id
 * @return array
 */
function getOpenDocumentReuploadRequests(PDO $pdo, ?int $student_id = null, ?int $application_id = null): array
{
    $rows = getDocumentReuploadRequestRows($pdo, $student_id, $application_id, ['pending']);

    if (!$rows) {
        return [];
    }

    $grouped = [];
    foreach ($rows as $row) {
        $appId = (int) ($row['application_id'] ?? 0);
        if ($appId <= 0) {
            continue;
        }

        if (!isset($grouped[$appId])) {
            $grouped[$appId] = [
                'application_id' => $appId,
                'scholarship_name' => $row['scholarship_name'] ?? '',
                'application_status' => $row['application_status'] ?? '',
                'application_updated_at' => $row['application_updated_at'] ?? null,
                'note' => '',
                'count' => 0,
                'created_at' => $row['created_at'] ?? null,
                'documents' => [],
            ];
        }

        $note = trim((string) ($row['note'] ?? ''));
        if ($grouped[$appId]['note'] === '' && $note !== '') {
            $grouped[$appId]['note'] = $note;
        }

        $grouped[$appId]['count']++;
        $grouped[$appId]['documents'][] = [
            'request_id' => (int) ($row['request_id'] ?? 0),
            'document_id' => (int) ($row['document_id'] ?? 0),
            'document_name' => formatDocumentDisplayName($row['file_name'] ?? ''),
            'document_path' => $row['file_path'] ?? '',
            'note' => $note,
            'created_at' => $row['created_at'] ?? null,
            'resolved_at' => $row['resolved_at'] ?? null,
        ];
    }

    return array_values($grouped);
}

if (!function_exists('getStudentReviewAction')) {
    function getStudentReviewAction(PDO $pdo, int $student_id): ?array
    {
        if ($student_id <= 0) {
            return null;
        }

        $openRequests = getOpenDocumentReuploadRequests($pdo, $student_id);
        if (!empty($openRequests)) {
            $request = $openRequests[0];

            return [
                'mode' => 'request',
                'application_id' => (int) ($request['application_id'] ?? 0),
                'scholarship_name' => $request['scholarship_name'] ?? 'Your application',
                'application_status' => $request['application_status'] ?? 'Under Review',
                'count' => (int) ($request['count'] ?? 0),
                'note' => trim((string) ($request['note'] ?? '')),
            ];
        }

        $stmt = $pdo->prepare("
            SELECT a.id as application_id, a.status as application_status, s.name as scholarship_name
            FROM applications a
            JOIN scholarships s ON a.scholarship_id = s.id
            WHERE a.student_id = ? AND a.status = 'Under Review'
            ORDER BY COALESCE(a.updated_at, a.submitted_at) DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->execute([$student_id]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            return null;
        }

        return [
            'mode' => 'review',
            'application_id' => (int) ($application['application_id'] ?? 0),
            'scholarship_name' => $application['scholarship_name'] ?? 'Your application',
            'application_status' => $application['application_status'] ?? 'Under Review',
            'count' => 0,
            'note' => '',
        ];
    }
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
        clearCurrentSessionCookie();
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
        $currentDateSql = dbCurrentDateSql($pdo);
        $stmt = $pdo->prepare("
            SELECT s.id FROM scholarships s 
            WHERE s.end_of_term IS NOT NULL AND s.end_of_term <= {$currentDateSql} AND s.status = 'active'
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
