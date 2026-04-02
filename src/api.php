<?php
$config = require 'config.php';

$tzString = !empty($config['timezone']) ? $config['timezone'] : (ini_get('date.timezone') ?: date_default_timezone_get());
date_default_timezone_set($tzString);

try {
    $serverTimeZone = new DateTimeZone($tzString);
} catch (Exception $e) {
    $serverTimeZone = new DateTimeZone('UTC'); 
}

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
    throw new Exception('Папка не найдена или недоступна.');
}

function checkAccess($path, $type = 'w') {
    if ($type === 'w' && !is_writable($path)) throw new Exception('Ошибка: нет прав на запись.');
    if ($type === 'r' && !is_readable($path)) throw new Exception('Ошибка: доступ запрещен.');
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
        throw new Exception('Действие отклонено: файл или папка заняты фоновым процессом.');
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
                $result[] = ['rel_path' => $relPath, 'size' => 0, 'is_dir' => true];
                $result = array_merge($result, scanDirRecursive($path, $baseLen));
            } else {
                $result[] = ['rel_path' => $relPath, 'size' => filesize($path), 'is_dir' => false];
            }
        }
    }
    return $result;
}

function handleAction($action, $path, $data, $targetPath) {
    global $config, $baseDir, $serverTimeZone;

    switch ($action) {
        case 'init':
            return [
                'theme' => $config['theme'], 
                'refresh_interval' => $config['refresh_interval'], 
                'panes' => $config['panes'],
                'max_edit_size' => $config['max_edit_size'] ?? 1048576,
                'window_title' => $config['window_title'] ?? 'Simple File Manager',
                'use_trash' => $config['use_trash'] ?? false
            ];

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
                    
                    $dt = new DateTime('@' . $stat['mtime']);
                    $dt->setTimezone($serverTimeZone);

                    $files[] = [
                        'name' => $file, 'isDir' => is_dir($fp),
                        'size' => is_dir($fp) ? '-' : (filesize($fp) ?: 0),
                        'owner_group' => ($owner ?: '???') . ':' . ($group ?: '???'),
                        'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                        'modified' => $dt->format('d.m.Y H:i:s')
                    ];
                }
            }
            usort($files, fn($a, $b) => $b['isDir'] - $a['isDir'] ?: strcasecmp($a['name'], $b['name']));
            return ['files' => $files];

        // ОБЪЕДИНЕННЫЙ БЛОК: Перенос файлов и Удаление
        case 'transfer_os': 
        case 'delete':
            $isDelete = ($action === 'delete');
            $useTrash = $config['use_trash'] ?? false;
            $trashPath = normalizePath($baseDir . '/.trash');
            
            if ($isDelete) {
                checkAccess($targetPath, 'w');
                $permanentDelete = [];
                $moveToTrash = [];
                
                // Сортируем: что удалять навсегда, а что в корзину
                foreach ($data['names'] as $name) {
                    $p = normalizePath($targetPath . '/' . basename($name));
                    checkLock($p);
                    $isTrashFolder = ($p === $trashPath);
                    $isInTrash = strpos($p, $trashPath . '/') === 0;
                    
                    if ($useTrash && !$isTrashFolder && !$isInTrash) {
                        $moveToTrash[] = basename($name);
                    } else {
                        $permanentDelete[] = basename($name);
                    }
                }
                
                // Выполняем безвозвратное удаление
                foreach ($permanentDelete as $name) {
                    $p = normalizePath($targetPath . '/' . basename($name));
                    is_dir($p) ? shell_exec("rm -rf ".escapeshellarg($p)) : @unlink($p);
                }
                
                // Если в корзину ничего переносить не надо - завершаем
                if (empty($moveToTrash)) {
                    return ['success' => true];
                }
                
                // Иначе подготавливаем переменные для переноса (transfer_os)
                $data['from_path'] = $path;
                $data['to_path'] = '.trash';
                $data['names'] = $moveToTrash;
                $data['type'] = 'cut';
                
                if (!is_dir($trashPath)) @mkdir($trashPath, 0777, true);
            } else {
                // Если это обычный перенос, и цель корзина - создаем её
                if ($data['to_path'] === '.trash' && !is_dir($trashPath)) {
                    @mkdir($trashPath, 0777, true);
                }
            }

            // --- ОБЩАЯ ЛОГИКА ФОНОВОГО ПЕРЕНОСА (ОБЩАЯ ДЛЯ DELETE И TRANSFER) ---
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
                    $suffix = $isDelete ? 'удалено ' : 'копия ';
                    $newBaseName = $filename . ' (' . $suffix . $counter . ')' . $ext;
                    $counter++;
                }
                
                $dstPath = rtrim(normalizePath($dstBase . '/' . $newBaseName), '/');
                
                if ($srcPath === $dstPath || strpos($dstFolder . '/', $srcPath . '/') === 0) {
                    throw new Exception('Ошибка: попытка скопировать или переместить объект сам в себя.');
                }
                
                checkLock($srcPath); checkLock($dstPath); 
                
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

            // Передаем клиенту команду на запуск процесса очистки пустых папок после завершения фоновых задач
            if ($isDelete) {
                 return [
                    'success' => true,
                    'trash_started' => true,
                    'trash_cleanup' => [
                        'from_path' => $data['from_path'],
                        'names' => $data['names'],
                        'type' => 'cut'
                    ]
                 ];
            }

            return ['success' => true];

        case 'poll_tasks': 
            return getTasks();

        case 'control_task':
            modifyTasks(function($tasks) use ($data) {
                if (isset($tasks[$data['id']])) {
                    $tasks[$data['id']]['status'] = $data['cmd'];
                }
                return $tasks;
            });
            return ['success' => true];

        case 'clear_task':
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
            return ['success' => true];

        case 'cleanup_dirs':
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
            return ['success' => true];

        case 'create_folder':
        case 'create_file':
            checkAccess($targetPath, 'w');
            $new = $targetPath . '/' . basename($data['name']);
            checkLock($new);
            if ($action === 'create_folder') @mkdir($new); else @file_put_contents($new, '');
            return ['success' => true];
            
        case 'read_file':
            checkAccess($targetPath, 'r');
            $name = basename($data['name'] ?? $_GET['name'] ?? '');
            $fp = $targetPath . '/' . $name;
            $header = @file_get_contents($fp, false, null, 0, 1024);
            if (strpos($header, "\0") !== false) {
                throw new Exception('Отклонено: файл является бинарным и не может быть открыт в текстовом редакторе.');
            }
            return ['content' => @file_get_contents($fp)];

        case 'save_file':
            checkAccess($targetPath, 'w');
            $p = $targetPath . '/' . basename($data['name']);
            checkLock($p); 
            file_put_contents($p, $data['content']);
            return ['success' => true];

        case 'rename':
            checkAccess($targetPath, 'w');
            $old = $targetPath . '/' . basename($data['old_name']);
            $new = $targetPath . '/' . basename($data['new_name']);
            checkLock($old); checkLock($new);
            rename($old, $new);
            return ['success' => true];

        case 'chmod':
            checkAccess($targetPath, 'w');
            $p = $targetPath . '/' . basename($data['name']);
            checkLock($p); chmod($p, octdec($data['mode']));
            return ['success' => true];

        case 'upload':
            checkAccess($targetPath, 'w');
            if (!isset($_FILES['file'])) throw new Exception('Файл не получен.');
            $dst = $targetPath . '/' . basename($_FILES['file']['name']);
            checkLock($dst); 
            if (file_exists($dst) && ($data['overwrite'] ?? $_POST['overwrite'] ?? 'false') !== 'true') {
                return ['confirm' => "Файл уже существует. Заменить?"];
            }
            if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dst)) {
                throw new Exception('Ошибка загрузки файла.');
            }
            return ['success' => true];

        default:
            throw new Exception("Неизвестное действие: $action");
    }
}

try {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'download') {
        $path = $_GET['path'] ?? '';
        $name = $_GET['name'] ?? '';
        $targetPath = getSafePath($baseDir, $path);
        $fullPath = $targetPath . '/' . basename($name);
        
        checkAccess($targetPath, 'r');
        if (!file_exists($fullPath)) throw new Exception('Файл или папка не найдены.');
        checkLock($fullPath);

        if (is_dir($fullPath)) {
            if (!extension_loaded('zip')) throw new Exception('На сервере не установлено расширение ZIP.');
            
            $zipFile = sys_get_temp_dir() . '/' . uniqid('cc_') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('Не удалось создать ZIP архив.');
            }
            
            $dirLen = strlen($fullPath);
            $items = scanDirRecursive($fullPath, $dirLen);
            $baseName = basename($fullPath);
            $zip->addEmptyDir($baseName);
            
            foreach ($items as $item) {
                $localName = $baseName . '/' . $item['rel_path'];
                if ($item['is_dir']) {
                    $zip->addEmptyDir($localName);
                } else {
                    $zip->addFile($fullPath . '/' . $item['rel_path'], $localName);
                }
            }
            $zip->close();
            
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $baseName . '.zip"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);
            unlink($zipFile);
            exit;
        } else {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
            header('Content-Length: ' . filesize($fullPath));
            readfile($fullPath);
            exit;
        }
    }

    header('Content-Type: application/json');
    
    if ($action === 'batch') {
        $input = json_decode(file_get_contents('php://input'), true);
        $responses = [];
        foreach ($input['requests'] ?? [] as $req) {
            try {
                $reqAction = $req['action'] ?? '';
                $reqPath = $req['path'] ?? '';
                $reqData = $req['data'] ?? [];
                $targetPath = getSafePath($baseDir, $reqPath);
                $responses[] = handleAction($reqAction, $reqPath, $reqData, $targetPath);
            } catch (Exception $e) {
                $responses[] = ['error' => $e->getMessage()];
            }
        }
        echo json_encode(['responses' => $responses]);
    } else {
        $path = $_GET['path'] ?? '';
        $targetPath = ($action === 'init' || $action === 'poll_tasks') ? $baseDir : getSafePath($baseDir, $path);
        
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        echo json_encode(handleAction($action, $path, $data, $targetPath));
    }
} catch (Exception $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => $e->getMessage()]);
}