<?php
$base_path = dirname(__DIR__); // This resolves to C:\xampp\htdocs\websitescholarship\scholarship-portal
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

// --- Applicant Type Filtering for Logged-in Users ---
$user_is_logged_in = isset($_SESSION['user_id']);
$student_type = null;
$has_active_scholarship = false;

// --- Filtering Logic ---
$search = $_GET['search'] ?? '';
$categories = isset($_GET['categories']) && is_array($_GET['categories']) ? $_GET['categories'] : [];
$sql = "SELECT s.* FROM scholarships s";
$whereClauses = [];
$params = [];

// Always filter for active scholarships
$whereClauses[] = "s.status = 'active'";

// Filter out scholarships that require an exam but have no questions
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
    // Merge accumulated params with categories
    if (!empty($categories)) {
        $params = array_merge($params, $categories);
    }
    $stmt->execute($params);
    $scholarships = $stmt->fetchAll();

} catch (PDOException $e) {
    // In a real app, log this error
    $scholarships = [];
    $distinct_categories = [];
    // You might want to display an error message to the user.
}

$scholarship_categories = [
    'Yeomchang Scholarship',
    'Student assistant',
    'Academic scholarship',
    'Employees immediate family',
    'Pastors Kids',
    'Church leaders',
    'Public teachers’ kid',
    'Public teachers’ workers',
    'DVC senior High Graduates',
    'Siblings’ scholarship',
    'SSC officer scholarship'
];

$page_title = 'All Scholarships';
// The header is now inlined as per the request.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'DVC Scholarship Hub'; ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- AOS (Animate on Scroll) CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Swiper.js CSS -->
    <link rel="stylesheet" href="https://unpkg.com/swiper/swiper-bundle.min.css" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include $base_path . '/public/header.php'; ?>
    <main>
        <!-- Page Header Section -->
        <section class="page-header text-center py-5 bg-light position-relative">
            <div class="container position-relative" data-aos="fade-up">
                <h1 class="display-4 fw-bold">Explore Scholarships</h1>
                <p class="lead text-muted col-lg-8 mx-auto">Filter and find the perfect scholarship to support your academic journey.</p>
            </div>
            <div class="section-divider section-divider-angle">
                <svg viewBox="0 0 1440 80" preserveAspectRatio="none" style="height: 80px; width: 100%;">
                    <path d="M0,80 L1440,0 L1440,80 L0,80 Z" class="shape-fill" style="fill: #ffffff;"></path>
                </svg>
            </div>
        </section>
    <div class="container py-5">
    <!-- Reminder Alert -->
    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center mb-4" role="alert" data-aos="fade-down">
        <i class="bi bi-info-circle-fill fs-4 me-3 text-primary"></i>
        <div>
            <h5 class="alert-heading fw-bold mb-1">Important Reminder</h5>
            <p class="mb-0 small">Please note that each student is limited to <strong>one scholarship application</strong> only. Ensure you review the qualifications carefully before applying.</p>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-3">
            <!-- Filter Sidebar -->
            <div class="filter-sidebar p-4 rounded bg-white shadow-sm border" data-aos="fade-right">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-sliders me-2"></i>Filter</h5>
                    <a href="scholarships.php" class="text-decoration-none small text-muted">Clear All</a>
                </div>
                <hr class="my-2 mb-4 opacity-25">
                <form action="scholarships.php" method="GET">
                    <!-- Categories -->
                    <div class="mb-0">
                        <label class="form-label fw-bold text-uppercase small text-muted mb-3" style="letter-spacing: 0.5px;">Categories</label>
                        <div class="d-flex flex-column gap-2">
                        <?php foreach ($scholarship_categories as $category): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="categories[]" value="<?php echo htmlspecialchars($category); ?>" id="cat-<?php echo htmlspecialchars(str_replace(' ', '-', $category)); ?>" <?php echo in_array($category, $categories) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cat-<?php echo htmlspecialchars(str_replace(' ', '-', $category)); ?>">
                                    <?php echo htmlspecialchars($category); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-9" data-aos="fade-left" data-aos-delay="100">
            <!-- Scholarship Listings -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="fw-bold mb-0">Available Scholarships</h2>
                <span id="results-count" class="badge bg-primary-soft text-primary fs-6 rounded-pill"><?php echo count($scholarships); ?> Found</span>
            </div>
            <div class="row g-4" id="scholarship-listings-container">
                <?php if (empty($scholarships)): ?>
                    <div class="col-12">
                        <div class="no-results-card" data-aos="fade-up">
                            <div class="icon"><i class="bi bi-search-heart"></i></div>
                            <h4 class="fw-bold">No Scholarships Found</h4>
                            <p class="text-muted">We couldn't find any scholarships matching your criteria. <br>Try adjusting your filters or check back later!</p>
                            <a href="scholarships.php" class="btn btn-primary mt-3">Clear Filters</a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($scholarships as $index => $scholarship): ?>
                        <div class="col-md-6 col-lg-4 d-flex align-items-stretch" data-aos="fade-up" data-aos-delay="<?php echo ($index % 3) * 100; ?>">
                            <?php 
                            // Inlining scholarship_card.php logic to modify the link
                            $card_link = 'scholarship-details.php?id=' . $scholarship['id'];
                            $card_button_text = 'View Details';
                            include 'scholarship_card.php'; 
                            ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.querySelector('.filter-sidebar form');
    const scholarshipContainer = document.getElementById('scholarship-listings-container');
    const resultsCountBadge = document.getElementById('results-count');
    let debounceTimeout;

    function fetchScholarships() {
        const formData = new FormData(filterForm);
        // Create a query string from the form data
        const params = new URLSearchParams(formData).toString();
        
        // Update the browser's URL without reloading the page for shareable links
        history.pushState(null, '', `?${params}`);

        // Display a loading spinner to provide user feedback
        scholarshipContainer.innerHTML = `
            <div class="col-12 text-center py-5">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>`;

        // Fetch the filtered results from a dedicated search script
        fetch(`search_scholarships.php?${params}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // Update the scholarship list and the results count
                scholarshipContainer.innerHTML = data.html;
                resultsCountBadge.textContent = `${data.count} Found`;
                // Re-initialize the AOS (Animate on Scroll) library for new elements
                if (window.AOS) {
                    AOS.refresh();
                }
            })
            .catch(error => {
                console.error('Error fetching scholarships:', error);
                scholarshipContainer.innerHTML = `
                    <div class="col-12">
                        <div class="no-results-card">
                            <div class="icon"><i class="bi bi-wifi-off"></i></div>
                            <h4 class="fw-bold">Something Went Wrong</h4>
                            <p class="text-muted">Could not load scholarships. Please check your connection and try again.</p>
                        </div>
                    </div>`;
            });
    }

    // Listen for changes on the form (checking a box)
    filterForm.addEventListener('change', function(e) {
        clearTimeout(debounceTimeout);
        // Debounce the fetch call to avoid excessive requests while typing
        debounceTimeout = setTimeout(fetchScholarships, 300);
    });

    // Prevent the form from doing a full page reload if the user hits Enter
    filterForm.addEventListener('submit', e => e.preventDefault());
});
</script>
<?php include $base_path . '/includes/footer.php'; ?>