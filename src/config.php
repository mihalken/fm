<?php
return [
    'panes' => ['left' => 'a', 'right' => 'b'],
    'theme' => 'dark',
    'base_dir' => __DIR__ . '/uploads',
    'refresh_interval' => 30,
    'tasks_file' => __DIR__ . '/tasks.json',
    'chunk_size' => 100 * 1024 * 1024,
    'max_edit_size' => 1 * 1024 * 1024
];