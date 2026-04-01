<?php
$config = require 'config.php';
header('Content-Type: application/json');

$baseDir = $config['base_dir'];
if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

$action = $_GET['action'] ?? '';
$path = $_GET['path'] ?? '';

function getSafePath($dir, $rel) {
    $p = realpath($dir . '/' . $rel);
    if ($p !== false && strpos($p, realpath($dir)) === 0) return $p;
    return realpath($dir);
}

$targetPath = getSafePath($baseDir, $path);

switch ($action) {
    case 'init':
        echo json_encode(['theme' => $config['theme'], 'panes' => $config['panes']]);
        break;

    case 'list':
        $files = [];
        foreach (scandir($targetPath) as $file) {
            if ($file === '.' || $file === '..') continue;
            $fp = $targetPath . '/' . $file;
            $stat = stat($fp);
            $owner = function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid'])['name'] : $stat['uid'];
            $group = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid'])['name'] : $stat['gid'];
            $files[] = [
                'name' => $file, 'isDir' => is_dir($fp),
                'owner_group' => "$owner:$group",
                'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                'modified' => date("Y-m-d H:i", $stat['mtime'])
            ];
        }
        usort($files, fn($a, $b) => $b['isDir'] - $a['isDir'] ?: strcmp($a['name'], $b['name']));
        echo json_encode(['files' => $files]);
        break;

    case 'transfer': // Копирование или Перемещение
        $data = json_decode(file_get_contents('php://input'), true);
        $source = getSafePath($baseDir, $data['from_path']) . '/' . basename($data['name']);
        $dest = getSafePath($baseDir, $data['to_path']) . '/' . basename($data['name']);
        
        if (!file_exists($source)) die(json_encode(['error' => 'Файл не найден']));
        
        if ($data['type'] === 'copy') {
            $data['isDir'] ? shell_exec("cp -r ".escapeshellarg($source)." ".escapeshellarg($dest)) : copy($source, $dest);
        } else {
            rename($source, $dest);
        }
        echo json_encode(['success' => true]);
        break;

    case 'create_folder':
        $data = json_decode(file_get_contents('php://input'), true);
        mkdir($targetPath . '/' . basename($data['name']));
        echo json_encode(['success' => true]);
        break;

    case 'upload':
        if (isset($_FILES['file'])) {
            move_uploaded_file($_FILES['file']['tmp_name'], $targetPath . '/' . basename($_FILES['file']['name']));
            echo json_encode(['success' => true]);
        }
        break;

    case 'rename':
        $data = json_decode(file_get_contents('php://input'), true);
        rename($targetPath . '/' . basename($data['old_name']), $targetPath . '/' . basename($data['new_name']));
        echo json_encode(['success' => true]);
        break;

    case 'chmod':
        $data = json_decode(file_get_contents('php://input'), true);
        chmod($targetPath . '/' . basename($data['name']), octdec($data['mode']));
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $data = json_decode(file_get_contents('php://input'), true);
        $p = $targetPath . '/' . basename($data['name']);
        is_dir($p) ? shell_exec("rm -rf " . escapeshellarg($p)) : unlink($p);
        echo json_encode(['success' => true]);
        break;
}