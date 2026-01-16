<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Simple HTML debug page with upload form and server-side diagnostics
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Trucks Upload Debug</title>
    <style>
        body { font-family: system-ui, sans-serif; padding: 1rem 2rem; }
        pre { background: #f6f8fa; padding: 1rem; overflow: auto; }
        .grid { display: grid; gap: 1rem; grid-template-columns: 1fr 1fr; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; }
        form { display: grid; gap: .75rem; }
    </style>
</head>
<body>
    <h1>Trucks Upload Debug</h1>
    <div class="grid">
        <div class="card">
            <h2>Test Upload Form</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_gallery_truck">
                <label>Truck ID
                    <input type="number" name="truck_id" value="1" min="1">
                </label>
                <label>Bilder
                    <input type="file" name="gallery_images[]" accept="image/*" multiple required>
                </label>
                <button type="submit">Test Upload</button>
            </form>
        </div>
        <div class="card">
            <h2>Server Diagnostics</h2>
            <pre><?php
echo "=== trucks_upload_debug ===\n";
echo "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "Action: " . (isset($_POST['action']) ? $_POST['action'] : '(none)') . "\n";
echo "Truck ID: " . (isset($_POST['truck_id']) ? (int)$_POST['truck_id'] : -1) . "\n\n";

echo "FILES keys: " . implode(', ', array_keys($_FILES)) . "\n\n";
if (isset($_FILES['gallery_images'])) {
        $names = isset($_FILES['gallery_images']['name']) ? $_FILES['gallery_images']['name'] : [];
        $sizes = isset($_FILES['gallery_images']['size']) ? $_FILES['gallery_images']['size'] : [];
        $errors = isset($_FILES['gallery_images']['error']) ? $_FILES['gallery_images']['error'] : [];
        echo "Count: " . count($names) . "\n";
        for ($i = 0; $i < count($names); $i++) {
                echo "#{$i} name={$names[$i]} size=" . (int)$sizes[$i] . " error=" . (int)$errors[$i] . "\n";
        }
} else {
        echo "No gallery_images in \$_FILES\n";
}

// Try uploading the first file if present
if (isset($_FILES['gallery_images']['tmp_name'][0]) && (int)$_FILES['gallery_images']['size'][0] > 0) {
        $single = [
                'name' => $_FILES['gallery_images']['name'][0],
                'type' => $_FILES['gallery_images']['type'][0],
                'tmp_name' => $_FILES['gallery_images']['tmp_name'][0],
                'error' => $_FILES['gallery_images']['error'][0],
                'size' => $_FILES['gallery_images']['size'][0]
        ];
        echo "\nCalling upload_image to subfolder 'trucks'...\n";
        $res = upload_image($single, 'trucks');
        var_export($res);
        echo "\n";
}

// Show last 10 error log lines
$log = ROOT_PATH . '/logs/error.log';
if (file_exists($log)) {
        echo "\n=== Tail logs/error.log (last ~10 lines) ===\n";
        $lines = @file($log);
        if ($lines !== false) {
                $start = max(0, count($lines) - 10);
                for ($i = $start; $i < count($lines); $i++) {
                        echo rtrim($lines[$i], "\n") . "\n";
                }
        }
}
?></pre>
        </div>
    </div>
</body>
</html>
