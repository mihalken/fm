<?php
$taskId = $argv[1] ?? '';
if (!$taskId) exit;

$config = require __DIR__ . '/config.php';
$baseDir = realpath($config['base_dir']);
$tasksFile = $config['tasks_file'];
$chunkSize = $config['chunk_size'] ?? (2 * 1024 * 1024);

function updateTask($id, $callback) {
    global $tasksFile;
    $fp = @fopen($tasksFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $json = stream_get_contents($fp);
        $tasks = $json ? json_decode($json, true) : [];
        if (isset($tasks[$id])) {
            $tasks[$id] = $callback($tasks[$id]);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($tasks));
        }
        flock($fp, LOCK_UN);
    }
    if ($fp) @fclose($fp);
}

$task = null;
$fp = @fopen($tasksFile, 'r');
if ($fp) {
    flock($fp, LOCK_SH);
    $json = stream_get_contents($fp);
    $tasks = $json ? json_decode($json, true) : [];
    $task = $tasks[$taskId] ?? null;
    flock($fp, LOCK_UN);
    @fclose($fp);
}

if (!$task || $task['status'] !== 'running') exit;

$from = rtrim($baseDir, '/') . '/' . ltrim($task['from'], '/');
$to = rtrim($baseDir, '/') . '/' . ltrim($task['to'], '/');

// ---------------------------------------------------------
// ОПТИМИЗАЦИЯ ДЛЯ ОДНОГО ФИЗИЧЕСКОГО ДИСКА
// ---------------------------------------------------------
$fromDev = @stat($from)['dev'];
$toDirDev = @stat(dirname($to))['dev'];

if ($fromDev !== null && $fromDev === $toDirDev) {
    if ($task['type'] === 'cut') {
        updateTask($taskId, fn($t) => array_merge($t, ['native' => true]));
        if (@rename($from, $to)) {
            updateTask($taskId, fn($t) => array_merge($t, ['offset' => $t['size'], 'status' => 'completed', 'native' => false]));
            exit;
        }
        updateTask($taskId, fn($t) => array_merge($t, ['native' => false])); // Откат флага при ошибке
    } else if ($task['type'] === 'copy') {
        updateTask($taskId, fn($t) => array_merge($t, ['native' => true]));
        if (@copy($from, $to)) {
            updateTask($taskId, fn($t) => array_merge($t, ['offset' => $t['size'], 'status' => 'completed', 'native' => false]));
            exit;
        }
        updateTask($taskId, fn($t) => array_merge($t, ['native' => false])); // Откат флага при ошибке
    }
}
// ---------------------------------------------------------

$in = @fopen($from, 'rb');
$out = @fopen($to, $task['offset'] > 0 ? 'ab' : 'wb');

if (!$in || !$out) {
    updateTask($taskId, fn($t) => array_merge($t, ['status' => 'error']));
    if ($in) @fclose($in);
    if ($out) @fclose($out);
    exit;
}

fseek($in, $task['offset']);

while (!feof($in)) {
    $currTask = null;
    $fp = @fopen($tasksFile, 'r');
    if ($fp) {
        flock($fp, LOCK_SH);
        $tasks = json_decode(stream_get_contents($fp), true);
        $currTask = $tasks[$taskId] ?? null;
        flock($fp, LOCK_UN);
        @fclose($fp);
    }
    
    if (!$currTask || $currTask['status'] === 'cancelled') {
        @unlink($to); 
        exit;
    }
    if ($currTask['status'] === 'paused') {
        exit; 
    }

    $data = fread($in, $chunkSize);
    if ($data === false) break;
    
    fwrite($out, $data);
    $newOffset = ftell($in);
    
    updateTask($taskId, fn($t) => array_merge($t, ['offset' => $newOffset]));
    
    usleep(5000); 
}

@fclose($in);
@fclose($out);

if ($task['type'] === 'cut') {
    @unlink($from); 
}

updateTask($taskId, fn($t) => array_merge($t, ['status' => 'completed', 'offset' => $t['size']]));