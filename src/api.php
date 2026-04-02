<?php
$config = require 'config.php';
header('Content-Type: application/json');

$baseDir = realpath($config['base_dir']);
if (!$baseDir) {
    @mkdir($config['base_dir'], 0777, true);
    $baseDir = realpath($config['base_dir']);
}

$tasksFile = $config['tasks_file'];
if (!file_exists($tasksFile)) file_put_contents($tasksFile, json_encode([]));

function getTasks() { 
    global $tasksFile; 
    $fp = fopen($tasksFile, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $json ? json_decode($json, true) : []; 
}

function modifyTasks($callback) {
    global $tasksFile;
    $fp = fopen($tasksFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $json = stream_get_contents($fp);
        $tasks = $json ? json_decode($json, true) : [];
        $tasks = $callback($tasks);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($tasks));
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

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

function normalizePath($path) {
    $path = str_replace('\\', '/', $path);
    return preg_replace('#/+#', '/', $path);
}

function isPathLocked($absPath) {
    $tasks = getTasks();
    global $baseDir;
    $target = rtrim(normalizePath($absPath), '/') . '/';
    
    foreach ($tasks as $task) {
        if (in_array($task['status'], ['completed', 'cancelled', 'error'])) continue;
        
        $from = rtrim(normalizePath($baseDir . '/' . $task['from']), '/') . '/';
        $to = rtrim(normalizePath($baseDir . '/' . $task['to']), '/') . '/';
        
        if (strpos($from, $target) === 0 || strpos($target, $from) === 0) return true;
        if (strpos($to, $target) === 0 || strpos($target, $to) === 0) return true;
    }
    return false;
}

function checkLock($path) {
    if (isPathLocked($path)) {
        die(json_encode(['error' => 'Действие отклонено: файл или папка заняты фоновым процессом.']));
    }
}

function scanDirRecursive($dir, $baseLen) {
    $result = [];
    $items = @scandir($dir);
    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            $relPath = substr($path, $baseLen + 1);
            if (is_dir($path)) {
                $result = array_merge($result, scanDirRecursive($path, $baseLen));
            } else {
                $result[] = ['rel_path' => $relPath, 'size' => filesize($path), 'is_dir' => false];
            }
        }
    }
    return $result;
}

$action = $_GET['action'] ?? '';
$path = $_GET['path'] ?? '';
$targetPath = getSafePath($baseDir, $path);

switch ($action) {
    case 'init':
        echo json_encode([
            'theme' => $config['theme'], 
            'refresh_interval' => $config['refresh_interval'], 
            'panes' => $config['panes'],
            'max_edit_size' => $config['max_edit_size'] ?? 1048576
        ]); break;

    case 'list':
        checkAccess($targetPath, 'r');
        $files = [];
        $items = @scandir($targetPath);
        if ($items) {
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') continue;
                $fp = $targetPath . '/' . $file;
                $stat = @stat($fp);
                if (!$stat) continue;
                $owner = function_exists('posix_getpwuid') ? @posix_getpwuid($stat['uid'])['name'] : $stat['uid'];
                $group = function_exists('posix_getgrgid') ? @posix_getgrgid($stat['gid'])['name'] : $stat['gid'];
                $files[] = [
                    'name' => $file, 'isDir' => is_dir($fp),
                    'size' => is_dir($fp) ? '-' : (filesize($fp) ?: 0),
                    'owner_group' => ($owner ?: '???') . ':' . ($group ?: '???'),
                    'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                    'modified' => date("Y-m-d H:i", $stat['mtime'])
                ];
            }
        }
        usort($files, fn($a, $b) => $b['isDir'] - $a['isDir'] ?: strcasecmp($a['name'], $b['name']));
        echo json_encode(['files' => $files]); break;

    case 'transfer_os': 
        $data = json_decode(file_get_contents('php://input'), true);
        $srcBase = getSafePath($baseDir, $data['from_path']);
        $dstBase = getSafePath($baseDir, $data['to_path']);
        checkAccess($dstBase, 'w');

        $filesToTransfer = [];
        foreach ($data['names'] as $name) {
            $srcPath = rtrim(normalizePath($srcBase . '/' . basename($name)), '/');
            $dstFolder = rtrim(normalizePath($dstBase), '/');
            
            $originalBaseName = basename($name);
            $newBaseName = $originalBaseName;
            $counter = 1;
            
            while (file_exists($dstBase . '/' . $newBaseName)) {
                $info = pathinfo($originalBaseName);
                $isDir = is_dir($srcPath);
                $ext = (isset($info['extension']) && !$isDir) ? '.' . $info['extension'] : '';
                $filename = $isDir ? $originalBaseName : $info['filename'];
                
                $newBaseName = $filename . ' (копия ' . $counter . ')' . $ext;
                $counter++;
            }
            
            $dstPath = rtrim(normalizePath($dstBase . '/' . $newBaseName), '/');
            
            if ($srcPath === $dstPath || strpos($dstFolder . '/', $srcPath . '/') === 0) {
                die(json_encode(['error' => 'Ошибка: попытка скопировать или переместить объект сам в себя.']));
            }
            
            checkLock($srcPath); 
            checkLock($dstPath); 
            
            if (is_dir($srcPath)) {
                @mkdir($dstBase . '/' . $newBaseName, 0777, true);
                
                $subItems = scanDirRecursive($srcPath, strlen($srcBase));
                foreach ($subItems as $item) {
                    $parts = explode('/', $item['rel_path'], 2);
                    $parts[0] = $newBaseName; 
                    $remappedRelPath = implode('/', $parts);

                    if ($item['is_dir']) {
                        @mkdir($dstBase . '/' . $remappedRelPath, 0777, true);
                    } else {
                        @mkdir($dstBase . '/' . dirname($remappedRelPath), 0777, true);
                        $filesToTransfer[] = [
                            'orig_rel_path' => $item['rel_path'],
                            'new_rel_path' => $remappedRelPath,
                            'size' => $item['size'],
                            'is_dir' => false
                        ];
                    }
                }
            } else {
                $filesToTransfer[] = [
                    'orig_rel_path' => basename($name),
                    'new_rel_path' => $newBaseName,
                    'size' => filesize($srcPath),
                    'is_dir' => false
                ];
            }
        }

        $spawnList = [];
        modifyTasks(function($tasks) use ($filesToTransfer, $data, &$spawnList) {
            foreach ($filesToTransfer as $f) {
                $taskId = uniqid('task_');
                $tasks[$taskId] = [
                    'id' => $taskId, 'type' => $data['type'],
                    'from' => $data['from_path'] . '/' . $f['orig_rel_path'],
                    'to' => $data['to_path'] . '/' . $f['new_rel_path'],
                    'name' => $f['new_rel_path'], 
                    'size' => $f['size'], 
                    'offset' => 0, 'status' => 'running'
                ];
                $spawnList[] = $taskId;
            }
            return $tasks;
        });

        foreach ($spawnList as $tid) {
            $cmd = "php " . escapeshellarg(__DIR__ . "/worker.php") . " " . escapeshellarg($tid) . " > /dev/null 2>&1 &";
            exec($cmd);
        }

        echo json_encode(['success' => true]); break;

    case 'poll_tasks': echo json_encode(getTasks()); break;

    case 'control_task':
        $data = json_decode(file_get_contents('php://input'), true);
        modifyTasks(function($tasks) use ($data) {
            if (isset($tasks[$data['id']])) {
                $tasks[$data['id']]['status'] = $data['cmd'];
            }
            return $tasks;
        });
        echo json_encode(['success' => true]); break;

    case 'clear_task':
        $data = json_decode(file_get_contents('php://input'), true);
        modifyTasks(function($tasks) use ($data, $baseDir) {
            if (isset($tasks[$data['id']])) {
                $task = $tasks[$data['id']];
                if ($task['status'] !== 'completed') {
                    $dstDir = realpath($baseDir . '/' . dirname($task['to']));
                    if ($dstDir) @unlink($dstDir . '/' . basename($task['to']));
                }
                unset($tasks[$data['id']]);
            }
            return $tasks;
        });
        echo json_encode(['success' => true]); break;

    case 'cleanup_dirs':
        $data = json_decode(file_get_contents('php://input'), true);
        $srcBase = getSafePath($baseDir, $data['from_path']);
        function removeEmptyDirs($dir) {
            if (!is_dir($dir)) return;
            $items = array_diff(scandir($dir), ['.', '..']);
            foreach ($items as $item) {
                if (is_dir($dir . '/' . $item)) removeEmptyDirs($dir . '/' . $item);
            }
            @rmdir($dir);
        }
        foreach ($data['names'] as $name) {
            $srcPath = $srcBase . '/' . basename($name);
            checkLock($srcPath);
            if (is_dir($srcPath)) removeEmptyDirs($srcPath);
        }
        echo json_encode(['success' => true]); break;

    case 'create_folder':
    case 'create_file':
        checkAccess($targetPath, 'w');
        $data = json_decode(file_get_contents('php://input'), true);
        $new = $targetPath . '/' . basename($data['name']);
        checkLock($new);
        if ($action === 'create_folder') @mkdir($new); else @file_put_contents($new, '');
        echo json_encode(['success' => true]); break;
        
    case 'read_file':
        checkAccess($targetPath, 'r');
        $fp = $targetPath . '/' . basename($_GET['name']);
        
        // --- ЗАЩИТА ОТ ОТКРЫТИЯ БИНАРНЫХ ФАЙЛОВ ---
        $header = @file_get_contents($fp, false, null, 0, 1024);
        if (strpos($header, "\0") !== false) {
            die(json_encode(['error' => 'Отклонено: файл является бинарным и не может быть открыт в текстовом редакторе.']));
        }
        
        echo json_encode(['content' => @file_get_contents($fp)]); break;

    case 'save_file':
        checkAccess($targetPath, 'w');
        $data = json_decode(file_get_contents('php://input'), true);
        $p = $targetPath . '/' . basename($data['name']);
        checkLock($p); 
        file_put_contents($p, $data['content']);
        echo json_encode(['success' => true]); break;

    case 'delete':
        checkAccess($targetPath, 'w');
        $data = json_decode(file_get_contents('php://input'), true);
        foreach ($data['names'] as $name) {
            $p = $targetPath . '/' . basename($name);
            checkLock($p); 
            is_dir($p) ? shell_exec("rm -rf ".escapeshellarg($p)) : @unlink($p);
        }
        echo json_encode(['success' => true]); break;

    case 'rename':
        checkAccess($targetPath, 'w');
        $data = json_decode(file_get_contents('php://input'), true);
        $old = $targetPath . '/' . basename($data['old_name']);
        $new = $targetPath . '/' . basename($data['new_name']);
        checkLock($old); 
        checkLock($new);
        rename($old, $new);
        echo json_encode(['success' => true]); break;

    case 'chmod':
        checkAccess($targetPath, 'w');
        $data = json_decode(file_get_contents('php://input'), true);
        $p = $targetPath . '/' . basename($data['name']);
        checkLock($p); 
        chmod($p, octdec($data['mode']));
        echo json_encode(['success' => true]); break;

    case 'upload':
        checkAccess($targetPath, 'w');
        if (!isset($_FILES['file'])) die(json_encode(['error' => 'Файл не получен.']));
        $dst = $targetPath . '/' . basename($_FILES['file']['name']);
        checkLock($dst); 
        if (file_exists($dst) && ($_POST['overwrite'] ?? 'false') !== 'true') {
            die(json_encode(['confirm' => "Файл уже существует. Заменить?"]));
        }
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dst)) {
            die(json_encode(['error' => 'Ошибка загрузки файла.']));
        }
        echo json_encode(['success' => true]); break;
}