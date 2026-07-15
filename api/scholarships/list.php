<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(dirname(__DIR__));
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Fetch active scholarships with applicant counts
    $active_sql = "
        SELECT
            s.*,
            COUNT(CASE WHEN latest_apps.applicant_type = 'New' AND latest_apps.status NOT IN ('Rejected', 'Dropped') THEN 1 END) as new_applicants,
            COUNT(CASE WHEN latest_apps.applicant_type = 'Renewal' AND latest_apps.status NOT IN ('Rejected', 'Dropped') THEN 1 END) as renewal_applicants,
            (SELECT COUNT(*) FROM exam_questions eq WHERE eq.scholarship_id = s.id) as exam_question_count
        FROM scholarships s
        LEFT JOIN (
            SELECT a.scholarship_id, a.applicant_type, a.status
            FROM applications a
            INNER JOIN (
                SELECT student_id, scholarship_id, MAX(id) as max_id
                FROM applications
                GROUP BY student_id, scholarship_id
            ) latest ON a.id = latest.max_id
        ) latest_apps ON s.id = latest_apps.scholarship_id
        WHERE s.status IN ('active', 'inactive')
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ";

    $active_scholarships = $pdo->query($active_sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($active_scholarships as &$scholarship) {
        $capacity = getScholarshipCapacitySummary($pdo, (int) ($scholarship['id'] ?? 0), (int) ($scholarship['available_slots'] ?? 0));
        $scholarship['approved_count'] = $capacity['approved_count'];
        $scholarship['occupied_count'] = $capacity['occupied_count'];
        $scholarship['remaining_slots'] = $capacity['remaining_slots'];
        $scholarship['is_full'] = $capacity['is_full'];
    }
    unset($scholarship);

    // Fetch archived scholarships
    $archived_sql = "SELECT * FROM scholarships WHERE status = 'archived' ORDER BY created_at DESC";
    $archived_scholarships = $pdo->query($archived_sql)->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'active' => $active_scholarships,
            'archived' => $archived_scholarships
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
