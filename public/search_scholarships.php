<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
portalSendPageCacheHeaders(180, isLoggedIn());

// --- Filtering Logic ---
$search = $_GET['search'] ?? '';
$categories = isset($_GET['categories']) && is_array($_GET['categories']) ? $_GET['categories'] : [];
$categories_for_cache = $categories;
sort($categories_for_cache);
$cache_key = 'public.search_scholarships:' . sha1(json_encode([$search, $categories_for_cache]));

$response = portalCacheRemember($cache_key, 180, function () use ($pdo, $search, $categories) {
    $sql = "SELECT
                s.id,
                s.name,
                s.category,
                s.amount,
                s.amount_type,
                s.deadline,
                s.available_slots,
                s.description
            FROM scholarships s";
    $whereClauses = [];
    $params = [];

    $whereClauses[] = "s.status = 'active'";
    $whereClauses[] = "(s.requires_exam = 0 OR (s.requires_exam = 1 AND EXISTS (SELECT 1 FROM exam_questions eq WHERE eq.scholarship_id = s.id)))";

    if (!empty($search)) {
        $whereClauses[] = "(s.name LIKE ? OR s.description LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if (!empty($categories)) {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $whereClauses[] = "s.category IN ($placeholders)";
    }

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $sql .= " ORDER BY s.deadline ASC";

    try {
        $stmt = $pdo->prepare($sql);
        if (!empty($categories)) {
            $params = array_merge($params, $categories);
        }
        $stmt->execute($params);
        $scholarships = $stmt->fetchAll();
    } catch (PDOException $e) {
        $scholarships = [];
    }

    $html = '';
    $count = count($scholarships);

    if (empty($scholarships)) {
        $html = '
        <div class="col-12">
            <div class="no-results-card" data-aos="fade-up">
                <div class="icon"><i class="bi bi-search-heart"></i></div>
                <h4 class="fw-bold">No Scholarships Found</h4>
                <p class="text-muted">We couldn\'t find any scholarships matching your criteria. <br>Try adjusting your filters or check back later!</p>
                <a href="scholarships.php" class="btn btn-primary mt-3">Clear Filters</a>
            </div>
        </div>';
    } else {
        foreach ($scholarships as $index => $scholarship) {
            ob_start();
            $card_link = 'scholarship-details.php?id=' . $scholarship['id'];
            $card_button_text = 'View Details';
            echo '<div class="col-md-6 col-lg-4 d-flex align-items-stretch" data-aos="fade-up" data-aos-delay="' . (($index % 3) * 100) . '">';
            include 'scholarship_card.php';
            echo '</div>';
            $html .= ob_get_clean();
        }
    }

    return ['html' => $html, 'count' => $count];
});

header('Content-Type: application/json');
echo json_encode($response);
exit();
?>
