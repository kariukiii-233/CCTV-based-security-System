<?php
// =====================================================================
//  install.php  -  one-time setup. Open http://localhost/cctv/install.php
//  Creates the database + tables + the 2 accounts, and saves config.php.
//  No phpMyAdmin needed. It locks itself afterwards (install.lock).
// =====================================================================
$lock = __DIR__ . '/install.lock';
$locked = is_file($lock);
$msg = ''; $ok = false;
$host = trim($_POST['host'] ?? 'localhost');
$user = trim($_POST['user'] ?? 'root');
$pass = (string)($_POST['pass'] ?? '');
$name = trim($_POST['name'] ?? 'cctv_system');

// Split SQL into statements, ignoring ; inside quotes and -- comments
function split_sql(string $sql): array {
    $out = []; $cur = ''; $q = ''; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== '') {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $n) { $cur .= $sql[++$i]; }
            elseif ($c === $q) {
                if ($i + 1 < $n && $sql[$i + 1] === $q) { $cur .= $sql[++$i]; } else { $q = ''; }
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
        if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-') {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $cur .= "\n"; continue;
        }
        if ($c === ';') { if (trim($cur) !== '') $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}

// Turn the MySQL script into SQLite-compatible SQL
function to_sqlite(string $sql): string {
    $sql = preg_replace('/\bINT\s+(UNSIGNED\s+)?(NOT NULL\s+)?AUTO_INCREMENT/i', 'INTEGER', $sql);
    $sql = str_ireplace('AUTO_INCREMENT', '', $sql);
    $sql = preg_replace('/ENUM\([^)]*\)/i', 'TEXT', $sql);
    $sql = preg_replace('/\)\s*ENGINE=[^;]*;/i', ');', $sql);
    $sql = preg_replace('/\bUNSIGNED\b/i', '', $sql);
    return preg_replace('/ON UPDATE CURRENT_TIMESTAMP/i', '', $sql);
}
$mode = ($_POST['mode'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($mode === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) throw new Exception("PHP's SQLite extension is off. In XAMPP: Config > php.ini, remove the ; before extension=pdo_sqlite, then restart Apache.");
            $dir = __DIR__ . '/data';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception('Could not create the data folder.');
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            @unlink($dir . '/cctv.sqlite');
            $sql = @file_get_contents(__DIR__ . '/database.sql');
            if ($sql === false) throw new Exception('database.sql was not found next to install.php.');
            $sql = to_sqlite(preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql));
            $pdo = new PDO('sqlite:' . $dir . '/cctv.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (split_sql($sql) as $stmt) $pdo->exec($stmt);
            if (file_put_contents(__DIR__ . '/config.php', "<?php\n// written by install.php\ndefine('DB_DRIVER', 'sqlite');\n") === false) throw new Exception('Could not write config.php. Is the folder writable?');
            file_put_contents($lock, date('c'));
            $ok = true;
            $name = 'built-in SQLite database';
        } else {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new Exception('Database name may only contain letters, numbers and underscores.');
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = @file_get_contents(__DIR__ . '/database.sql');
        if ($sql === false) throw new Exception('database.sql was not found next to install.php.');
        // drop the CREATE DATABASE / USE lines; we use the name typed above
        $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");
        foreach (split_sql($sql) as $stmt) $pdo->exec($stmt);

        $cfg = "<?php\n// written by install.php\ndefine('DB_DRIVER', 'mysql');\ndefine('DB_HOST', " . var_export($host, true) . ");\n"
             . "define('DB_NAME', " . var_export($name, true) . ");\n"
             . "define('DB_USER', " . var_export($user, true) . ");\n"
             . "define('DB_PASS', " . var_export($pass, true) . ");\n";
        if (file_put_contents(__DIR__ . '/config.php', $cfg) === false) throw new Exception('Could not write config.php. Is the folder writable?');
        file_put_contents($lock, date('c'));
        $ok = true;
        }
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (strpos($m, 'Access denied') !== false) $m .= "\n\nThe MySQL user or password is wrong. On a fresh XAMPP, user is root and the password is empty.";
        elseif (strpos($m, 'refused') !== false || strpos($m, '2002') !== false) $m .= "\n\nMySQL is not running. Start it in the XAMPP Control Panel.";
        $msg = $m;
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install - CCTV-Based Security System</title><link rel="icon" href="data:,">
<style>
:root{--bg:#0E1318;--panel:#171E25;--line:#2B3038;--accent:#5FA648;--text:#E7ECEE;--muted:#8A97A3;--alert:#D9614A}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--bg);color:var(--text);font-family:-apple-system,"Segoe UI",Arial,sans-serif}
.card{width:100%;max-width:460px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:28px}
h1{margin:0 0 6px;font-size:18px}p{color:var(--muted);font-size:13px;line-height:1.6;margin:0 0 18px}
label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}input{width:100%;padding:10px 12px;margin-bottom:14px;border-radius:6px;border:1px solid var(--line);background:#0F151A;color:var(--text);font-size:14px}
button,a.btn{display:block;text-align:center;width:100%;padding:12px;border:0;border-radius:6px;background:var(--accent);color:#0E1318;font-weight:600;font-size:14px;cursor:pointer;text-decoration:none}
.err{white-space:pre-wrap;margin-bottom:14px;padding:10px 12px;border:1px solid var(--alert);border-radius:6px;background:#2A1712;color:#F0B4A6;font-size:13px}
code{color:var(--text)}
</style></head><body><div class="card">
<?php if ($ok): ?>
  <h1>Installation complete</h1>
  <p>The database <code><?= htmlspecialchars($name) ?></code> is ready.<br><br>
  Administrator: <code>admin / admin123</code><br>Security personnel: <code>security / security123</code></p>
  <a class="btn" href="login.php">Go to the login page</a>
<?php elseif ($locked): ?>
  <h1>Already installed</h1>
  <p>This system has already been set up. To run the installer again, delete <code>install.lock</code> (and <code>config.php</code>) from the cctv folder.</p>
  <a class="btn" href="login.php">Go to the login page</a>
<?php else: ?>
  <h1>Install the CCTV system</h1>
  <p>Choose where to store the data. The first option needs no MySQL and no password. It creates the database, tables and both accounts.</p>
  <?php if ($msg): ?><div class="err"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <form method="post">
    <label style="color:var(--text);font-size:14px"><input type="radio" name="mode" value="sqlite" style="width:auto;margin-right:8px" <?= $mode==='sqlite'?'checked':'' ?>>Built-in database (recommended, no password)</label>
    <label style="color:var(--text);font-size:14px"><input type="radio" name="mode" value="mysql" style="width:auto;margin-right:8px" <?= $mode==='mysql'?'checked':'' ?>>MySQL (fill in the details below)</label>
    <label>MySQL host</label><input name="host" value="<?= htmlspecialchars($host) ?>">
    <label>MySQL user</label><input name="user" value="<?= htmlspecialchars($user) ?>">
    <label>MySQL password (leave empty if none)</label><input name="pass" type="password" value="">
    <label>Database name</label><input name="name" value="<?= htmlspecialchars($name) ?>">
    <button type="submit">Install</button>
  </form>
<?php endif; ?>
</div></body></html>
