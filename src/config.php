<?php
return [
    'panes' => ['left' => 'a', 'right' => 'b'],
    'theme' => 'dark',
    'base_dir' => __DIR__ . '/uploads',
    'refresh_interval' => 5,
    'tasks_file' => __DIR__ . '/tasks.json',
    'chunk_size' => 100 * 1024 * 1024 // Размер чанка при копировании (в байтах)
];