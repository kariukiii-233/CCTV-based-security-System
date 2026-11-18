<?php
// stream.php?id=CAMERA_ID  - shows a camera's RTSP / http feed live in the browser.
// Browsers cannot play rtsp:// directly, so ffmpeg converts it to a Motion-JPEG stream.
// Needs ffmpeg installed (see DEPLOYMENT.txt). Each open viewer uses one ffmpeg process.
require __DIR__ . '/db.php';
start_session();
$u = $_SESSION['user'] ?? null;
if (!$u || !in_array($u['role'], ['admin', 'operator'], true)) { http_response_code(401); exit('Not allowed'); }
$st = db()->prepare('SELECT rtsp_url, status FROM cameras WHERE camera_id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$c = $st->fetch();
session_write_close();                                   // do not block the rest of the site while streaming
if (!$c || empty($c['rtsp_url']) || $c['status'] !== 'online') { http_response_code(404); exit('No live feed for this camera.'); }

$null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$cmd = array_merge([FFMPEG_PATH, '-hide_banner', '-loglevel', 'error'], ffmpeg_input($c['rtsp_url']),
    ['-an', '-vf', 'scale=960:-2', '-r', '10', '-q:v', '6', '-f', 'mpjpeg', '-boundary_tag', 'frame', 'pipe:1']);
$p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes);
if (!is_resource($p)) { http_response_code(500); exit('Could not start ffmpeg. Check FFMPEG_PATH in db.php.'); }

while (ob_get_level()) ob_end_clean();
set_time_limit(0); ignore_user_abort(true);
header('Content-Type: multipart/x-mixed-replace; boundary=frame');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
$until = time() + 4 * 3600;                              // safety: one viewer session lasts at most 4 hours
while (!feof($pipes[1]) && time() < $until) {
    $d = fread($pipes[1], 16384);
    if ($d === false || $d === '') { usleep(20000); continue; }
    echo $d; flush();
    if (connection_aborted()) break;
}
fclose($pipes[1]);
proc_terminate($p);
proc_close($p);
