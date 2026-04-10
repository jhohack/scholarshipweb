<!-- Browser Tab Icon (Favicon) -->
<?php
// Dynamically resolve the path to the favicon image based on current page depth
$icon_path = 'images/dvclogo.png';
if (file_exists($icon_path)) {
    $favicon_href = $icon_path;
} elseif (file_exists('../' . $icon_path)) {
    $favicon_href = '../' . $icon_path;
} elseif (file_exists('../../' . $icon_path)) {
    $favicon_href = '../../' . $icon_path;
} else {
    $favicon_href = '../' . $icon_path; // Fallback
}

// Build URLs based on current script location.
$script_dir_url = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$script_dir_url = rtrim($script_dir_url, '/');
if ($script_dir_url === '') {
    $script_dir_url = '/';
}

$is_public_area = preg_match('#/public$#', $script_dir_url) === 1;
$public_base_url = $is_public_area
    ? $script_dir_url
    : rtrim(preg_replace('#/(admin|student)$#', '', $script_dir_url), '/') . '/public';

$manifest_config = [
    'name' => 'DVC Scholarship Portal',
    'short_name' => 'DVC Portal',
    'description' => 'Find and apply for scholarships at Davao Vision College.',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#0d6efd'
];
?>
<link rel="icon" href="<?php echo $favicon_href; ?>" type="image/x-icon">
<link rel="apple-touch-icon" href="<?php echo $favicon_href; ?>">
<meta name="theme-color" content="#0d6efd">
<?php if ($is_public_area): ?>
<script>
(function() {
    // InfinityFree can return an HTML security page for manifest.json.
    // Use a Blob manifest so browsers always receive valid JSON.
    const baseUrl = window.location.origin + <?php echo json_encode($public_base_url, JSON_UNESCAPED_SLASHES); ?>;
    const projectBaseUrl = baseUrl.replace(/\/public$/, '');
    const manifestData = {
        name: <?php echo json_encode($manifest_config['name']); ?>,
        short_name: <?php echo json_encode($manifest_config['short_name']); ?>,
        description: <?php echo json_encode($manifest_config['description']); ?>,
        start_url: baseUrl + '/index.php',
        scope: baseUrl + '/',
        display: <?php echo json_encode($manifest_config['display']); ?>,
        background_color: <?php echo json_encode($manifest_config['background_color']); ?>,
        theme_color: <?php echo json_encode($manifest_config['theme_color']); ?>,
        icons: [
            {
                src: projectBaseUrl + '/images/dvclogo.png',
                sizes: '192x192',
                type: 'image/png',
                purpose: 'any'
            },
            {
                src: projectBaseUrl + '/images/dvclogo.png',
                sizes: '512x512',
                type: 'image/png',
                purpose: 'any maskable'
            }
        ]
    };

    const manifestBlob = new Blob([JSON.stringify(manifestData)], { type: 'application/json' });
    const manifestUrl = URL.createObjectURL(manifestBlob);
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = manifestUrl;
    document.head.appendChild(link);
})();
</script>
<?php endif; ?>
