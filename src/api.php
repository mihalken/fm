<?php
$config = require 'config.php';
header('Content-Type: application/json');

$baseDir = $config['base_dir'];
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$action = $_GET['action'] ?? '';
$path = $_GET['path'] ?? '';

// Защита от Directory Traversal
$targetPath = realpath($baseDir . '/' . $path);
if ($targetPath !== false && strpos($targetPath, realpath($baseDir)) !== 0) {
    die(json_encode(['error' => 'Доступ запрещен']));
}
if ($targetPath === false) {
    $targetPath = $baseDir;
}

switch ($action) {
    case 'init':
        // Отдаем только нужную часть конфига фронтенду
        echo json_encode([
            'theme' => $config['theme'],
            'panes' => $config['panes']
        ]);
        break;

    case 'list':
        $files = [];
        $items = scandir($targetPath);
        foreach ($items as $file) {
            if ($file === '.' || $file === '..') continue;
            $fullPath = $targetPath . '/' . $file;
            $stat = stat($fullPath);
            
            // Получаем имена юзера и группы (работает на Linux/macOS)
            $owner = function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid'])['name'] : $stat['uid'];
            $group = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid'])['name'] : $stat['gid'];

            $files[] = [
                'name' => $file,
                'isDir' => is_dir($fullPath),
                'owner' => $owner,
                'group' => $group,
                'perms' => substr(sprintf('%o', fileperms($fullPath)), -4),
                'created' => date("Y-m-d H:i", $stat['ctime']),
                'modified' => date("Y-m-d H:i", $stat['mtime'])
            ];
        }
        // Сортировка: папки выше файлов
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

    case 'rename':
        $data = json_decode(file_get_contents('php://input'), true);
        $oldPath = $targetPath . '/' . basename($data['old_name']);
        $newPath = $targetPath . '/' . basename($data['new_name']);
        
        if (!empty($data['new_name']) && file_exists($oldPath) && !file_exists($newPath)) {
            rename($oldPath, $newPath);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Невозможно переименовать']);
        }
        break;

    case 'chmod':
        $data = json_decode(file_get_contents('php://input'), true);
        $itemPath = $targetPath . '/' . basename($data['name']);
        // Переводим строку прав (например '0755') в восьмеричное число
        $mode = octdec($data['mode']);
        if (chmod($itemPath, $mode)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Ошибка смены прав']);
        }
        break;

    case 'delete':
        $data = json_decode(file_get_contents('php://input'), true);
        $delPath = $targetPath . '/' . basename($data['name']);
        if (is_dir($delPath)) {
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