<?php
// Converts schema.sql to SQLite for local tests.
$sql = file_get_contents(__DIR__ . '/../schema.sql');
$sql = preg_replace('/--.*\n/', "\n", $sql);
$sql = str_replace(['INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', 'UNSIGNED'], ['INTEGER PRIMARY KEY AUTOINCREMENT', '', ''], $sql);
$sql = preg_replace('/,\s*INDEX \([^)]*\)/', '', $sql);
return $sql;
