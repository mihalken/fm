<?php
header('Content-Type: application/json');

// Корневая папка для файлов
$baseDir = __DIR__ . '/uploads';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$action = $_GET['action'] ?? '';
$path = $_GET['path'] ?? '';

// Базовая защита от выхода за пределы корневой директории (Directory Traversal)
$targetPath = realpath($baseDir . '/' . $path);
if ($targetPath !== false && strpos($targetPath, realpath($baseDir)) !== 0) {
    die(json_encode(['error' => 'Доступ запрещен']));
}
if ($targetPath === false) {
    $targetPath = $baseDir;
}

switch ($action) {
    case 'list':
        $files = [];
        foreach (scandir($targetPath) as $file) {
            if ($file === '.' || $file === '..') continue;
            $files[] = [
                'name' => $file,
                'isDir' => is_dir($targetPath . '/' . $file)
            ];
        }
        // Сортируем: сначала папки, потом файлы
        usort($files, function($a, $b) {
            if ($a['isDir'] == $b['isDir']) return strcmp($a['name'], $b['name']);
            return $b['isDir'] - $a['isDir'];
        });
        echo json_encode(['files' => $files]);
        break;

    case 'create_folder':
        $data = json_decode(file_get_contents('php://input'), true);
        $newFolder = $targetPath . '/' . basename($data['name']);
        if (!empty($data['name']) && !file_exists($newFolder)) {
            mkdir($newFolder);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Ошибка создания папки']);
        }
        break;

    case 'upload':
        if (isset($_FILES['file'])) {
            $uploadPath = $targetPath . '/' . basename($_FILES['file']['name']);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Ошибка загрузки']);
            }
        }
        break;

    case 'delete':
        $data = json_decode(file_get_contents('php://input'), true);
        $delPath = $targetPath . '/' . basename($data['name']);
        if (is_dir($delPath)) {
            // Удаляем папку (только если она пустая для безопасности)
            @rmdir($delPath); 
        } else if (is_file($delPath)) {
            @unlink($delPath);
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Неизвестное действие']);
        break;
}