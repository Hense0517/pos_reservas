<?php
// backup_db.php - Ejecutar con cron
require_once 'includes/config.php';

$backup_dir = __DIR__ . '/backups/db/';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0777, true);

$fecha = date('Y-m-d_H-i-s');
$filename = "backup_{$fecha}.sql";

$command = sprintf(
    'mysqldump -h %s -u %s %s > %s',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_NAME),
    escapeshellarg($backup_dir . $filename)
);

system($command, $output);

// Eliminar backups antiguos (>30 días)
foreach (glob($backup_dir . '*.sql') as $file) {
    if (filemtime($file) < time() - 30 * 24 * 60 * 60) {
        unlink($file);
    }
}