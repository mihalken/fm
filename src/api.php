<?php
$config = require 'config.php';
header('Content-Type: application/json');

$baseDir = $config['base_dir'];
if (!is_dir($baseDir)) @mkdir($baseDir, 0777, true);

$action = $_GET['action'] ?? '';
$path = $_GET['path'] ?? '';
$targetPath = getSafePath($baseDir, $path);

function getSafePath($dir, $rel) {
    $p = realpath($dir . '/' . $rel);
    $base = realpath($dir);
    if ($p !== false && strpos($p, $base) === 0) return $p;
    return $base;
}

function checkAccess($path, $type = 'w') {
    if ($type === 'w' && !is_writable($path)) die(json_encode(['error' => 'Ошибка: нет прав на запись.']));
    if ($type === 'r' && !is_readable($path)) die(json_encode(['error' => 'Ошибка: доступ запрещен.']));
}

switch ($action) {
    case 'init':
        echo json_encode([
            'theme' => $config['theme'] ?? 'dark',
            'refresh_interval' => $config['refresh_interval'] ?? 30
        ]);
        break;

    case 'list':
        checkAccess($targetPath, 'r');
        $files = [];
        $items = @scandir($targetPath);
        foreach ($items as $file) {
            if ($file === '.' || $file === '..') continue;
            $fp = $targetPath . '/' . $file;
            $stat = @stat($fp);
            if (!$stat) continue;
            $owner = function_exists('posix_getpwuid') ? @posix_getpwuid($stat['uid'])['name'] : $stat['uid'];
            $group = function_exists('posix_getgrgid') ? @posix_getgrgid($stat['gid'])['name'] : $stat['gid'];
            $files[] = [
                'name' => $file, 'isDir' => is_dir($fp),
                'owner_group' => ($owner ?: '???') . ':' . ($group ?: '???'),
                'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                'modified' => date("d.m.Y H:i", $stat['mtime'])
            ];
        }
        usort($files, fn($a, $b) => $b['isDir'] - $a['isDir'] ?: strcmp($a['name'], $b['name']));
        echo json_encode(['files' => $files]);
        break;

    case 'transfer':
        $data = json_decode(file_get_contents('php://input'), true);
        $destDir = getSafePath($baseDir, $data['to_path']);
        checkAccess($destDir, 'w');
        foreach ($data['names'] as $name) {
            $src = getSafePath($baseDir, $data['from_path']) . '/' . basename($name);
            $dst = $destDir . '/' . basename($name);
            if (file_exists($dst) && !($data['overwrite'] ?? false)) die(json_encode(['confirm' => "Заменить '$name'?"]));
            $res = ($data['type'] === 'copy') ? (is_dir($src) ? shell_exec("cp -r ".escapeshellarg($src)." ".escapeshellarg($dst)) : @copy($src, $dst)) : @rename($src, $dst);
        }
        echo json_encode(['success' => true]);
        break;

    case 'create_folder':
    case 'create_file':
        checkAccess($targetPath, 'w');
        $data = json_decode(file_get_contents('php://input'), true);
        $new = $targetPath . '/' . basename($data['name']);
        if ($action === 'create_folder') @mkdir($new); else @file_put_contents($new, '');
        echo json_encode(['success' => true]);
        break;

    case 'read_file':
        $fp = $targetPath . '/' . basename($_GET['name']);
        echo json_encode(['content' => @file_get_contents($fp)]);
        break;

    case 'save_file':
        $data = json_decode(file_get_contents('php://input'), true);
        file_put_contents($targetPath . '/' . basename($data['name']), $data['content']);
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $data = json_decode(file_get_contents('php://input'), true);
        foreach ($data['names'] as $name) {
            $p = $targetPath . '/' . basename($name);
            is_dir($p) ? shell_exec("rm -rf ".escapeshellarg($p)) : @unlink($p);
        }
        echo json_encode(['success' => true]);
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
}