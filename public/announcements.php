<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';

checkSessionTimeout();

$page_title = 'Announcements';

try {
    $stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($announcements as &$ann) {
        $stmt_att = $pdo->prepare("SELECT file_path, file_name FROM announcement_attachments WHERE announcement_id = ?");
        $stmt_att->execute([$ann['id']]);
        $ann['attachments'] = $stmt_att->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ann);
} catch (PDOException $e) {
    $announcements = [];
    error_log("Public announcements page error: " . $e->getMessage());
}

$active_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - DVC Scholarship Hub</title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Professional UI/UX Overhaul */
        body {
            background-color: #f8f9fa;
        }
        
        /* Modern Header */
        .page-header-modern {
            background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%);
            color: white;
            padding: 5rem 0 8rem; /* Extra padding bottom for overlap */
            margin-bottom: -4rem; /* Negative margin to pull content up */
            border-radius: 0 0 50% 50% / 4%;
            position: relative;
            overflow: hidden;
        }
        
        .page-header-modern::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url('assets/images/pattern.png'); /* Optional texture */
            opacity: 0.1;
        }

        /* Sidebar Navigation */
        .announcement-sidebar {
            position: sticky;
            top: 100px;
            z-index: 10;
        }

        .list-group-custom .list-group-item {
            border: none;
            margin-bottom: 0.75rem;
            border-radius: 12px !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 1.25rem;
            background: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            border-left: 5px solid transparent;
        }

        .list-group-custom .list-group-item:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.05);
            border-left-color: #dee2e6;
        }

        .list-group-custom .list-group-item.active {
            background: white;
            color: var(--bs-primary);
            border-left-color: var(--bs-primary);
            box-shadow: 0 10px 25px rgba(13, 110, 253, 0.15);
            transform: scale(1.02);
        }
        
        .list-group-custom .list-group-item.active .text-muted {
            color: #6c757d !important;
        }

        /* Content Card */
        .announcement-content-card {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: 0 15px 35px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.02);
            min-height: 600px;
            position: relative;
        }

        /* Gallery Images */
        .gallery-img-wrapper {
            overflow: hidden;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: zoom-in;
        }
        .gallery-img-wrapper:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        .gallery-img-wrapper img {
            transition: transform 0.5s ease;
        }
        .gallery-img-wrapper:hover img {
            transform: scale(1.05);
        }

        /* Animations */
        .fade-in-up {
            animation: fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<section class="page-header-modern text-center">
    <div class="container position-relative" data-aos="zoom-in">
        <h1 class="display-4 fw-bold mb-3">Announcements & Updates</h1>
        <p class="lead opacity-75 col-lg-8 mx-auto">Stay connected with the latest news, deadlines, and opportunities from the DVC Scholarship Hub.</p>
    </div>
</section>

<main class="container py-5 position-relative" style="z-index: 2;">
    <?php if (empty($announcements)): ?>
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center py-5">
                <div class="card border-0 shadow-sm p-5 rounded-4" data-aos="fade-up">
                    <div class="mb-3 text-muted opacity-25">
                        <i class="bi bi-bell-slash-fill display-1"></i>
                    </div>
                    <h2 class="fw-bold text-secondary">No Active Announcements</h2>
                    <p class="text-muted">There are no announcements at this time. Please check back later!</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="announcement-sidebar" data-aos="fade-right">
                    <h5 class="fw-bold mb-4 text-secondary text-uppercase small ls-1">Recent Updates</h5>
                    <div class="list-group list-group-custom" id="announcement-list-titles" role="tablist">
                        <?php foreach ($announcements as $index => $item): 
                            $is_active = ($active_id > 0 && $item['id'] == $active_id) || ($active_id == 0 && $index === 0);
                        ?>
                            <a class="list-group-item list-group-item-action <?php echo $is_active ? 'active' : ''; ?>" data-bs-toggle="list" href="#announcement-<?php echo $item['id']; ?>" role="tab">
                                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 fw-bold text-truncate"><?php echo htmlspecialchars($item['title']); ?></h6>
                                    <?php if ($index === 0): ?><span class="badge bg-danger rounded-pill ms-2" style="font-size: 0.6rem;">NEW</span><?php endif; ?>
                                </div>
                                <small class="text-muted d-flex align-items-center">
                                    <i class="bi bi-calendar2-event me-2" style="font-size: 0.8rem;"></i>
                                    <?php echo date('M d, Y', strtotime($item['created_at'])); ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-8" data-aos="fade-left" data-aos-delay="200">
                <div class="tab-content announcement-content-card">
                    <?php foreach ($announcements as $index => $item): 
                        $is_active = ($active_id > 0 && $item['id'] == $active_id) || ($active_id == 0 && $index === 0);
                    ?>
                        <?php
                        // Organize attachments into images and files
                        $images = [];
                        $files = [];
                        if (!empty($item['attachments'])) {
                            foreach ($item['attachments'] as $att) {
                                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $att['file_path'])) {
                                    $images[] = $att;
                                } else {
                                    $files[] = $att;
                                }
                            }
                        }
                        ?>
                        <div class="tab-pane fade <?php echo $is_active ? 'show active' : ''; ?> fade-in-up" id="announcement-<?php echo $item['id']; ?>" role="tabpanel">
                            <div class="d-flex align-items-center mb-4 pb-3 border-bottom">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 me-3">
                                    <i class="bi bi-megaphone-fill fs-4"></i>
                                </div>
                                <div>
                                    <h2 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($item['title']); ?></h2>
                                    <div class="text-muted small">
                                        Posted on <?php echo date('F j, Y', strtotime($item['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="content-body fs-5 text-secondary mb-5" style="line-height: 1.8; text-align: justify;">
                                <?php echo nl2br(htmlspecialchars($item['content'])); ?>
                            </div>

                            <?php if (!empty($images)): ?>
                                <h6 class="fw-bold mb-3 text-uppercase text-muted small ls-1"><i class="bi bi-images me-2"></i>Gallery</h6>
                                <div class="row g-3 mb-4">
                                    <?php foreach ($images as $img): ?>
                                        <div class="<?php echo count($images) === 1 ? 'col-12' : (count($images) === 2 ? 'col-md-6' : 'col-md-4'); ?>">
                                            <div class="ratio ratio-16x9 gallery-img-wrapper" onclick="showImageModal('<?php echo htmlspecialchars($img['file_path']); ?>')">
                                                <img src="<?php echo htmlspecialchars($img['file_path']); ?>" class="object-fit-cover" alt="Announcement Image">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($files)): ?>
                                <div class="card bg-light border-0 rounded-3 mt-4">
                                    <div class="card-body">
                                        <h6 class="fw-bold mb-3 text-dark"><i class="bi bi-paperclip me-2"></i>Attachments</h6>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($files as $file): ?>
                                                <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="btn btn-white bg-white border shadow-sm text-start rounded-pill px-3">
                                                    <i class="bi bi-file-earmark-arrow-down-fill text-danger me-2"></i>
                                                    <?php echo htmlspecialchars($file['file_name']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-body p-0 text-center position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 z-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <img src="" id="modalImage" class="img-fluid rounded shadow-lg" style="max-height: 90vh;" alt="Full View">
            </div>
        </div>
    </div>
</div>

<script>
function showImageModal(src) {
    document.getElementById('modalImage').src = src;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}
</script>

<?php
include $base_path . '/includes/footer.php';
?>
</body>
</html>