<?php
// =====================================================================
//  recorder.php  -  the 24/7 recorder. Run it with start-recorder.bat and leave it open.
//  Every 10 seconds it:
//    1. checks each camera that has a recording address (is it reachable?)
//    2. marks the camera online/offline and raises an alert when one drops
//    3. starts ffmpeg to save the video in files (one file every SEGMENT_MINUTES)
//    4. lists the new files in the Recordings page
//    5. deletes footage older than the retention period (Settings page)
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from start-recorder.bat, not from the browser.\n"); }
require __DIR__ . '/db.php';
set_time_limit(0);

$segSeconds = (int)(getenv('CCTV_SEGMENT_SECONDS') ?: SEGMENT_MINUTES * 60);
$base = recordings_dir();

$lock = fopen($base . '/.recorder.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("The recorder is already running.\n");

function say(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }
function folder(string $code): string { return preg_replace('/\W+/', '', $code); }

$procs = []; $fails = []; $retryAt = []; $lastPurge = 0;

function stop_cam(int $id): void {
    global $procs;
    if (!isset($procs[$id])) return;
    @proc_terminate($procs[$id]['proc']); @proc_close($procs[$id]['proc']);
    unset($procs[$id]);
}
function start_cam(array $c): void {
    global $procs, $base, $segSeconds, $retryAt;
    $dir = $base . '/' . folder($c['camera_code']);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $out = str_replace('\\', '/', $dir) . '/%Y%m%d_%H%M%S.mp4';
    $cmd = array_merge([FFMPEG_PATH, '-hide_banner', '-loglevel', 'error'], ffmpeg_input($c['rtsp_url']), [
        '-map', '0:v:0', ...(preg_match('#^https?://#i', $c['rtsp_url']) ? ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p', '-g', '30'] : ['-c:v', 'copy']), '-an',
        '-f', 'segment', '-segment_time', (string)$segSeconds, '-reset_timestamps', '1', '-strftime', '1',
        '-segment_format', 'mp4', '-segment_format_options', 'movflags=frag_keyframe+empty_moov',
        $out,
    ]);
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    // file names are stamped with the local clock; make ffmpeg use the same timezone as PHP (on Linux/Mac; Windows uses the PC clock)
    $env = DIRECTORY_SEPARATOR === '\\' ? null : array_merge(getenv(), ['TZ' => date_default_timezone_get()]);
    $p = @proc_open($cmd, [1 => ['file', $null, 'w'], 2 => ['file', $dir . '/ffmpeg.log', 'a']], $pipes, null, $env);
    if (!is_resource($p)) { say('Could not start ffmpeg for ' . $c['camera_code'] . '. Check FFMPEG_PATH in db.php.'); $retryAt[$c['camera_id']] = time() + 60; return; }
    $procs[$c['camera_id']] = ['proc' => $p, 'code' => $c['camera_code']];
    say('Recording ' . $c['camera_code']);
}
function set_status(array $c, string $to): void {
    db()->prepare('UPDATE cameras SET status = ? WHERE camera_id = ?')->execute([$to, $c['camera_id']]);
    audit('camera_status', $c['camera_code'] . " is $to (recorder)", null);
    if ($to === 'offline') raise_alert_cli((int)$c['camera_id'], $c['camera_code'] . ' went offline');
    say($c['camera_code'] . " is now $to");
}
function raise_alert_cli(int $id, string $msg): void {
    db()->prepare('INSERT INTO alerts (camera_id, alert_type, message, created_at) VALUES (?,?,?,?)')->execute([$id, 'offline', $msg, now()]);
}
function index_files(array $cams): void {
    foreach ($cams as $c) {
        $rel0 = 'recordings/' . folder($c['camera_code']);
        foreach (glob(__DIR__ . '/' . $rel0 . '/*.mp4') ?: [] as $file) {
            $rel = $rel0 . '/' . basename($file);
            $mt = filemtime($file); $mb = round(filesize($file) / 1048576, 2);
            $done = (time() - $mt) > 25;                       // file not touched for 25 s = finished
            $q = db()->prepare('SELECT recording_id, end_time FROM recordings WHERE file_path = ?'); $q->execute([$rel]);
            $row = $q->fetch();
            $dt = DateTime::createFromFormat('Ymd_His', substr(basename($file), 0, 15));
            $start = $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', $mt);
            if (!$row) {
                db()->prepare('INSERT INTO recordings (camera_id, start_time, end_time, file_path, file_size_mb, started_by) VALUES (?,?,?,?,?,NULL)')
                    ->execute([$c['camera_id'], $start, $done ? date('Y-m-d H:i:s', $mt) : null, $rel, $mb]);
            } elseif ($row['end_time'] === null) {
                db()->prepare('UPDATE recordings SET end_time = ?, file_size_mb = ? WHERE recording_id = ?')
                    ->execute([$done ? date('Y-m-d H:i:s', $mt) : null, $mb, $row['recording_id']]);
            }
        }
    }
}

say('Recorder started. Press Ctrl+C (or close this window) to stop it.');
while (true) {
    try {
        set_setting('recorder_heartbeat', now());
        $cams = db()->query("SELECT camera_id, camera_code, rtsp_url, status FROM cameras WHERE rtsp_url IS NOT NULL AND rtsp_url <> ''")->fetchAll();
        $ids = array_column($cams, 'camera_id');
        foreach (array_keys($procs) as $id) if (!in_array($id, $ids)) stop_cam($id);   // camera deleted or address removed

        foreach ($cams as $c) {
            $id = (int)$c['camera_id'];
            if ($c['status'] === 'maintenance') { stop_cam($id); continue; }
            if (camera_reachable($c['rtsp_url'])) {
                $fails[$id] = 0;
                if ($c['status'] === 'offline') set_status($c, 'online');
                if (isset($procs[$id])) {
                    $s = proc_get_status($procs[$id]['proc']);
                    if (!$s['running']) { say($c['camera_code'] . ' recording stopped (ffmpeg exit ' . $s['exitcode'] . '). Restarting in 15 s.'); stop_cam($id); $retryAt[$id] = time() + 15; }
                }
                if (!isset($procs[$id]) && time() >= ($retryAt[$id] ?? 0)) start_cam($c);
            } else {
                $fails[$id] = ($fails[$id] ?? 0) + 1;
                if ($fails[$id] >= 2) {                                     // two misses in a row = really offline
                    stop_cam($id);
                    if ($c['status'] === 'online') set_status($c, 'offline');
                }
            }
        }
        index_files($cams);
        if (time() - $lastPurge > 3600) {
            $n = purge_old_recordings(); $lastPurge = time();
            if ($n) { audit('recordings_purge', "$n recordings older than retention (recorder)", null); say("Deleted $n old recording(s)."); }
        }
    } catch (Throwable $e) {
        say('Problem: ' . $e->getMessage() . ' - will retry.');
    }
    sleep(10);
}
