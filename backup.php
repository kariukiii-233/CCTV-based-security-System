<?php
// backup.php  -  admin only. Downloads a backup of all the system's data (not the video files).
require __DIR__ . '/db.php';
start_session();
$u = $_SESSION['user'] ?? null;
if (!$u || $u['role'] !== 'admin') { http_response_code(403); exit('Only the administrator can download a backup.'); }
session_write_close();
$stamp = date('Y-m-d_His');
$pdo = db();
audit('backup', 'Backup downloaded', (int)$u['id']);

if (DB_DRIVER === 'sqlite') {
    // A consistent copy of the database file
    $tmp = sys_get_temp_dir() . '/cctv_backup_' . bin2hex(random_bytes(6)) . '.sqlite';
    try { $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'"); }
    catch (Throwable $e) { copy(__DIR__ . '/data/cctv.sqlite', $tmp); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="cctv-backup-' . $stamp . '.sqlite"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// MySQL: a text .sql file (tables + data) that phpMyAdmin > Import can restore
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="cctv-backup-' . $stamp . '.sql"');
echo "-- CCTV system backup, " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
    echo "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";
    $st = $pdo->query("SELECT * FROM `$t`");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r));
        echo "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
    }
    echo "\n";
}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
