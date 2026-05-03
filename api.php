<?php
header('Content-Type: application/json');
require_once 'config.php';

$action = $_GET['action'] ?? '';

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
        case IMAGETYPE_JPEG: $source = imagecreatefromjpeg($source_path); break;
        case IMAGETYPE_PNG: $source = imagecreatefrompng($source_path); break;
        case IMAGETYPE_GIF: $source = imagecreatefromgif($source_path); break;
        case IMAGETYPE_WEBP: $source = imagecreatefromwebp($source_path); break;
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

switch ($action) {
    case 'list':
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;

            // Get total count
            $countStmt = $pdo->query("SELECT COUNT(*) FROM photos");
            $total = $countStmt->fetchColumn();
            $pages = ceil($total / $limit);

            // Get photos with limit and offset
            $stmt = $pdo->prepare("SELECT * FROM photos ORDER BY upload_date DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $photos = $stmt->fetchAll();

            echo json_encode([
                'photos' => $photos,
                'page' => $page,
                'pages' => $pages,
                'total' => $total
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'upload':
        $name = $_POST['name'] ?? 'Untitled';
        $upload_date = date('Y-m-d H:i:s');
        
        $upload_dir = 'uploads/';
        $thumb_dir = 'uploads/thumbs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

        $results = [];
        $files = $_FILES['files'];
        $file_count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $file_count; $i++) {
            $tmp_name = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $file_name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $base_name = time() . '-' . rand(1000, 9999);
            $new_filename = $base_name . '.' . $ext;
            $thumb_filename = $base_name . '_thumb.' . $ext;
            
            $target_path = $upload_dir . $new_filename;
            $thumb_path = $thumb_dir . $thumb_filename;

            if (move_uploaded_file($tmp_name, $target_path)) {
                $has_thumb = generateThumbnail($target_path, $thumb_path);
                $final_thumb_url = $has_thumb ? $thumb_path : $target_path;

                try {
                    $final_name = $file_count > 1 ? "$name " . ($i + 1) : $name;
                    $stmt = $pdo->prepare("INSERT INTO photos (name, url, thumbnail_url, upload_date, storage_path) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$final_name, $target_path, $final_thumb_url, $upload_date, $target_path]);
                    $results[] = ['status' => 'success', 'file' => $file_name];
                } catch (Exception $e) {
                    $results[] = ['status' => 'error', 'message' => $e->getMessage(), 'file' => $file_name];
                }
            } else {
                $results[] = ['status' => 'error', 'message' => 'Failed to move file', 'file' => $file_name];
            }
        }

        echo json_encode(['results' => $results]);
        break;

    case 'delete':
        $id = $_GET['id'] ?? '';
        if (!$id) {
            echo json_encode(['error' => 'Missing ID']);
            break;
        }

        try {
            // Get file paths to delete
            $stmt = $pdo->prepare("SELECT url, thumbnail_url FROM photos WHERE id = ?");
            $stmt->execute([$id]);
            $photo = $stmt->fetch();

            if ($photo) {
                if (file_exists($photo['url'])) unlink($photo['url']);
                if (isset($photo['thumbnail_url']) && file_exists($photo['thumbnail_url'])) unlink($photo['thumbnail_url']);
                
                $delStmt = $pdo->prepare("DELETE FROM photos WHERE id = ?");
                $delStmt->execute([$id]);
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['error' => 'Photo not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
