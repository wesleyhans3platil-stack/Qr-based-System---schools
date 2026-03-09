<?php
// Shared header for all admin pages
// Usage: $page_title = "Page Name"; include 'includes/header.php';
if (!isset($__favicon)) {
    $__favicon = '';
    $__fav_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='system_logo'");
    if ($__fav_r && $__fav_row = $__fav_r->fetch_assoc()) {
        $__fav_file = $__fav_row['setting_value'] ?? '';
        if ($__fav_file && file_exists(__DIR__ . '/../../assets/uploads/logos/' . $__fav_file)) {
            $__favicon = '../assets/uploads/logos/' . $__fav_file;
        }
    }
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'Admin Panel') ?> — EduTrack | SDO-Sipalay City</title>
<?php if ($__favicon): ?><link rel="icon" type="image/png" href="<?= $__favicon ?>"><?php endif; ?>
<link rel="stylesheet" href="includes/styles.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
