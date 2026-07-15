<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$scholarship_id = filter_input(INPUT_GET, 'scholarship_id', FILTER_SANITIZE_NUMBER_INT);
$status_filter = $_GET['status_filter'] ?? 'all';
$applicant_type_filter = $_GET['applicant_type_filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?? 1);
$per_page = 50;
$offset = ($page - 1) * $per_page;

if (!$scholarship_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or missing scholarship ID'
    ]);
    exit;
}

try {
    // Verify scholarship exists
    $scholarship_check = $pdo->prepare("SELECT id, name FROM scholarships WHERE id = ? LIMIT 1");
    $scholarship_check->execute([$scholarship_id]);
    $scholarship = $scholarship_check->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Scholarship not found'
        ]);
        exit;
    }

    // Build query with filters
    $where_clauses = ["a.scholarship_id = ?"];
    $params = [$scholarship_id];

    if ($status_filter !== 'all') {
        $where_clauses[] = "a.status = ?";
        $params[] = $status_filter;
    }

    if ($applicant_type_filter !== 'all') {
        $where_clauses[] = "a.applicant_type = ?";
        $params[] = $applicant_type_filter;
    }

    $search = trim((string) $search);
    $search_length = function_exists('mb_strlen') ? mb_strlen($search, 'UTF-8') : strlen($search);
    $effective_search = $search_length >= 2 ? $search : '';

    if ($effective_search !== '') {
        $search_operator = (defined('DB_DRIVER') && DB_DRIVER === 'pgsql') ? 'ILIKE' : 'LIKE';
        $search_tokens = preg_split('/[\s,]+/', $effective_search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (!empty($search_tokens)) {
            foreach ($search_tokens as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }

                $prefix_like = $token . '%';
                $where_clauses[] = "(
                    s.school_id_number {$search_operator} ?
                    OR s.student_name {$search_operator} ?
                    OR s.email {$search_operator} ?
                    OR u.first_name {$search_operator} ?
                    OR u.middle_name {$search_operator} ?
                    OR u.last_name {$search_operator} ?
                )";
                $params[] = $prefix_like;
                $params[] = $prefix_like;
                $params[] = $prefix_like;
                $params[] = $prefix_like;
                $params[] = $prefix_like;
                $params[] = $prefix_like;
            }
        }
    }

    $where_sql = implode(' AND ', $where_clauses);

    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM applications a
                  JOIN students s ON a.student_id = s.id
                  LEFT JOIN users u ON s.user_id = u.id
                  WHERE $where_sql";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Get applications with pagination
    $sql = "SELECT
                a.id,
                a.student_id,
                a.scholarship_id,
                a.status,
                a.applicant_type,
                a.submitted_at,
                a.scholarship_percentage,
                a.scholarship_amount,
                a.gwa,
                a.program,
                a.year_level,
                a.remarks,
                s.student_name,
                s.school_id_number,
                s.email,
                s.phone
            FROM applications a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN users u ON s.user_id = u.id
            WHERE $where_sql
            ORDER BY a.submitted_at DESC
            LIMIT ? OFFSET ?";

    $params[] = $per_page;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get status counts
    $count_sql = "SELECT
                    COUNT(CASE WHEN status NOT IN ('Rejected', 'Dropped') AND applicant_type = 'New' THEN 1 END) as new_pending,
                    COUNT(CASE WHEN status NOT IN ('Rejected', 'Dropped') AND applicant_type = 'Renewal' THEN 1 END) as renewal_pending,
                    COUNT(CASE WHEN status = 'Approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'Under Review' THEN 1 END) as under_review,
                    COUNT(CASE WHEN status = 'Rejected' THEN 1 END) as declined,
                    COUNT(CASE WHEN status = 'Incomplete' THEN 1 END) as incomplete,
                    COUNT(*) as total
                FROM applications
                WHERE scholarship_id = ?";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute([$scholarship_id]);
    $counts = $count_stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'scholarship' => $scholarship,
            'applications' => $applications,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page)
            ],
            'counts' => $counts
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
