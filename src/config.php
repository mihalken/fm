<?php
return [
    'panes' => ['left' => 'a', 'right' => 'b'],
    'theme' => 'dark',
    'base_dir' => __DIR__.'/../uploads',
    'refresh_interval' => 5,
    'chunk_size' => 2 * 1024 * 1024, // Чанки по 2 МБ для разных дисков
    'max_edit_size' => 1 * 1024 * 1024,
    'window_title' => 'Simple File Manager',
    'use_trash' => false, // Измените на true для включения корзины (.trash)
    'ffprobe_path' => '/usr/bin/ffprobe',
];