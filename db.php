<?php
// =====================================================================
//  db.php  -  database connection + shared helpers
//  If your MySQL has a password, or a different name, change the 4 lines below.
//  XAMPP default: user "root", empty password.
// =====================================================================
// install.php writes config.php for you; if it exists, it overrides the values below.
if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', 'cctv_system');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_DRIVER') || define('DB_DRIVER', 'mysql');
// Path to ffmpeg (used for recording and for showing RTSP cameras in the browser).
// Windows example: 'C:\\ffmpeg\\bin\\ffmpeg.exe'.  If ffmpeg is on your PATH, leave as 'ffmpeg'.
defined('FFMPEG_PATH') || define('FFMPEG_PATH', 'ffmpeg');
defined('SEGMENT_MINUTES') || define('SEGMENT_MINUTES', 10);   // one video file per camera every N minutes

date_default_timezone_set('Africa/Nairobi');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            if (DB_DRIVER === 'sqlite') {
                $pdo = new PDO('sqlite:' . __DIR__ . '/data/cctv.sqlite', null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec('PRAGMA foreign_keys = ON');
                return $pdo;
            }
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Could not connect to the database.\n"
               . "1) Is MySQL started in the XAMPP Control Panel?\n"
               . "2) Did you import database.sql in phpMyAdmin?\n"
               . "3) Delete install.lock and config.php, then run install.php again to re-enter the MySQL details.");
        }
    }
    return $pdo;
}

function start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function now(): string {
    return date('Y-m-d H:i:s');
}

function setting(string $key, string $default = ''): string {
    $st = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function audit(string $action, string $details = '', ?int $userId = null): void {
    if ($userId === null) {
        $userId = $_SESSION['user']['id'] ?? null;
    }
    $st = db()->prepare('INSERT INTO audit_log (user_id, action, details, ip_address, created_at) VALUES (?,?,?,?,?)');
    $st->execute([$userId, $action, mb_substr($details, 0, 255), $_SERVER['REMOTE_ADDR'] ?? '', now()]);
}

// ---------------------------------------------------------------------
//  Camera helpers shared by api.php, stream.php, play.php and recorder.php
// ---------------------------------------------------------------------
function valid_camera_source(string $u): bool {
    return (bool)preg_match('#^(rtsps?|https?)://[^\s]+$#i', $u) || (bool)preg_match('#^samples/[A-Za-z0-9_.-]+\.(mp4|mkv|avi)$#', $u);
}
// ffmpeg input arguments for a camera address (rtsp://, http:// or a demo file in samples/)
function ffmpeg_input(string $u): array {
    if (preg_match('#^rtsps?://#i', $u)) return ['-rtsp_transport', 'tcp', '-i', $u];
    if (preg_match('#^https?://#i', $u))  return ['-i', $u];
    return ['-re', '-stream_loop', '-1', '-i', __DIR__ . '/' . $u];            // demo clip, played as if live
}
function camera_host_port(string $u): ?array {
    if (!preg_match('#^(rtsps?|https?)://(?:[^@/]*@)?([^:/\s]+)(?::(\d+))?#i', $u, $m)) return null;
    $port = $m[3] ?? (stripos($m[1], 'rtsp') === 0 ? 554 : (strtolower($m[1]) === 'https' ? 443 : 80));
    return [$m[2], (int)$port];
}
function camera_reachable(string $u): bool {
    $hp = camera_host_port($u);
    if (!$hp) return is_file(__DIR__ . '/' . $u);
    $e = 0; $es = '';
    $f = @fsockopen($hp[0], $hp[1], $e, $es, 2);
    if ($f) { fclose($f); return true; }
    return false;
}
function recordings_dir(): string {
    $d = __DIR__ . '/recordings';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n");
    return $d;
}
// Safe full path of a recording's file, or null if missing / outside recordings/
function recording_path(?string $rel): ?string {
    if (!$rel) return null;
    $base = realpath(recordings_dir());
    $full = realpath(__DIR__ . '/' . $rel);
    if (!$base || !$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) return null;
    return $full;
}
function delete_recording_file(?string $rel): void {
    $p = recording_path($rel);
    if ($p) @unlink($p);
}
// Deletes finished recordings (files + rows) older than the retention period. Returns how many.
function purge_old_recordings(): int {
    $days = max(1, (int)setting('retention_days', '30'));
    $cut = date('Y-m-d H:i:s', time() - $days * 86400);
    $st = db()->prepare('SELECT recording_id, file_path FROM recordings WHERE end_time IS NOT NULL AND end_time < ? AND (file_path IS NULL OR file_path NOT LIKE \'recordings/demo/%\')');
    $st->execute([$cut]);
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        delete_recording_file($r['file_path']);
        db()->prepare('DELETE FROM recordings WHERE recording_id = ?')->execute([$r['recording_id']]);
        $n++;
    }
    return $n;
}
function set_setting(string $k, string $v): void {
    $x = db()->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?'); $x->execute([$k]);
    if ((int)$x->fetchColumn()) db()->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$v, $k]);
    else db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$k, $v]);
}
