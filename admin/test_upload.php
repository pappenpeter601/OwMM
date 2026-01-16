<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$db = getDBConnection();

echo "<h2>Gallery Upload Debug</h2>";

// Check if form was submitted
if (isset($_POST['upload_gallery'])) {
    echo "<h3>Form Submitted!</h3>";
    echo "<p>Truck ID: " . (int)$_POST['truck_id'] . "</p>";
    
    // Check FILES
    echo "<h3>Files Info:</h3>";
    echo "<pre>";
    print_r($_FILES);
    echo "</pre>";
    
    // Check if files exist
    if (isset($_FILES['gallery_images'])) {
        echo "<h3>Processing files...</h3>";
        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
            echo "<p>File $key:</p>";
            echo "<ul>";
            echo "<li>Name: " . $_FILES['gallery_images']['name'][$key] . "</li>";
            echo "<li>Size: " . $_FILES['gallery_images']['size'][$key] . "</li>";
            echo "<li>Tmp: $tmp_name</li>";
            echo "<li>Error: " . $_FILES['gallery_images']['error'][$key] . "</li>";
            echo "</ul>";
            
            if ($_FILES['gallery_images']['size'][$key] > 0) {
                $single_file = [
                    'name' => $_FILES['gallery_images']['name'][$key],
                    'type' => $_FILES['gallery_images']['type'][$key],
                    'tmp_name' => $tmp_name,
                    'error' => $_FILES['gallery_images']['error'][$key],
                    'size' => $_FILES['gallery_images']['size'][$key]
                ];
                
                echo "<p>Calling upload_image...</p>";
                $result = upload_image($single_file, 'trucks');
                echo "<p>Result:</p><pre>";
                print_r($result);
                echo "</pre>";
            }
        }
    } else {
        echo "<p style='color: red;'>No FILES data received!</p>";
    }
} else {
    echo "<p>No form submission detected</p>";
}

// Show existing gallery images
echo "<h3>Existing Gallery Images:</h3>";
$stmt = $db->query('SELECT id, truck_id, image_url, caption FROM gallery_images');
$images = $stmt->fetchAll();
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Truck ID</th><th>Image URL</th><th>Caption</th></tr>";
foreach($images as $img) {
    echo "<tr>";
    echo "<td>{$img['id']}</td>";
    echo "<td>" . ($img['truck_id'] ?? 'NULL') . "</td>";
    echo "<td>{$img['image_url']}</td>";
    echo "<td>" . ($img['caption'] ?? '') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Test form
echo '<h3>Test Upload Form</h3>';
echo '<form method="POST" enctype="multipart/form-data">';
echo '<input type="hidden" name="truck_id" value="1">';
echo '<input type="file" name="gallery_images[]" multiple accept="image/*" required>';
echo '<button type="submit" name="upload_gallery">Test Upload</button>';
echo '</form>';
?>
