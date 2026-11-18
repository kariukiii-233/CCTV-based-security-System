<?php
require __DIR__ . '/db.php';
start_session();

if (!empty($_SESSION['user'])) {
    header('Location: app.php');
    exit;
}

const MAX_ATTEMPTS = 5;
const LOCK_MINUTES = 15;

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token    = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['login_csrf'], $token)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter both a username and password.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();

        if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
            $mins = (int)ceil((strtotime($u['locked_until']) - time()) / 60);
            $error = "Too many failed attempts. Try again in $mins minute" . ($mins === 1 ? '' : 's') . '.';
        } elseif ($u && $u['status'] === 'disabled') {
            $error = 'This account has been disabled. Contact the administrator.';
        } elseif ($u && password_verify($password, $u['password_hash'])) {
            db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = ? WHERE user_id = ?')
                ->execute([now(), $u['user_id']]);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'       => (int)$u['user_id'],
                'name'     => $u['full_name'],
                'username' => $u['username'],
                'role'     => $u['role'],
            ];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            unset($_SESSION['login_csrf']);
            audit('login', 'Signed in', (int)$u['user_id']);
            header('Location: app.php');
            exit;
        } else {
            if ($u) {
                $attempts = (int)$u['failed_attempts'] + 1;
                $lock = null;
                if ($attempts >= MAX_ATTEMPTS) {
                    $lock = date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60);
                    $attempts = 0;
                }
                db()->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE user_id = ?')
                    ->execute([$attempts, $lock, $u['user_id']]);
                audit('login_failed', 'Wrong password', (int)$u['user_id']);
            }
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CCTV-Based Security System - Sign in</title>
<link rel="icon" href="data:,">
<style>
  :root{--bg:#0E1318;--panel:#171E25;--line:#2B3038;--accent:#5FA648;--accent-dim:#274420;--text:#E7ECEE;--muted:#8A97A3;--alert:#D9614A;--alert-bg:#2A1712;}
  *{box-sizing:border-box;}
  html,body{height:100%;margin:0;}
  body{background:var(--bg);color:var(--text);font-family:-apple-system,"Segoe UI",Inter,Helvetica,Arial,sans-serif;
    display:flex;align-items:center;justify-content:center;padding:24px;
    background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);
    background-size:36px 36px;background-position:center;}
  .wrap{width:100%;max-width:420px;}
  .status{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding:0 4px;font-size:12px;color:var(--muted);font-family:ui-monospace,Consolas,monospace;}
  .status .dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--accent);margin-right:6px;box-shadow:0 0 6px var(--accent);}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:32px 28px 28px;box-shadow:0 20px 60px rgba(0,0,0,.45);}
  h1{margin:0 0 4px;font-size:18px;font-weight:600;}
  .sub{margin:0 0 26px;font-size:13px;color:var(--muted);}
  label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px;}
  .field{margin-bottom:16px;}
  input[type=text],input[type=password]{width:100%;padding:11px 12px;border-radius:6px;border:1px solid var(--line);background:#0F151A;color:var(--text);font-size:14px;outline:none;}
  input:focus{border-color:var(--accent);}
  input.bad{border-color:var(--alert);}
  .pw-row{position:relative;}
  .pw-row button{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);font-size:11px;cursor:pointer;padding:4px 6px;}
  .submit{width:100%;padding:12px;margin-top:6px;border-radius:6px;border:1px solid var(--accent-dim);background:var(--accent);color:#0E1318;font-size:14px;font-weight:600;cursor:pointer;}
  .submit:hover{filter:brightness(1.08);}
  .error{margin-top:14px;padding:10px 12px;border-radius:6px;background:var(--alert-bg);border:1px solid var(--alert);color:#F0B4A6;font-size:13px;}
  .hint{margin-top:18px;font-size:11px;color:var(--muted);text-align:center;line-height:1.6;}
  .hint code{color:var(--text);}
</style>
</head>
<body>
<div class="wrap">
  <div class="status"><span><span class="dot"></span>SECURE CONNECTION</span><span id="clock">--:--:--</span></div>
  <div class="card">
    <h1>CCTV-Based Security System</h1>
    <p class="sub">Sign in to access the surveillance console.</p>
    <form method="post" action="login.php" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf']) ?>">
      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username" value="<?= htmlspecialchars($username) ?>" class="<?= $error ? 'bad' : '' ?>" autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="pw-row">
          <input id="password" name="password" type="password" autocomplete="current-password" class="<?= $error ? 'bad' : '' ?>">
          <button type="button" id="togglePw">SHOW</button>
        </div>
      </div>
      <button type="submit" class="submit">Log in</button>
      <?php if ($error): ?>
        <div class="error" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
    </form>
    <p class="hint">Administrator: <code>admin / admin123</code><br>Security personnel: <code>security / security123</code></p>
  </div>
</div>
<script>
  var pw = document.getElementById('password'), t = document.getElementById('togglePw');
  t.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    t.textContent = show ? 'HIDE' : 'SHOW';
  });
  function tick(){ document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-GB',{hour12:false}); }
  tick(); setInterval(tick, 1000);
</script>
</body>
</html>
