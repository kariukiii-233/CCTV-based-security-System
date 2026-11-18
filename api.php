<?php
// =====================================================================
//  api.php  -  every database read/write the system does goes through here.
//  Roles are checked on the server, so hiding a button is never the only protection.
// =====================================================================
require __DIR__ . '/db.php';
start_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

$user = $_SESSION['user'] ?? null;
if (!$user) fail('Not logged in', 401);

$action = $_GET['action'] ?? '';
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $tok)) fail('Session expired. Refresh the page.', 403);
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
}

function need(array $roles): void {
    global $user;
    if (!in_array($user['role'], $roles, true)) fail('You do not have permission to do that.', 403);
}
function s($v, int $max = 255): string { return mb_substr(trim((string)$v), 0, $max); }
function pick($v, array $allowed, string $default): string { return in_array($v, $allowed, true) ? $v : $default; }
function valid_date(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function raise_alert(int $cameraId, string $type, string $msg): void {
    db()->prepare('INSERT INTO alerts (camera_id, alert_type, message, created_at) VALUES (?,?,?,?)')
        ->execute([$cameraId, $type, $msg, now()]);
}
function camera_rows(): array {
    global $user;
    $rows = db()->query(
        "SELECT c.camera_id, c.camera_code, c.zone_id, c.location, c.ip_address, c.stream_url, c.rtsp_url, c.status, c.installed_on,
                z.zone_name,
                (SELECT r.recording_id FROM recordings r WHERE r.camera_id = c.camera_id AND r.end_time IS NULL
                  ORDER BY r.recording_id DESC LIMIT 1) AS active_recording
           FROM cameras c JOIN zones z ON z.zone_id = c.zone_id
          ORDER BY c.camera_code"
    )->fetchAll();
    foreach ($rows as &$r) {                       // camera passwords live inside rtsp_url: only admins may see them
        $r['has_rtsp'] = !empty($r['rtsp_url']);
        if ($user['role'] !== 'admin') unset($r['rtsp_url']);
    }
    return $rows;
}

try {
    $pdo = db();
    switch ($action) {

    // ---------------------------------------------------------- session
    case 'me':
        out(['user' => $user]);

    // ---------------------------------------------------------- dashboard
    case 'dashboard': {
        $cams   = camera_rows();
        $online = count(array_filter($cams, fn($c) => $c['status'] === 'online'));
        $openInc = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status <> 'closed'")->fetchColumn();
        $unack   = (int)$pdo->query("SELECT COUNT(*) FROM alerts WHERE acknowledged_at IS NULL")->fetchColumn();
        $usedMb  = (float)$pdo->query("SELECT COALESCE(SUM(file_size_mb),0) FROM recordings")->fetchColumn();
        $capGb   = (float)setting('storage_capacity_gb', '500');
        $pct     = $capGb > 0 ? min(100, round($usedMb / 1024 / $capGb * 100, 1)) : 0;
        $recent  = $pdo->query(
            "SELECT i.incident_id, i.title, i.severity, i.status, i.created_at, c.camera_code
               FROM incidents i LEFT JOIN cameras c ON c.camera_id = i.camera_id
              ORDER BY i.created_at DESC, i.incident_id DESC LIMIT 5"
        )->fetchAll();
        $alerts  = $pdo->query(
            "SELECT a.alert_id, a.alert_type, a.message, a.created_at, c.camera_code
               FROM alerts a JOIN cameras c ON c.camera_id = a.camera_id
              WHERE a.acknowledged_at IS NULL
              ORDER BY a.created_at DESC, a.alert_id DESC LIMIT 6"
        )->fetchAll();
        out([
            'cameras' => $cams,
            'stats' => [
                'cameras_total' => count($cams), 'cameras_online' => $online,
                'open_incidents' => $openInc, 'open_alerts' => $unack,
                'storage_pct' => $pct, 'storage_used_gb' => round($usedMb / 1024, 1), 'storage_cap_gb' => $capGb,
            ],
            'recent_incidents' => $recent,
            'alerts' => $alerts,
        ]);
    }

    // ---------------------------------------------------------- zones & cameras
    case 'cameras_list':
        need(['admin', 'operator']);
        out(['cameras' => camera_rows(), 'zones' => $pdo->query('SELECT * FROM zones ORDER BY zone_name')->fetchAll()]);

    case 'zone_save': {
        need(['admin']);
        $id = (int)($body['zone_id'] ?? 0);
        $name = s($body['zone_name'] ?? '', 80);
        $bld  = s($body['building'] ?? '', 80);
        if ($name === '') fail('Zone name is required.');
        if ($id) {
            $pdo->prepare('UPDATE zones SET zone_name = ?, building = ? WHERE zone_id = ?')->execute([$name, $bld ?: null, $id]);
        } else {
            $pdo->prepare('INSERT INTO zones (zone_name, building) VALUES (?,?)')->execute([$name, $bld ?: null]);
        }
        audit('zone_save', $name);
        out(['ok' => true]);
    }

    case 'zone_delete': {
        need(['admin']);
        $id = (int)($body['zone_id'] ?? 0);
        $pdo->prepare('DELETE FROM zones WHERE zone_id = ?')->execute([$id]);
        audit('zone_delete', "Zone #$id");
        out(['ok' => true]);
    }

    case 'camera_save': {
        need(['admin']);
        $id     = (int)($body['camera_id'] ?? 0);
        $code   = s($body['camera_code'] ?? '', 20);
        $zone   = (int)($body['zone_id'] ?? 0);
        $loc    = s($body['location'] ?? '', 120);
        $ip     = s($body['ip_address'] ?? '', 45);
        $stream = s($body['stream_url'] ?? '', 255);
        $rtsp   = s($body['rtsp_url'] ?? '', 255);
        $status = pick($body['status'] ?? '', ['online', 'offline', 'maintenance'], 'online');
        $inst   = s($body['installed_on'] ?? '', 10);

        if ($code === '' || $loc === '') fail('Camera code and location are required.');
        $z = $pdo->prepare('SELECT COUNT(*) FROM zones WHERE zone_id = ?'); $z->execute([$zone]);
        if (!(int)$z->fetchColumn()) fail('Choose a zone for this camera.');
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) fail('That IP address is not valid.');
        if ($stream !== '' && strtolower($stream) !== 'webcam' && !preg_match('#^https?://#i', $stream)) fail('Stream URL must be the word webcam, or start with http:// or https://');
        if (strtolower($stream) === 'webcam') $stream = 'webcam';
        if ($rtsp !== '' && !valid_camera_source($rtsp)) fail('Recording address must start with rtsp://, http:// or https://');
        if ($inst !== '' && !valid_date($inst)) fail('Installed date must look like 2026-10-14.');

        if ($id) {
            $old = $pdo->prepare('SELECT status FROM cameras WHERE camera_id = ?'); $old->execute([$id]);
            $oldStatus = $old->fetchColumn();
            if ($oldStatus === false) fail('Camera not found.', 404);
            $pdo->prepare('UPDATE cameras SET camera_code=?, zone_id=?, location=?, ip_address=?, stream_url=?, rtsp_url=?, status=?, installed_on=? WHERE camera_id=?')
                ->execute([$code, $zone, $loc, $ip ?: null, $stream ?: null, $rtsp ?: null, $status, $inst ?: null, $id]);
            if ($oldStatus !== $status) {
                audit('camera_status', "$code set to $status");
                if ($status === 'offline') raise_alert($id, 'offline', "$code went offline");
            }
            audit('camera_edit', $code);
        } else {
            $pdo->prepare('INSERT INTO cameras (camera_code, zone_id, location, ip_address, stream_url, rtsp_url, status, installed_on, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $zone, $loc, $ip ?: null, $stream ?: null, $rtsp ?: null, $status, $inst ?: null, now()]);
            audit('camera_add', $code);
        }
        out(['ok' => true]);
    }

    case 'camera_delete': {
        need(['admin']);
        $id = (int)($body['camera_id'] ?? 0);
        $pdo->prepare('DELETE FROM cameras WHERE camera_id = ?')->execute([$id]);
        audit('camera_delete', "Camera #$id");
        out(['ok' => true]);
    }

    // ---------------------------------------------------------- incidents
    case 'incidents_list': {
        $where = []; $args = [];
        $st = $_GET['status'] ?? '';
        if (in_array($st, ['open', 'under_review', 'closed'], true)) { $where[] = 'i.status = ?'; $args[] = $st; }
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') { $where[] = 'i.title LIKE ?'; $args[] = '%' . $q . '%'; }
        $sql = "SELECT i.incident_id, i.camera_id, i.title, i.description, i.severity, i.status, i.created_at, i.closed_at,
                       c.camera_code, u.full_name AS reporter
                  FROM incidents i
                  LEFT JOIN cameras c ON c.camera_id = i.camera_id
                  LEFT JOIN users u   ON u.user_id   = i.reported_by"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY i.created_at DESC, i.incident_id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql); $stmt->execute($args);
        out(['incidents' => $stmt->fetchAll()]);
    }

    case 'incident_save': {
        need(['admin', 'operator']);
        $id    = (int)($body['incident_id'] ?? 0);
        $cam   = (int)($body['camera_id'] ?? 0);
        $title = s($body['title'] ?? '', 150);
        $desc  = s($body['description'] ?? '', 2000);
        $sev   = pick($body['severity'] ?? '', ['low', 'medium', 'high'], 'medium');
        $stat  = pick($body['status'] ?? '', ['open', 'under_review', 'closed'], 'open');
        if ($title === '') fail('Give the incident a short title.');
        if ($cam) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM cameras WHERE camera_id = ?'); $c->execute([$cam]);
            if (!(int)$c->fetchColumn()) fail('That camera does not exist.');
        }
        if ($id) {
            $old = $pdo->prepare('SELECT status, closed_at FROM incidents WHERE incident_id = ?'); $old->execute([$id]);
            $o = $old->fetch();
            if (!$o) fail('Incident not found.', 404);
            $closedAt = $stat === 'closed' ? ($o['closed_at'] ?: now()) : null;
            $pdo->prepare('UPDATE incidents SET camera_id=?, title=?, description=?, severity=?, status=?, closed_at=? WHERE incident_id=?')
                ->execute([$cam ?: null, $title, $desc ?: null, $sev, $stat, $closedAt, $id]);
            audit('incident_edit', $title);
        } else {
            $closedAt = $stat === 'closed' ? now() : null;
            $pdo->prepare('INSERT INTO incidents (camera_id, reported_by, title, description, severity, status, created_at, closed_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$cam ?: null, $user['id'], $title, $desc ?: null, $sev, $stat, now(), $closedAt]);
            audit('incident_add', $title);
        }
        out(['ok' => true]);
    }

    case 'incident_status': {
        need(['admin', 'operator']);
        $id   = (int)($body['incident_id'] ?? 0);
        $stat = pick($body['status'] ?? '', ['open', 'under_review', 'closed'], '');
        if ($stat === '') fail('Unknown status.');
        $closedAt = $stat === 'closed' ? now() : null;
        $pdo->prepare('UPDATE incidents SET status = ?, closed_at = ? WHERE incident_id = ?')->execute([$stat, $closedAt, $id]);
        audit('incident_status', "Incident #$id -> $stat");
        out(['ok' => true]);
    }

    case 'incident_delete': {
        need(['admin']);
        $id = (int)($body['incident_id'] ?? 0);
        $pdo->prepare('DELETE FROM incidents WHERE incident_id = ?')->execute([$id]);
        audit('incident_delete', "Incident #$id");
        out(['ok' => true]);
    }

    // ---------------------------------------------------------- recordings
    case 'recordings_list': {
        need(['admin', 'operator']);
        $where = []; $args = [];
        $cam = (int)($_GET['camera_id'] ?? 0);
        if ($cam) { $where[] = 'r.camera_id = ?'; $args[] = $cam; }
        $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
        if ($from !== '' && valid_date($from)) { $where[] = 'r.start_time >= ?'; $args[] = $from . ' 00:00:00'; }
        if ($to   !== '' && valid_date($to))   { $where[] = 'r.start_time <= ?'; $args[] = $to . ' 23:59:59'; }
        $sql = "SELECT r.recording_id, r.camera_id, r.start_time, r.end_time, r.file_path, r.file_size_mb, r.started_by, c.camera_code
                  FROM recordings r JOIN cameras c ON c.camera_id = r.camera_id"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY r.start_time DESC, r.recording_id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql); $stmt->execute($args);
        out(['recordings' => $stmt->fetchAll()]);
    }

    case 'recording_start': {
        need(['admin', 'operator']);
        $cam = (int)($body['camera_id'] ?? 0);
        $c = $pdo->prepare('SELECT camera_code, status, rtsp_url FROM cameras WHERE camera_id = ?'); $c->execute([$cam]);
        $row = $c->fetch();
        if (!$row) fail('Camera not found.', 404);
        if (!empty($row['rtsp_url'])) fail($row['camera_code'] . ' is recorded automatically by the recorder service.');
        if ($row['status'] !== 'online') fail($row['camera_code'] . ' is not online, so it cannot record.');
        $a = $pdo->prepare('SELECT COUNT(*) FROM recordings WHERE camera_id = ? AND end_time IS NULL'); $a->execute([$cam]);
        if ((int)$a->fetchColumn()) fail($row['camera_code'] . ' is already recording.');
        $path = 'recordings/' . preg_replace('/\W+/', '', $row['camera_code']) . '_' . date('Ymd_His') . '.mp4';
        $pdo->prepare('INSERT INTO recordings (camera_id, start_time, file_path, file_size_mb, started_by) VALUES (?,?,?,0,?)')
            ->execute([$cam, now(), $path, $user['id']]);
        audit('recording_start', $row['camera_code']);
        out(['ok' => true]);
    }

    case 'recording_stop': {
        need(['admin', 'operator']);
        $id = (int)($body['recording_id'] ?? 0);
        $r = $pdo->prepare('SELECT start_time, end_time FROM recordings WHERE recording_id = ?'); $r->execute([$id]);
        $row = $r->fetch();
        if (!$row) fail('Recording not found.', 404);
        if ($row['end_time']) fail('That recording has already stopped.');
        $secs = max(1, time() - strtotime($row['start_time']));
        $mb   = round($secs * 0.25, 2);           // about 2 Mbps video = 0.25 MB per second
        $pdo->prepare('UPDATE recordings SET end_time = ?, file_size_mb = ? WHERE recording_id = ?')->execute([now(), $mb, $id]);
        audit('recording_stop', "Recording #$id");
        out(['ok' => true]);
    }

    case 'recording_delete': {
        need(['admin']);
        $id = (int)($body['recording_id'] ?? 0);
        $f = $pdo->prepare('SELECT file_path FROM recordings WHERE recording_id = ?'); $f->execute([$id]);
        delete_recording_file($f->fetchColumn() ?: null);
        $pdo->prepare('DELETE FROM recordings WHERE recording_id = ?')->execute([$id]);
        audit('recording_delete', "Recording #$id");
        out(['ok' => true]);
    }

    case 'recordings_purge': {
        need(['admin']);
        $days = max(1, (int)setting('retention_days', '30'));
        $n = purge_old_recordings();
        audit('recordings_purge', "$n recordings older than $days days");
        out(['ok' => true, 'deleted' => $n, 'days' => $days]);
    }

    // ---------------------------------------------------------- system health (admin)
    case 'health': {
        need(['admin']);
        $hb = setting('recorder_heartbeat', '');
        $age = $hb !== '' ? time() - strtotime($hb) : null;
        $ff = false;
        try {
            $p = @proc_open([FFMPEG_PATH, '-version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($p)) { $o = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); $ff = stripos($o, 'ffmpeg version') !== false; }
        } catch (Throwable $e) {}
        $cams = $pdo->query("SELECT c.camera_id, c.camera_code, c.location, c.status, c.rtsp_url,
                (SELECT MAX(start_time) FROM recordings r WHERE r.camera_id = c.camera_id) AS last_recording,
                (SELECT COALESCE(SUM(file_size_mb),0) FROM recordings r WHERE r.camera_id = c.camera_id) AS used_mb
              FROM cameras c ORDER BY c.camera_code")->fetchAll();
        foreach ($cams as &$c) { $c['recorded'] = !empty($c['rtsp_url']); unset($c['rtsp_url']); }
        $dir = recordings_dir();
        out([
            'recorder_running' => $age !== null && $age < 60,
            'recorder_age' => $age,
            'ffmpeg' => $ff,
            'retention_days' => (int)setting('retention_days', '30'),
            'used_mb' => (float)$pdo->query('SELECT COALESCE(SUM(file_size_mb),0) FROM recordings')->fetchColumn(),
            'free_mb' => round((float)@disk_free_space($dir) / 1048576, 0),
            'total_mb' => round((float)@disk_total_space($dir) / 1048576, 0),
            'cameras' => $cams,
        ]);
    }

    // ---------------------------------------------------------- alerts
    case 'alert_ack': {
        need(['admin', 'operator']);
        $id = (int)($body['alert_id'] ?? 0);
        $pdo->prepare('UPDATE alerts SET acknowledged_by = ?, acknowledged_at = ? WHERE alert_id = ? AND acknowledged_at IS NULL')
            ->execute([$user['id'], now(), $id]);
        audit('alert_ack', "Alert #$id");
        out(['ok' => true]);
    }

    case 'alert_test': {
        need(['admin', 'operator']);
        if (setting('motion_alerts', '1') !== '1') fail('Motion alerts are switched off in Settings.');
        $cam = (int)($body['camera_id'] ?? 0);
        $c = $pdo->prepare('SELECT camera_code, location FROM cameras WHERE camera_id = ?'); $c->execute([$cam]);
        $row = $c->fetch();
        if (!$row) fail('Camera not found.', 404);
        raise_alert($cam, 'motion', 'Motion detected (test) at ' . $row['location']);
        audit('alert_test', $row['camera_code']);
        out(['ok' => true]);
    }

    // ---------------------------------------------------------- users
    case 'users_list':
        need(['admin']);
        out(['users' => $pdo->query('SELECT user_id, full_name, username, email, role, status, last_login, created_at FROM users ORDER BY full_name')->fetchAll()]);

    case 'user_save': {
        need(['admin']);
        $id    = (int)($body['user_id'] ?? 0);
        $name  = s($body['full_name'] ?? '', 100);
        $uname = s($body['username'] ?? '', 50);
        $email = s($body['email'] ?? '', 100);
        $role  = pick($body['role'] ?? '', ['admin', 'operator', 'management'], 'operator');
        $stat  = pick($body['status'] ?? '', ['active', 'disabled'], 'active');
        $pass  = (string)($body['password'] ?? '');
        if ($name === '') fail('Full name is required.');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $uname)) fail('Username must be 3-50 letters, numbers, dots, dashes or underscores.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email address is not valid.');
        if ($id === $user['id'] && ($role !== 'admin' || $stat !== 'active')) fail('You cannot remove your own admin access.');
        if ($id) {
            if ($pass !== '' && strlen($pass) < 8) fail('Password must be at least 8 characters.');
            $pdo->prepare('UPDATE users SET full_name=?, username=?, email=?, role=?, status=? WHERE user_id=?')
                ->execute([$name, $uname, $email ?: null, $role, $stat, $id]);
            if ($pass !== '') {
                $pdo->prepare('UPDATE users SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE user_id=?')
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            audit('user_edit', $uname);
        } else {
            if (strlen($pass) < 8) fail('Password must be at least 8 characters.');
            $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role, status, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $uname, $email ?: null, password_hash($pass, PASSWORD_DEFAULT), $role, $stat, now()]);
            audit('user_add', $uname);
        }
        out(['ok' => true]);
    }

    case 'user_delete': {
        need(['admin']);
        $id = (int)($body['user_id'] ?? 0);
        if ($id === $user['id']) fail('You cannot delete your own account.');
        $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$id]);
        audit('user_delete', "User #$id");
        out(['ok' => true]);
    }

    case 'password_change': {
        $cur = (string)($body['current'] ?? ''); $new = (string)($body['new'] ?? '');
        if (strlen($new) < 8) fail('New password must be at least 8 characters.');
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?'); $st->execute([$user['id']]);
        if (!password_verify($cur, (string)$st->fetchColumn())) fail('Your current password is not correct.');
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        audit('password_change', 'Changed own password');
        out(['ok' => true]);
    }

    case 'audit_list':
        need(['admin']);
        out(['log' => $pdo->query(
            "SELECT a.log_id, a.action, a.details, a.created_at, u.username
               FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id
              ORDER BY a.created_at DESC, a.log_id DESC LIMIT 40")->fetchAll()]);

    // ---------------------------------------------------------- settings
    case 'settings_get': {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $map = []; foreach ($rows as $r) $map[$r['setting_key']] = $r['setting_value'];
        out(['settings' => $map]);
    }

    case 'settings_save': {
        need(['admin']);
        $clean = [
            'org_name'            => s($body['org_name'] ?? '', 100),
            'storage_capacity_gb' => (string)max(1, (float)($body['storage_capacity_gb'] ?? 500)),
            'retention_days'      => (string)min(3650, max(1, (int)($body['retention_days'] ?? 30))),
            'motion_alerts'       => !empty($body['motion_alerts']) ? '1' : '0',
            'auto_archive'        => !empty($body['auto_archive']) ? '1' : '0',
            'email_summary'       => !empty($body['email_summary']) ? '1' : '0',
        ];
        if ($clean['org_name'] === '') fail('Organisation name cannot be empty.');
        foreach ($clean as $k => $v) {
            $ex = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?'); $ex->execute([$k]);
            if ((int)$ex->fetchColumn()) {
                $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$v, $k]);
            } else {
                $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?,?)')->execute([$k, $v]);
            }
        }
        audit('settings_save', 'System settings updated');
        out(['ok' => true]);
    }

    // ---------------------------------------------------------- reports
    case 'report': {
        $to   = $_GET['to']   ?? date('Y-m-d');
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
        if (!valid_date($from) || !valid_date($to)) fail('Dates must look like 2026-10-14.');
        if ($from > $to) fail('The start date must be before the end date.');
        $a = $from . ' 00:00:00'; $b = $to . ' 23:59:59';

        $group = function (string $sql) use ($pdo, $a, $b) {
            $st = $pdo->prepare($sql); $st->execute([$a, $b]); return $st->fetchAll();
        };
        $byStatus   = $group("SELECT status AS label, COUNT(*) AS n FROM incidents WHERE created_at BETWEEN ? AND ? GROUP BY status");
        $bySeverity = $group("SELECT severity AS label, COUNT(*) AS n FROM incidents WHERE created_at BETWEEN ? AND ? GROUP BY severity");
        $byCamera   = $group("SELECT COALESCE(c.camera_code, 'No camera') AS label, COUNT(*) AS n
                                FROM incidents i LEFT JOIN cameras c ON c.camera_id = i.camera_id
                               WHERE i.created_at BETWEEN ? AND ? GROUP BY c.camera_code ORDER BY n DESC");
        $alertsBy   = $group("SELECT alert_type AS label, COUNT(*) AS n FROM alerts WHERE created_at BETWEEN ? AND ? GROUP BY alert_type");
        $rows       = $group("SELECT i.incident_id, i.title, i.severity, i.status, i.created_at, i.closed_at, COALESCE(c.camera_code,'') AS camera
                                FROM incidents i LEFT JOIN cameras c ON c.camera_id = i.camera_id
                               WHERE i.created_at BETWEEN ? AND ? ORDER BY i.created_at DESC LIMIT 500");
        $rec = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(file_size_mb),0) AS mb FROM recordings WHERE start_time BETWEEN ? AND ?");
        $rec->execute([$a, $b]); $recRow = $rec->fetch();
        $camStatus = $pdo->query("SELECT status AS label, COUNT(*) AS n FROM cameras GROUP BY status")->fetchAll();

        out([
            'from' => $from, 'to' => $to,
            'total_incidents' => count($rows),
            'by_status' => $byStatus, 'by_severity' => $bySeverity, 'by_camera' => $byCamera,
            'alerts_by_type' => $alertsBy, 'camera_status' => $camStatus,
            'recordings' => ['count' => (int)$recRow['n'], 'gb' => round((float)$recRow['mb'] / 1024, 1)],
            'incidents' => $rows,
        ]);
    }

    default:
        fail('Unknown action.', 404);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail('That would duplicate an existing record, or the record is still in use.', 409);
    fail('Database error. Check that MySQL is running and database.sql was imported.', 500);
}
