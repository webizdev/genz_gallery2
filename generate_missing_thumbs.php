<?php
require_once 'config.php';

// Copy the logic from api.php to ensure consistency
function generateThumbnail($source_path, $target_path, $target_width = 400) {
    if (!file_exists($source_path)) return false;
    
    list($width, $height, $type) = getimagesize($source_path);
    if ($width <= 0 || $height <= 0) return false;

    $target_height = floor($height * ($target_width / $width));
    $thumbnail = imagecreatetruecolor($target_width, $target_height);
    
    // Handle transparency for PNG/WebP
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
        imagefilledrectangle($thumbnail, 0, 0, $target_width, $target_height, $transparent);
    }

    switch ($type) {
        case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($source_path); break;
        case IMAGETYPE_PNG: $source = @imagecreatefrompng($source_path); break;
        case IMAGETYPE_GIF: $source = @imagecreatefromgif($source_path); break;
        case IMAGETYPE_WEBP: $source = @imagecreatefromwebp($source_path); break;
        default: return false;
    }

    if (!$source) return false;

    imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $target_width, $target_height, $width, $height);

    switch ($type) {
        case IMAGETYPE_JPEG: imagejpeg($thumbnail, $target_path, 80); break;
        case IMAGETYPE_PNG: imagepng($thumbnail, $target_path); break;
        case IMAGETYPE_GIF: imagegif($thumbnail, $target_path); break;
        case IMAGETYPE_WEBP: imagewebp($thumbnail, $target_path, 80); break;
    }

    imagedestroy($thumbnail);
    imagedestroy($source);
    return true;
}

// Create thumb dir if not exists
$thumb_dir = 'uploads/thumbs/';
if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

echo "Starting thumbnail generation...<br>";

try {
    // Fetch photos that don't have thumbnails
    $stmt = $pdo->query("SELECT id, url FROM photos WHERE thumbnail_url IS NULL OR thumbnail_url = ''");
    $photos = $stmt->fetchAll();

    $count = 0;
    foreach ($photos as $photo) {
        $source_path = $photo['url'];
        
        // Skip if original doesn't exist
        if (!file_exists($source_path)) {
            echo "Skipping ID {$photo['id']}: File not found ({$source_path})<br>";
            continue;
        }

        $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
        $base_name = pathinfo($source_path, PATHINFO_FILENAME);
        $thumb_filename = $base_name . '_thumb.' . $ext;
        $thumb_path = $thumb_dir . $thumb_filename;

        if (generateThumbnail($source_path, $thumb_path)) {
            $update = $pdo->prepare("UPDATE photos SET thumbnail_url = ? WHERE id = ?");
            $update->execute([$thumb_path, $photo['id']]);
            echo "Generated thumbnail for ID {$photo['id']}<br>";
            $count++;
        } else {
            echo "Failed to generate thumbnail for ID {$photo['id']}<br>";
        }
    }

    echo "<br>Finished! Total thumbnails generated: $count";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>