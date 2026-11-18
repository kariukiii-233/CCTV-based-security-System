<?php
// play.php?id=RECORDING_ID  - sends a recorded video to a logged-in admin / security user.
// Supports seeking (HTTP Range) so the video bar works. Add &dl=1 to download.
require __DIR__ . '/db.php';
start_session();
$u = $_SESSION['user'] ?? null;
if (!$u || !in_array($u['role'], ['admin', 'operator'], true)) { http_response_code(401); exit('Not allowed'); }
$st = db()->prepare('SELECT r.file_path, c.camera_code FROM recordings r JOIN cameras c ON c.camera_id = r.camera_id WHERE r.recording_id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$r = $st->fetch();
$path = $r ? recording_path($r['file_path']) : null;
if (!$path) { http_response_code(404); exit('This video file was not found on the server.'); }
session_write_close();

$size = filesize($path); $start = 0; $end = $size - 1;
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=0');
if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . basename($path) . '"');
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); $end = $size - 1; }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$f = fopen($path, 'rb'); fseek($f, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($f) && !connection_aborted()) {
    $d = fread($f, min(65536, $left)); if ($d === false) break;
    echo $d; $left -= strlen($d); flush();
}
fclose($f);
