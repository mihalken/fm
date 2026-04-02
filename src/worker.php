<?php
if (php_sapi_name() !== 'cli') die("Только для CLI");

$taskId = $argv[1] ?? null;
if (!$taskId) die("Не указан ID задачи");

$config = require __DIR__ . '/config.php';
$tasksFile = $config['tasks_file'];
$baseDir = realpath($config['base_dir']);

function updateTask($taskId, $callback) {
    global $tasksFile;
    $fp = fopen($tasksFile, 'c+');
    $ret = null;
    if ($fp && flock($fp, LOCK_EX)) {
        $json = stream_get_contents($fp);
        $tasks = $json ? json_decode($json, true) : [];
        
        if (isset($tasks[$taskId])) {
            $tasks[$taskId] = $callback($tasks[$taskId]);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($tasks));
            $ret = $tasks[$taskId];
        }
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
    return $ret;
}

$chunkSize = 1024 * 1024 * 2;

while (true) {
    $task = updateTask($taskId, function($t) { return $t; }); 
    
    if (!$task) break;

    // Перехват отмены и физическое удаление недокачанного файла
    if ($task['status'] === 'cancel' || $task['status'] === 'cancelled') {
        $dstDir = realpath($baseDir . '/' . dirname($task['to']));
        if ($dstDir) @unlink($dstDir . '/' . basename($task['to'])); 
        
        // Меняем статус на 'cancelled', чтобы фронтенд обновил иконку
        if ($task['status'] === 'cancel') {
            updateTask($taskId, function($t) { 
                $t['status'] = 'cancelled'; 
                return $t; 
            });
        }
        break; 
    }

    if ($task['status'] === 'paused') {
        sleep(1); 
        continue;
    }

    if ($task['status'] !== 'running') break;

    $src = realpath($baseDir . '/' . $task['from']);
    $dstDir = realpath($baseDir . '/' . dirname($task['to']));
    
    if (!$src || !$dstDir) {
        updateTask($taskId, function($t) { $t['status'] = 'error'; return $t; });
        break;
    }
    
    $dst = $dstDir . '/' . basename($task['to']);

    $fpSrc = @fopen($src, 'rb');
    if (!$fpSrc) {
        updateTask($taskId, function($t) { $t['status'] = 'error'; return $t; });
        break;
    }

    fseek($fpSrc, $task['offset']);
    $chunk = fread($fpSrc, $chunkSize);
    $isEOF = feof($fpSrc) || strlen($chunk) == 0;
    fclose($fpSrc);

    if (strlen($chunk) > 0) {
        $fpDst = @fopen($dst, $task['offset'] === 0 ? 'wb' : 'ab');
        if ($fpDst) {
            fwrite($fpDst, $chunk);
            fclose($fpDst);
        }
    }

    $newOffset = $task['offset'] + strlen($chunk);

    if ($isEOF) {
        if ($task['type'] === 'cut') @unlink($src);
        updateTask($taskId, function($t) use ($newOffset) {
            $t['offset'] = $newOffset;
            $t['status'] = 'completed';
            return $t;
        });
        break;
    } else {
        updateTask($taskId, function($t) use ($newOffset) {
            $t['offset'] = $newOffset;
            return $t;
        });
    }
    
    usleep(50000); 
}