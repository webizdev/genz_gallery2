<?php
header('Content-Type: application/json');
require_once 'config.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;

            // Get total count
            $countStmt = $pdo->query("SELECT COUNT(*) FROM photos");
            $total = $countStmt->fetchColumn();

            // Get paginated results
            $stmt = $pdo->prepare("SELECT * FROM photos ORDER BY id DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $photos = $stmt->fetchAll();
            echo json_encode([
                'photos' => $photos,
                'total' => $total,
                'page' => $page,
                'pages' => ceil($total / $limit)
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'upload':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Invalid method']);
            break;
        }

        $name = $_POST['name'] ?? 'Untitled';
        $upload_date = date('Y-m-d');
        
        if (!isset($_FILES['files'])) {
            echo json_encode(['error' => 'No files uploaded']);
            break;
        }

        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $results = [];
        $files = $_FILES['files'];
        
        // Handle single or multiple files
        $file_count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $file_count; $i++) {
            $tmp_name = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $file_name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            
            $ext = pathinfo($file_name, PATHINFO_EXTENSION);
            $new_filename = time() . '-' . rand(1000, 9999) . '.' . $ext;
            $target_path = $upload_dir . $new_filename;

            if (move_uploaded_file($tmp_name, $target_path)) {
                try {
                    $final_name = $file_count > 1 ? "$name " . ($i + 1) : $name;
                    $stmt = $pdo->prepare("INSERT INTO photos (name, url, upload_date, storage_path) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$final_name, $target_path, $upload_date, $target_path]);
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
            // Get storage path first to delete file
            $stmt = $pdo->prepare("SELECT storage_path FROM photos WHERE id = ?");
            $stmt->execute([$id]);
            $photo = $stmt->fetch();

            if ($photo && $photo['storage_path'] && file_exists($photo['storage_path'])) {
                unlink($photo['storage_path']);
            }

            $stmt = $pdo->prepare("DELETE FROM photos WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
        break;
}
?>