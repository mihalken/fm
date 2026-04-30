<?php
class FileManagerApi {
    private $config;
    private $baseDir;
    private $serverTimeZone;
    private $tasksFile;

    public function __construct() {
        $this->config = require 'config.php';
        
        $tzString = ini_get('date.timezone') ?: date_default_timezone_get();
        date_default_timezone_set($tzString);
        
        try {
            $this->serverTimeZone = new DateTimeZone($tzString);
        } catch (Exception $e) {
            $this->serverTimeZone = new DateTimeZone('UTC'); 
        }

        $this->baseDir = realpath($this->config['base_dir']);
        if (!$this->baseDir) {
            @mkdir($this->config['base_dir'], 0777, true);
            $this->baseDir = realpath($this->config['base_dir']);
        }

        // Жестко задаем путь во временной папке ОС
        $this->tasksFile = sys_get_temp_dir() . '/fm_tasks_' . md5(__DIR__) . '.json';
        
        if (!file_exists($this->tasksFile)) {
            file_put_contents($this->tasksFile, json_encode([]));
        }
    }

    private function getTasks() { 
        $fp = @fopen($this->tasksFile, 'r');
        if (!$fp) return [];
        flock($fp, LOCK_SH);
        $json = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $json ? json_decode($json, true) : []; 
    }

    private function modifyTasks($callback) {
        $fp = @fopen($this->tasksFile, 'c+');
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

    private function getSafePath($rel) {
        $base = rtrim(str_replace('\\', '/', $this->baseDir), '/');
        $parts = explode('/', str_replace('\\', '/', $rel));
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($safeParts);
            else $safeParts[] = $part;
        }
        return rtrim($base . (!empty($safeParts) ? '/' . implode('/', $safeParts) : ''), '/');
    }

    private function checkAccess($path, $type = 'w') {
        if ($type === 'w' && !is_writable($path)) throw new Exception('Ошибка: нет прав на запись.');
        if ($type === 'r' && !is_readable($path)) throw new Exception('Ошибка: доступ запрещен.');
    }

    private function normalizePath($path) {
        return preg_replace('#/+#', '/', str_replace('\\', '/', $path));
    }

    private function checkLock($absPath) {
        $tasks = $this->getTasks();
        $target = rtrim($this->normalizePath($absPath), '/') . '/';
        
        foreach ($tasks as $task) {
            if (in_array($task['status'], ['completed', 'cancelled', 'error'])) continue;
            $from = rtrim($this->normalizePath($this->baseDir . '/' . $task['from']), '/') . '/';
            $to = rtrim($this->normalizePath($this->baseDir . '/' . $task['to']), '/') . '/';
            
            if (strpos($from, $target) === 0 || strpos($target, $from) === 0 || 
                strpos($to, $target) === 0 || strpos($target, $to) === 0) {
                throw new Exception('Действие отклонено: файл или папка заняты фоновым процессом.');
            }
        }
    }

    private function scanDirRecursive($dir, $baseLen) {
        $result = [];
        $items = @scandir($dir);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . '/' . $item;
                $relPath = substr($path, $baseLen + 1);
                
                if (is_dir($path) && !is_link($path)) { 
                    $result[] = ['rel_path' => $relPath, 'size' => 0, 'is_dir' => true];
                    $result = array_merge($result, $this->scanDirRecursive($path, $baseLen));
                } else {
                    $result[] = ['rel_path' => $relPath, 'size' => @filesize($path) ?: 0, 'is_dir' => false];
                }
            }
        }
        return $result;
    }

    public function handleRequest() {
        try {
            $action = $_GET['action'] ?? '';
            
            if ($action === 'download') {
                $this->handleDownload($_GET['path'] ?? '', $_GET['name'] ?? '');
                return;
            }

            header('Content-Type: application/json');
            
            if ($action === 'batch') {
                $input = json_decode(file_get_contents('php://input'), true);
                $responses = [];
                foreach ($input['requests'] ?? [] as $req) {
                    try {
                        $responses[] = $this->executeAction($req['action'] ?? '', $req['path'] ?? '', $req['data'] ?? []);
                    } catch (Exception $e) {
                        $responses[] = ['error' => $e->getMessage()];
                    }
                }
                echo json_encode(['responses' => $responses]);
            } else {
                $path = $_GET['path'] ?? '';
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                echo json_encode($this->executeAction($action, $path, $data));
            }
        } catch (Exception $e) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function executeAction($action, $path, $data) {
        $targetPath = in_array($action, ['init', 'poll_tasks']) ? $this->baseDir : $this->getSafePath($path);
        $methodName = 'action' . str_replace('_', '', ucwords($action, '_'));
        
        if (method_exists($this, $methodName)) {
            return $this->$methodName($path, $data, $targetPath);
        }
        throw new Exception("Неизвестное действие: $action");
    }

    private function actionInit($path, $data, $targetPath) {
        return [
            'theme' => $this->config['theme'], 
            'refresh_interval' => $this->config['refresh_interval'], 
            'panes' => $this->config['panes'],
            'max_edit_size' => $this->config['max_edit_size'] ?? 1048576,
            'window_title' => $this->config['window_title'] ?? 'Simple File Manager',
            'use_trash' => $this->config['use_trash'] ?? false
        ];
    }

    private function actionList($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'r');
        $files = [];
        $items = @scandir($targetPath);
        if ($items) {
            foreach ($items as $file) {
                if ($file === '.' || $file === '..') continue;
                $fp = $targetPath . '/' . $file;
                $isLink = is_link($fp);
                $stat = @stat($fp) ?: @lstat($fp); 
                if (!$stat) continue;
                
                $owner = function_exists('posix_getpwuid') ? @posix_getpwuid($stat['uid'])['name'] : $stat['uid'];
                $group = function_exists('posix_getgrgid') ? @posix_getgrgid($stat['gid'])['name'] : $stat['gid'];
                
                $dt = new DateTime('@' . $stat['mtime']);
                $dt->setTimezone($this->serverTimeZone);

                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $type = is_dir($fp) ? 'folder' : ($ext !== '' ? strtolower($ext) : 'none');

                $files[] = [
                    'name' => $file, 'isDir' => is_dir($fp), 'isLink' => $isLink, 
                    'ext' => $type,
                    'size' => is_dir($fp) ? '-' : (filesize($fp) ?: 0),
                    'owner_group' => ($owner ?: '???') . ':' . ($group ?: '???'),
                    'perms' => substr(sprintf('%o', fileperms($fp)), -4),
                    'modified' => $dt->format('d.m.Y H:i:s'), 'mtime' => $stat['mtime']
                ];
            }
        }
        usort($files, fn($a, $b) => $b['isDir'] - $a['isDir'] ?: strcasecmp($a['name'], $b['name']));
        return ['files' => $files];
    }

    private function actionTransferOs($path, $data, $targetPath) {
        return $this->handleTransferOrDelete(false, $path, $data, $targetPath);
    }

    private function actionDelete($path, $data, $targetPath) {
        return $this->handleTransferOrDelete(true, $path, $data, $targetPath);
    }

    private function handleTransferOrDelete($isDelete, $path, $data, $targetPath) {
        $useTrash = $this->config['use_trash'] ?? false;
        $trashPath = $this->normalizePath($this->baseDir . '/.trash');
        
        if ($isDelete) {
            $this->checkAccess($targetPath, 'w');
            $permanentDelete = [];
            $moveToTrash = [];
            $forceDelete = !empty($data['force_delete']); 
            
            foreach ($data['names'] as $name) {
                $p = $this->normalizePath($targetPath . '/' . basename($name));
                $this->checkLock($p);
                $isTrashFolder = ($p === $trashPath);
                $isInTrash = strpos($p, $trashPath . '/') === 0;
                
                if ($useTrash && !$isTrashFolder && !$isInTrash && !$forceDelete) {
                    $moveToTrash[] = basename($name);
                } else {
                    $permanentDelete[] = basename($name);
                }
            }
            
            foreach ($permanentDelete as $name) {
                $p = $this->normalizePath($targetPath . '/' . basename($name));
                if (is_link($p)) { @unlink($p); } 
                else { is_dir($p) ? shell_exec("rm -rf ".escapeshellarg($p)) : @unlink($p); }
            }
            
            if (empty($moveToTrash)) return ['success' => true];
            
            $data['from_path'] = $path;
            $data['to_path'] = '.trash';
            $data['names'] = $moveToTrash;
            $data['type'] = 'cut';
            
            if (!is_dir($trashPath)) @mkdir($trashPath, 0777, true);
        } else {
            if ($data['to_path'] === '.trash' && !is_dir($trashPath)) @mkdir($trashPath, 0777, true);
        }

        $srcBase = $this->getSafePath($data['from_path']);
        $dstBase = $this->getSafePath($data['to_path']);
        $this->checkAccess($dstBase, 'w');

        $filesToTransfer = [];
        foreach ($data['names'] as $name) {
            $srcPath = rtrim($this->normalizePath($srcBase . '/' . basename($name)), '/');
            $dstFolder = rtrim($this->normalizePath($dstBase), '/');
            
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
            
            $dstPath = rtrim($this->normalizePath($dstBase . '/' . $newBaseName), '/');
            if ($srcPath === $dstPath || strpos($dstFolder . '/', $srcPath . '/') === 0) {
                throw new Exception('Ошибка: попытка скопировать или переместить объект сам в себя.');
            }
            
            $this->checkLock($srcPath); $this->checkLock($dstPath); 
            
            if (is_dir($srcPath)) {
                @mkdir($dstBase . '/' . $newBaseName, 0777, true);
                $subItems = $this->scanDirRecursive($srcPath, strlen($srcBase));
                foreach ($subItems as $item) {
                    $parts = explode('/', $item['rel_path'], 2);
                    $parts[0] = $newBaseName; 
                    $remappedRelPath = implode('/', $parts);

                    if ($item['is_dir']) {
                        @mkdir($dstBase . '/' . $remappedRelPath, 0777, true);
                    } else {
                        @mkdir($dstBase . '/' . dirname($remappedRelPath), 0777, true);
                        $filesToTransfer[] = ['orig_rel_path' => $item['rel_path'], 'new_rel_path' => $remappedRelPath, 'size' => $item['size']];
                    }
                }
            } else {
                $filesToTransfer[] = ['orig_rel_path' => basename($name), 'new_rel_path' => $newBaseName, 'size' => filesize($srcPath)];
            }
        }

        $spawnList = [];
        $this->modifyTasks(function($tasks) use ($filesToTransfer, $data, &$spawnList) {
            foreach ($filesToTransfer as $f) {
                $taskId = uniqid('task_');
                $tasks[$taskId] = [
                    'id' => $taskId, 'type' => $data['type'],
                    'from' => $data['from_path'] . '/' . $f['orig_rel_path'],
                    'to' => $data['to_path'] . '/' . $f['new_rel_path'],
                    'name' => $f['new_rel_path'], 'size' => $f['size'], 
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

        if ($isDelete) {
             return [
                'success' => true, 'trash_started' => true,
                'trash_cleanup' => ['from_path' => $data['from_path'], 'names' => $data['names'], 'type' => 'cut']
             ];
        }
        return ['success' => true];
    }

    private function actionPollTasks() { return $this->getTasks(); }

    private function actionControlTask($path, $data) {
        $this->modifyTasks(function($tasks) use ($data) {
            if (isset($tasks[$data['id']])) $tasks[$data['id']]['status'] = $data['cmd'];
            return $tasks;
        });
        return ['success' => true];
    }

    private function actionClearTask($path, $data) {
        $this->modifyTasks(function($tasks) use ($data) {
            if (isset($tasks[$data['id']])) {
                $task = $tasks[$data['id']];
                if ($task['status'] !== 'completed') {
                    $dstDir = realpath($this->baseDir . '/' . dirname($task['to']));
                    if ($dstDir) @unlink($dstDir . '/' . basename($task['to']));
                }
                unset($tasks[$data['id']]);
            }
            return $tasks;
        });
        return ['success' => true];
    }

    private function actionCleanupDirs($path, $data) {
        $srcBase = $this->getSafePath($data['from_path']);
        
        $removeEmptyDirs = function($dir) use (&$removeEmptyDirs) {
            if (!is_dir($dir) || is_link($dir)) return;
            $items = array_diff(scandir($dir), ['.', '..']);
            foreach ($items as $item) {
                if (is_dir($dir . '/' . $item)) $removeEmptyDirs($dir . '/' . $item);
            }
            @rmdir($dir);
        };
        
        foreach ($data['names'] as $name) {
            $srcPath = $srcBase . '/' . basename($name);
            $this->checkLock($srcPath);
            if (is_dir($srcPath) && !is_link($srcPath)) $removeEmptyDirs($srcPath);
        }
        return ['success' => true];
    }

    private function actionCreateFolder($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'w');
        $new = $targetPath . '/' . basename($data['name']);
        $this->checkLock($new);
        @mkdir($new); return ['success' => true];
    }

    private function actionCreateFile($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'w');
        $new = $targetPath . '/' . basename($data['name']);
        $this->checkLock($new);
        @file_put_contents($new, ''); return ['success' => true];
    }

    private function actionReadFile($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'r');
        $fp = $targetPath . '/' . basename($data['name'] ?? '');
        $header = @file_get_contents($fp, false, null, 0, 1024);
        if (strpos($header, "\0") !== false) throw new Exception('Отклонено: файл является бинарным.');
        return ['content' => @file_get_contents($fp)];
    }

    private function actionSaveFile($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'w');
        $p = $targetPath . '/' . basename($data['name']);
        $this->checkLock($p); 
        file_put_contents($p, $data['content']);
        return ['success' => true];
    }

    private function actionRename($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'w');
        $old = $targetPath . '/' . basename($data['old_name']);
        $new = $targetPath . '/' . basename($data['new_name']);
        $this->checkLock($old); $this->checkLock($new);
        rename($old, $new);
        return ['success' => true];
    }

    private function actionChmod($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'w');
        $p = $targetPath . '/' . basename($data['name']);
        $this->checkLock($p); chmod($p, octdec($data['mode']));
        return ['success' => true];
    }

    private function actionUpload($path, $data, $targetPath) {
        $this->checkAccess($targetPath, 'w');
        if (!isset($_FILES['file'])) throw new Exception('Файл не получен.');
        $dst = $targetPath . '/' . basename($_FILES['file']['name']);
        $this->checkLock($dst); 
        if (file_exists($dst) && ($data['overwrite'] ?? $_POST['overwrite'] ?? 'false') !== 'true') {
            return ['confirm' => "Файл уже существует. Заменить?"];
        }
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dst)) throw new Exception('Ошибка загрузки файла.');
        return ['success' => true];
    }

    private function handleDownload($path, $name) {
        $targetPath = $this->getSafePath($path);
        $fullPath = $targetPath . '/' . basename($name);
        
        $this->checkAccess($targetPath, 'r');
        if (!file_exists($fullPath) && !is_link($fullPath)) throw new Exception('Файл или папка не найдены.');
        $this->checkLock($fullPath);

        if (is_dir($fullPath) && !is_link($fullPath)) {
            if (!extension_loaded('zip')) throw new Exception('На сервере не установлено расширение ZIP.');
            
            $zipFile = sys_get_temp_dir() . '/' . uniqid('cc_') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception('Не удалось создать ZIP архив.');
            
            $dirLen = strlen($fullPath);
            $items = $this->scanDirRecursive($fullPath, $dirLen);
            $baseName = basename($fullPath);
            $zip->addEmptyDir($baseName);
            
            foreach ($items as $item) {
                $localName = $baseName . '/' . $item['rel_path'];
                if ($item['is_dir']) $zip->addEmptyDir($localName);
                else $zip->addFile($fullPath . '/' . $item['rel_path'], $localName);
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
            header('Content-Length: ' . @filesize($fullPath));
            readfile($fullPath);
            exit;
        }
    }
}

// Запуск API
$api = new FileManagerApi();
$api->handleRequest();