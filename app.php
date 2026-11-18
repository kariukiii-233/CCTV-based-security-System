<?php
require __DIR__ . '/db.php';
start_session();
if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
$boot = ['user' => $_SESSION['user'], 'csrf' => $_SESSION['csrf'] ?? ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CCTV-Based Security System</title>
<link rel="icon" href="data:,">
<style>
  :root{--bg:#0E1318;--panel:#171E25;--panel2:#1C242C;--line:#2B3038;--accent:#5FA648;--accent-dim:#274420;--text:#E7ECEE;--muted:#8A97A3;--alert:#D9614A;--alert-bg:#2A1712;--warn:#D9A24A;--warn-bg:#2B2314;}
  *{box-sizing:border-box;}
  html,body{margin:0;min-height:100%;}
  body{background:var(--bg);color:var(--text);font-family:-apple-system,"Segoe UI",Inter,Helvetica,Arial,sans-serif;display:flex;min-height:100vh;}
  .mono{font-family:ui-monospace,Consolas,monospace;}
  button,input,select,textarea{font-family:inherit;}
  a{color:inherit;text-decoration:none;}

  .side{width:220px;flex:0 0 220px;background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:18px 12px;position:sticky;top:0;height:100vh;overflow-y:auto;}
  .brand{display:flex;align-items:center;gap:8px;padding:4px 8px 6px;font-size:13px;font-weight:600;}
  .brand .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 6px var(--accent);}
  .who{padding:0 8px 18px;font-size:11px;color:var(--muted);line-height:1.5;}
  nav{display:flex;flex-direction:column;gap:2px;}
  .nav-item{display:flex;align-items:center;gap:10px;padding:10px;border-radius:6px;border:1px solid transparent;color:var(--muted);font-size:13px;cursor:pointer;background:none;text-align:left;width:100%;}
  .nav-item:hover{background:var(--panel2);color:var(--text);}
  .nav-item.active{background:var(--accent-dim);border-color:var(--accent);color:var(--text);}
  .nav-item .ico{width:16px;text-align:center;font-size:13px;}
  .spacer{flex:1;}
  .divider{height:1px;background:var(--line);margin:10px 4px 12px;}
  .nav-item.logout{color:var(--alert);}
  .nav-item.logout:hover{background:var(--alert-bg);}

  main{flex:1;min-width:0;padding:22px 28px 40px;}
  .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;gap:12px;flex-wrap:wrap;}
  .topbar h1{margin:0;font-size:19px;font-weight:600;}
  .topbar .sub{margin:2px 0 0;font-size:12px;color:var(--muted);}
  .clock{font-size:12px;color:var(--muted);}
  .clock .dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--accent);margin-right:6px;box-shadow:0 0 5px var(--accent);}

  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:24px;}
  .stat{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:14px 16px;}
  .stat .label{font-size:11px;color:var(--muted);margin-bottom:6px;}
  .stat .val{font-size:22px;font-weight:600;}
  .stat .note{font-size:11px;color:var(--muted);margin-top:4px;}
  .val.warn{color:var(--warn);} .val.alert{color:var(--alert);}
  section{margin-bottom:26px;}
  section h2{font-size:12px;margin:0 0 12px;color:var(--muted);font-weight:600;letter-spacing:.04em;text-transform:uppercase;}
  .head-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap;}
  .head-row h2{margin:0;}

  .cam-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;}
  .cam{background:#0B0F13;border:1px solid var(--line);border-radius:8px;aspect-ratio:16/10;display:flex;flex-direction:column;justify-content:space-between;padding:8px 10px;cursor:pointer;text-align:left;color:inherit;width:100%;}
  .cam:hover{border-color:var(--accent);}
  .cam.static{cursor:default;}
  .cam .tag{display:flex;align-items:center;gap:5px;font-size:10px;color:var(--accent);}
  .cam .tag .dot{width:5px;height:5px;border-radius:50%;background:var(--accent);box-shadow:0 0 4px var(--accent);}
  .cam .name{font-size:12px;color:var(--muted);}
  .cam.offline{opacity:.6;} .cam.offline .tag{color:var(--alert);} .cam.offline .tag .dot{background:var(--alert);box-shadow:none;}
  .cam.maintenance .tag{color:var(--warn);} .cam.maintenance .tag .dot{background:var(--warn);box-shadow:none;}

  .list{background:var(--panel);border:1px solid var(--line);border-radius:8px;overflow:hidden;}
  .row{display:flex;align-items:center;gap:14px;padding:12px 16px;border-bottom:1px solid var(--line);font-size:13px;}
  .row:last-child{border-bottom:none;}
  .row.click{cursor:pointer;} .row.click:hover{background:var(--panel2);}
  .row .t1{flex:1;min-width:0;} .row .t1 small{display:block;color:var(--muted);font-size:11px;margin-top:2px;}
  .row .t2{color:var(--muted);font-size:12px;flex:0 0 auto;}
  .badge{font-size:10px;padding:3px 8px;border-radius:20px;border:1px solid var(--line);color:var(--muted);white-space:nowrap;}
  .badge.open,.badge.high,.badge.offline{color:var(--alert);border-color:var(--alert);}
  .badge.under_review,.badge.medium,.badge.maintenance{color:var(--warn);border-color:var(--warn);}
  .badge.closed,.badge.low,.badge.online,.badge.active{color:var(--accent);border-color:var(--accent);}
  .badge.disabled{color:var(--muted);}
  .empty{color:var(--muted);font-size:13px;padding:22px;text-align:center;}

  table{width:100%;border-collapse:collapse;font-size:13px;}
  th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--line);vertical-align:middle;}
  th{font-size:11px;color:var(--muted);font-weight:600;}
  tr:last-child td{border-bottom:none;}
  .scroll{overflow-x:auto;}

  .btn{background:var(--accent);color:#0E1318;border:1px solid var(--accent-dim);padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;}
  .btn:hover{filter:brightness(1.08);} .btn:disabled{opacity:.6;cursor:default;}
  .btn.ghost{background:none;color:var(--text);border-color:var(--line);} .btn.ghost:hover{border-color:var(--accent);}
  .btn.danger{background:none;color:var(--alert);border-color:var(--alert);}
  .btn.small{padding:5px 10px;font-weight:500;}
  .actions{display:flex;gap:6px;flex-wrap:wrap;}

  .filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;}
  .filters label{display:block;font-size:11px;color:var(--muted);margin-bottom:4px;}
  input,select,textarea{background:#0F151A;color:var(--text);border:1px solid var(--line);border-radius:6px;padding:8px 10px;font-size:13px;outline:none;}
  input:focus,select:focus,textarea:focus{border-color:var(--accent);}
  .note-box{font-size:12px;color:var(--muted);background:var(--panel);border:1px dashed var(--line);border-radius:8px;padding:12px 14px;margin-bottom:14px;line-height:1.5;}

  .bar-row{display:flex;align-items:center;gap:10px;font-size:12px;margin-bottom:8px;}
  .bar-row .lbl{width:110px;color:var(--muted);} .bar-row .bar{flex:1;height:8px;background:var(--line);border-radius:4px;overflow:hidden;}
  .bar-row .bar i{display:block;height:100%;background:var(--accent);} .bar-row .n{width:30px;text-align:right;}
  .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:16px;}
  .card h3{margin:0 0 12px;font-size:13px;}

  .overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:20px;z-index:50;}
  .modal{background:var(--panel);border:1px solid var(--line);border-radius:10px;width:100%;max-width:520px;max-height:92vh;overflow-y:auto;padding:22px;}
  .modal.wide{max-width:640px;}
  .modal h3{margin:0 0 16px;font-size:16px;}
  .f{margin-bottom:14px;} .f label{display:block;font-size:12px;color:var(--muted);margin-bottom:5px;}
  .f input,.f select,.f textarea{width:100%;} .f textarea{min-height:90px;resize:vertical;}
  .f.check{display:flex;align-items:center;gap:10px;} .f.check input{width:auto;} .f.check label{margin:0;color:var(--text);}
  .modal .err{display:none;background:var(--alert-bg);border:1px solid var(--alert);color:#F0B4A6;font-size:13px;padding:9px 12px;border-radius:6px;margin-bottom:12px;}
  .modal .foot{display:flex;gap:8px;justify-content:flex-end;margin-top:8px;}
  .detail p{margin:0 0 10px;font-size:13px;line-height:1.6;} .detail .meta{font-size:12px;color:var(--muted);}
  .feed{background:#05080A;border:1px solid var(--line);border-radius:8px;aspect-ratio:16/9;width:100%;overflow:hidden;position:relative;margin-bottom:6px;}
  .feed canvas,.feed img,.feed video{width:100%;height:100%;display:block;object-fit:cover;}
  .feed-note{font-size:11px;color:var(--muted);margin-bottom:14px;}
  .toast{position:fixed;bottom:22px;right:22px;background:var(--panel2);border:1px solid var(--accent);color:var(--text);padding:11px 16px;border-radius:8px;font-size:13px;z-index:80;max-width:340px;}
  .toast.bad{border-color:var(--alert);}

  @media (max-width:760px){
    .row{flex-wrap:wrap;gap:6px 10px;} .row .t1{flex:1 1 100%;}
    body{flex-direction:column;}
    .side{width:100%;flex:none;height:auto;position:static;flex-direction:column;}
    nav{flex-direction:row;flex-wrap:wrap;} .nav-item{width:auto;}
    main{padding:18px 16px 40px;}
  }
  @media print{
    .side,.topbar .clock,.no-print,.toast{display:none !important;}
    body{background:#fff;color:#000;display:block;} main{padding:0;}
    .stat,.card,.list{background:#fff;border-color:#999;} th,td,.row{border-color:#ccc;color:#000;}
    .stat .label,.bar-row .lbl,th{color:#444;} .bar-row .bar{background:#ddd;} .bar-row .bar i{background:#333;}
    .badge{color:#000 !important;border-color:#888 !important;}
  }
</style>
</head>
<body>

<aside class="side">
  <div class="brand"><span class="dot"></span>CCTV SECURITY</div>
  <div class="who" id="who"></div>
  <nav id="nav"></nav>
  <div class="spacer"></div>
  <div class="divider"></div>
  <a class="nav-item logout" href="logout.php"><span class="ico">&#9211;</span>Log out</a>
</aside>

<main>
  <div class="topbar">
    <div><h1 id="pageTitle"></h1><p class="sub" id="pageSub"></p></div>
    <div class="clock mono"><span class="dot"></span><span id="clock">--:--:--</span></div>
  </div>
  <div id="view"></div>
</main>

<script>window.BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script>
(function () {
'use strict';
const U = BOOT.user, CSRF = BOOT.csrf, ROLE = U.role;
const IS_ADMIN = ROLE === 'admin', CAN_OPERATE = ROLE === 'admin' || ROLE === 'operator';
const $ = (s, el) => (el || document).querySelector(s);
const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt = s => s ? String(s).slice(0, 16).replace('T', ' ') : '-';
const label = s => String(s || '').replace('_', ' ').replace(/^./, c => c.toUpperCase());
const roleName = r => ({ admin: 'Administrator', operator: 'Security personnel', management: 'University management' }[r] || label(r));
const view = $('#view');

async function api(action, opts) {
  opts = opts || {};
  const qs = new URLSearchParams(Object.assign({ action }, opts.params || {}));
  const init = { headers: {} };
  if (opts.body !== undefined) {
    init.method = 'POST';
    init.headers['Content-Type'] = 'application/json';
    init.headers['X-CSRF-Token'] = CSRF;
    init.body = JSON.stringify(opts.body);
  }
  const r = await fetch('api.php?' + qs.toString(), init);
  if (r.status === 401) { location.href = 'login.php'; throw new Error('Your session ended.'); }
  let data;
  try { data = await r.json(); } catch (e) { throw new Error('The server sent an unexpected reply. Check that PHP and MySQL are running.'); }
  if (!r.ok) throw new Error(data.error || 'Request failed');
  return data;
}

let toastTimer;
function toast(msg, bad) {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const t = document.createElement('div');
  t.className = 'toast' + (bad ? ' bad' : ''); t.textContent = msg;
  document.body.appendChild(t);
  clearTimeout(toastTimer); toastTimer = setTimeout(() => t.remove(), 3500);
}
async function run(promise, okMsg) {
  try { const r = await promise; if (okMsg) toast(okMsg); return r; }
  catch (e) { toast(e.message, true); return null; }
}

// ---------- modal + form helpers
let modalCleanup = null;
function closeModal() {
  const o = $('.overlay'); if (o) o.remove();
  if (modalCleanup) { modalCleanup(); modalCleanup = null; }
}
function openModal(html, wide) {
  closeModal();
  const o = document.createElement('div');
  o.className = 'overlay';
  o.innerHTML = '<div class="modal' + (wide ? ' wide' : '') + '" role="dialog" aria-modal="true">' + html + '</div>';
  o.addEventListener('mousedown', e => { if (e.target === o) closeModal(); });
  document.body.appendChild(o);
  return o.firstChild;
}
function fieldHtml(f) {
  const v = f.value == null ? '' : f.value;
  const req = f.required ? ' required' : '';
  let input;
  if (f.type === 'select') {
    input = '<select name="' + f.name + '">' + f.options.map(o =>
      '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(v) ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '</select>';
  } else if (f.type === 'textarea') {
    input = '<textarea name="' + f.name + '"' + req + '>' + esc(v) + '</textarea>';
  } else if (f.type === 'checkbox') {
    return '<div class="f check"><input type="checkbox" id="f_' + f.name + '" name="' + f.name + '"' + (v ? ' checked' : '') + '><label for="f_' + f.name + '">' + esc(f.label) + '</label></div>';
  } else {
    input = '<input type="' + (f.type || 'text') + '" name="' + f.name + '" value="' + esc(v) + '"' + req +
            (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') + (f.type === 'number' ? ' step="any" min="0"' : '') + '>';
  }
  return '<div class="f"><label>' + esc(f.label) + '</label>' + input + '</div>';
}
function formModal(cfg) {
  const m = openModal('<h3>' + esc(cfg.title) + '</h3><div class="err" id="ferr"></div><form id="mform" novalidate>' +
    cfg.fields.map(fieldHtml).join('') +
    '<div class="foot"><button type="button" class="btn ghost" id="mcancel">Cancel</button><button type="submit" class="btn">' + esc(cfg.submit || 'Save') + '</button></div></form>');
  $('#mcancel', m).onclick = closeModal;
  const first = $('input,select,textarea', m); if (first) first.focus();
  $('#mform', m).addEventListener('submit', async e => {
    e.preventDefault();
    const vals = {};
    cfg.fields.forEach(f => {
      const el = $('[name="' + f.name + '"]', m);
      vals[f.name] = f.type === 'checkbox' ? el.checked : el.value;
    });
    const btn = $('button[type=submit]', m); btn.disabled = true;
    try { await cfg.onSubmit(vals); closeModal(); }
    catch (er) { const box = $('#ferr', m); box.textContent = er.message; box.style.display = 'block'; btn.disabled = false; }
  });
}
function confirmModal(msg, onYes, yesLabel) {
  const m = openModal('<h3>Please confirm</h3><p style="font-size:13px;line-height:1.6;margin:0 0 16px">' + esc(msg) + '</p>' +
    '<div class="foot"><button class="btn ghost" id="cn">Cancel</button><button class="btn danger" id="cy">' + esc(yesLabel || 'Delete') + '</button></div>');
  $('#cn', m).onclick = closeModal;
  $('#cy', m).onclick = async () => { closeModal(); await onYes(); };
}

// ---------- navigation
const NAV = [
  { id: 'dashboard',  label: 'Dashboard',       ico: '&#9636;', roles: ['admin', 'operator', 'management'], sub: 'Overview of your surveillance network' },
  { id: 'monitoring', label: 'Live monitoring', ico: '&#9678;', roles: ['admin', 'operator'], sub: 'Select a camera to view its feed' },
  { id: 'cameras',    label: 'Cameras',         ico: '&#9635;', roles: ['admin'], sub: 'Add, edit and remove cameras and zones' },
  { id: 'incidents',  label: 'Incidents',       ico: '!',       roles: ['admin', 'operator', 'management'], sub: 'Logged and flagged security events' },
  { id: 'recordings', label: 'Recordings',      ico: '&#9654;', roles: ['admin', 'operator'], sub: 'Search and manage stored footage' },
  { id: 'reports',    label: 'Reports',         ico: '&#9638;', roles: ['admin', 'operator', 'management'], sub: 'Summaries you can print or export' },
  { id: 'health',     label: 'System health',   ico: '&#9829;', roles: ['admin'], sub: 'Recorder, storage and camera status' },
  { id: 'users',      label: 'Users',           ico: '&#9786;', roles: ['admin'], sub: 'Accounts, roles and the activity log' },
  { id: 'settings',   label: 'Settings',        ico: '&#9881;', roles: ['admin', 'operator', 'management'], sub: 'Your password and system preferences' },
].filter(n => n.roles.includes(ROLE));

$('#who').innerHTML = esc(U.name) + '<br>' + esc(roleName(ROLE));
$('#nav').innerHTML = NAV.map(n => '<button class="nav-item" data-page="' + n.id + '"><span class="ico">' + n.ico + '</span>' + n.label + '</button>').join('');
$('#nav').addEventListener('click', e => { const b = e.target.closest('[data-page]'); if (b) location.hash = b.dataset.page; });

const PAGES = {};
let feedTimer = null;
async function route() {
  closeModal();
  clearInterval(feedTimer);
  let id = location.hash.replace('#', '');
  if (!NAV.find(n => n.id === id)) id = 'dashboard';
  const n = NAV.find(x => x.id === id);
  document.querySelectorAll('.nav-item[data-page]').forEach(b => b.classList.toggle('active', b.dataset.page === id));
  $('#pageTitle').textContent = n.label; $('#pageSub').textContent = n.sub;
  view.innerHTML = '<div class="empty">Loading...</div>';
  try { await PAGES[id](); }
  catch (e) { view.innerHTML = '<div class="note-box">' + esc(e.message) + '</div>'; }
}
window.addEventListener('hashchange', route);
const refresh = () => route();

// ---------- shared pieces
function camCard(c, clickable) {
  const st = c.status === 'online' ? (c.active_recording ? 'LIVE - REC' : 'LIVE') : c.status.toUpperCase();
  return '<' + (clickable ? 'button data-cam="' + c.camera_id + '"' : 'div') + ' class="cam ' + esc(c.status) + (clickable ? '' : ' static') + '">' +
    '<div class="tag"><span class="dot"></span>' + st + '</div>' +
    '<div class="name">' + esc(c.camera_code) + ' - ' + esc(c.location) + '</div></' + (clickable ? 'button' : 'div') + '>';
}
function incRow(i) {
  return '<div class="row click" data-inc="' + i.incident_id + '"><div class="t1">' + esc(i.title) +
    '<small>' + esc(i.camera_code || 'No camera') + '</small></div><div class="t2">' + fmt(i.created_at) + '</div>' +
    '<span class="badge ' + esc(i.severity) + '">' + label(i.severity) + '</span><span class="badge ' + esc(i.status) + '">' + label(i.status) + '</span></div>';
}
function alertRow(a) {
  return '<div class="row"><div class="t1">' + esc(a.message) + '<small>' + esc(a.camera_code) + ' - ' + label(a.alert_type) + '</small></div>' +
    '<div class="t2">' + fmt(a.created_at) + '</div>' +
    (CAN_OPERATE ? '<button class="btn small ghost" data-ack="' + a.alert_id + '">Acknowledge</button>' : '') + '</div>';
}
function hookCommon(root, camList) {
  root.querySelectorAll('[data-ack]').forEach(b => b.onclick = async () => { if (await run(api('alert_ack', { body: { alert_id: +b.dataset.ack } }), 'Alert acknowledged')) refresh(); });
  root.querySelectorAll('[data-inc]').forEach(r => r.onclick = () => openIncident(+r.dataset.inc));
  if (camList) root.querySelectorAll('[data-cam]').forEach(b => b.onclick = () => openCamera(+b.dataset.cam));
}

// ---------- dashboard
PAGES.dashboard = async function () {
  const d = await api('dashboard'), s = d.stats;
  view.innerHTML =
    '<div class="stats">' +
      '<div class="stat"><div class="label">Cameras online</div><div class="val">' + s.cameras_online + ' / ' + s.cameras_total + '</div></div>' +
      '<div class="stat"><div class="label">Open incidents</div><div class="val ' + (s.open_incidents ? 'alert' : '') + '">' + s.open_incidents + '</div></div>' +
      '<div class="stat"><div class="label">Unacknowledged alerts</div><div class="val ' + (s.open_alerts ? 'warn' : '') + '">' + s.open_alerts + '</div></div>' +
      '<div class="stat"><div class="label">Storage used</div><div class="val">' + s.storage_pct + '%</div><div class="note">' + s.storage_used_gb + ' of ' + s.storage_cap_gb + ' GB</div></div>' +
    '</div>' +
    '<section><h2>Cameras</h2><div class="cam-grid">' + (d.cameras.map(c => camCard(c, CAN_OPERATE)).join('') || '<div class="empty">No cameras yet.</div>') + '</div></section>' +
    '<section><h2>Alerts waiting</h2><div class="list">' + (d.alerts.map(alertRow).join('') || '<div class="empty">No unacknowledged alerts.</div>') + '</div></section>' +
    '<section><h2>Recent incidents</h2><div class="list">' + (d.recent_incidents.map(incRow).join('') || '<div class="empty">No incidents logged.</div>') + '</div></section>';
  hookCommon(view, true);
};

// ---------- live monitoring + camera viewer
PAGES.monitoring = async function () {
  const d = await api('cameras_list');
  window.__cams = d.cameras;
  view.innerHTML = '<div class="cam-grid">' + (d.cameras.map(c => camCard(c, true)).join('') || '<div class="empty">No cameras yet. Ask an administrator to add one.</div>') + '</div>';
  hookCommon(view, true);
};

async function openCamera(id) {
  const d = await run(api('cameras_list')); if (!d) return;
  const c = d.cameras.find(x => x.camera_id == id); if (!c) return;
  const url = c.stream_url || '';
  let feed;
  if (c.status !== 'online') feed = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:13px">Camera is ' + esc(c.status) + '</div>';
  else if (url === 'webcam') feed = '<video id="webcamfeed" autoplay muted playsinline></video>';
  else if (/\.(mp4|webm)(\?|$)/i.test(url)) feed = '<video src="' + esc(url) + '" autoplay muted controls playsinline></video>';
  else if (url) feed = '<img src="' + esc(url) + '" alt="Live feed from ' + esc(c.camera_code) + '">';
  else if (c.has_rtsp) feed = '<img id="relay" src="stream.php?id=' + c.camera_id + '" alt="Live feed from ' + esc(c.camera_code) + '"><span id="liveclk" class="mono" style="position:absolute;left:12px;bottom:8px;font-size:12px;color:#E7ECEE;text-shadow:0 0 4px #000"></span>';
  else feed = '<div style="display:flex;align-items:center;justify-content:center;height:100%;padding:18px;text-align:center;color:var(--muted);font-size:13px">No camera is connected to ' + esc(c.camera_code) + ' yet.' + (IS_ADMIN ? ' Add its address under Cameras > Edit.' : ' Ask an administrator to connect it.') + '</div>';
  const m = openModal(
    '<h3>' + esc(c.camera_code) + ' - ' + esc(c.location) + '</h3>' +
    '<div class="feed">' + feed + '</div>' +
    '<div class="feed-note">' + (c.has_rtsp && !url ? 'Live video from the camera, converted by the server (ffmpeg). It is also recorded around the clock.' : url === 'webcam' ? 'Live picture from this computer\'s camera. Allow camera access when the browser asks.' : url ? 'Showing the stream set for this camera.' : 'No camera address has been set for this camera.') + '</div>' +
    '<div class="detail"><p class="meta">Zone: ' + esc(c.zone_name) + ' &middot; IP: ' + esc(c.ip_address || 'not set') + ' &middot; Status: ' + label(c.status) + '</p></div>' +
    '<div class="actions">' +
      (c.has_rtsp ? '<button class="btn ghost" disabled>Recorded automatically</button>' : c.active_recording ? '<button class="btn danger" id="recStop">Stop recording</button>' : '<button class="btn" id="recStart"' + (c.status !== 'online' ? ' disabled' : '') + '>Start recording</button>') +
      '<button class="btn ghost" id="testAlert"' + (c.status !== 'online' ? ' disabled' : '') + '>Raise test motion alert</button>' +
      '<button class="btn ghost" id="newInc">Report incident</button>' +
      '<button class="btn ghost" id="closeM">Close</button></div>', true);
  $('#closeM', m).onclick = closeModal;
  const rs = $('#recStart', m), rp = $('#recStop', m);
  if (rs) rs.onclick = async () => { if (await run(api('recording_start', { body: { camera_id: c.camera_id } }), 'Recording started')) { closeModal(); refresh(); } };
  if (rp) rp.onclick = async () => { if (await run(api('recording_stop', { body: { recording_id: c.active_recording } }), 'Recording stopped and saved')) { closeModal(); refresh(); } };
  $('#testAlert', m).onclick = async () => { if (await run(api('alert_test', { body: { camera_id: c.camera_id } }), 'Test alert raised')) { closeModal(); refresh(); } };
  $('#newInc', m).onclick = () => incidentForm(null, c.camera_id);
  const rl = $('#relay', m);
  if (rl) { const ck = $('#liveclk', m), tk = () => { ck.textContent = new Date().toLocaleString('en-GB'); }; tk(); const iv = setInterval(tk, 1000); modalCleanup = () => { clearInterval(iv); rl.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw='; }; }   // swapping the source drops the live connection so the server can stop ffmpeg
  if (rl) rl.onerror = () => { rl.parentNode.innerHTML = '<div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">The live picture could not start. Check that ffmpeg is installed (FFMPEG_PATH in db.php) and that the camera address is correct. See DEPLOYMENT.txt.</div>'; };
  const wv = $('#webcamfeed', m);
  if (wv) {
    let stream = null, closed = false;
    modalCleanup = () => { closed = true; if (stream) stream.getTracks().forEach(t => t.stop()); };
    const fail = msg => { wv.parentNode.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;padding:16px;text-align:center;color:var(--muted);font-size:13px">' + esc(msg) + '</div>'; };
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) fail('Camera access needs http://localhost or https. Open the system at http://localhost/cctv/.');
    else navigator.mediaDevices.getUserMedia({ video: true, audio: false }).then(st => {
      if (closed) { st.getTracks().forEach(t => t.stop()); return; }
      stream = st; wv.srcObject = st;
    }).catch(() => fail('Could not open the camera. Allow camera access in the browser, or check that no other app is using it.'));
  }
  const cv = $('#simfeed', m);
  if (cv) {
    const g = cv.getContext('2d'); let t = 0;
    const draw = () => {
      g.fillStyle = '#0a0f12'; g.fillRect(0, 0, 640, 360);
      for (let i = 0; i < 160; i++) { g.fillStyle = 'rgba(95,166,72,' + (Math.random() * .12) + ')'; g.fillRect(Math.random() * 640, Math.random() * 360, 2 + Math.random() * 18, 1); }
      g.fillStyle = 'rgba(95,166,72,.14)'; g.fillRect(0, (t * 4) % 360, 640, 18);
      g.fillStyle = '#5FA648'; g.font = '14px monospace';
      g.fillText(c.camera_code + '  ' + c.location, 16, 26);
      g.fillText(new Date().toLocaleString('en-GB'), 16, 346);
      if (c.active_recording) { g.fillStyle = '#D9614A'; g.beginPath(); g.arc(610, 22, 6, 0, 7); g.fill(); g.fillText('REC', 566, 27); }
      t++;
    };
    draw(); feedTimer = setInterval(draw, 120);
    modalCleanup = () => clearInterval(feedTimer);
  }
}

// ---------- cameras (admin)
PAGES.cameras = async function () {
  const d = await api('cameras_list');
  const zopts = d.zones.map(z => [z.zone_id, z.zone_name]);
  view.innerHTML =
    '<section><div class="head-row"><h2>Cameras</h2><button class="btn" id="addCam">Add camera</button></div>' +
    '<div class="list scroll"><table><thead><tr><th>Code</th><th>Zone</th><th>Location</th><th>IP address</th><th>Status</th><th></th></tr></thead><tbody>' +
    (d.cameras.map(c => '<tr><td>' + esc(c.camera_code) + '</td><td>' + esc(c.zone_name) + '</td><td>' + esc(c.location) + '</td><td>' + esc(c.ip_address || '-') +
      '</td><td><span class="badge ' + esc(c.status) + '">' + label(c.status) + '</span></td><td><div class="actions"><button class="btn small ghost" data-ed="' + c.camera_id + '">Edit</button><button class="btn small danger" data-del="' + c.camera_id + '">Delete</button></div></td></tr>').join('') ||
      '<tr><td colspan="6"><div class="empty">No cameras yet.</div></td></tr>') +
    '</tbody></table></div></section>' +
    '<section><div class="head-row"><h2>Zones</h2><button class="btn ghost" id="addZone">Add zone</button></div>' +
    '<div class="list scroll"><table><thead><tr><th>Zone</th><th>Building</th><th></th></tr></thead><tbody>' +
    d.zones.map(z => '<tr><td>' + esc(z.zone_name) + '</td><td>' + esc(z.building || '-') + '</td><td><div class="actions"><button class="btn small ghost" data-zed="' + z.zone_id + '">Edit</button><button class="btn small danger" data-zdel="' + z.zone_id + '">Delete</button></div></td></tr>').join('') +
    '</tbody></table></div></section>';

  const camForm = c => {
    if (!zopts.length) { toast('Add a zone first.', true); return; }
    formModal({
      title: c ? 'Edit camera' : 'Add camera', submit: c ? 'Save changes' : 'Add camera',
      fields: [
        { name: 'camera_code', label: 'Camera code (e.g. CAM 07)', required: true, value: c && c.camera_code },
        { name: 'zone_id', label: 'Zone', type: 'select', options: zopts, value: c && c.zone_id },
        { name: 'location', label: 'Location', required: true, value: c && c.location },
        { name: 'ip_address', label: 'IP address (optional)', value: c && c.ip_address, placeholder: '192.168.1.107' },
        { name: 'rtsp_url', label: 'Camera address for live view + 24/7 recording (optional)', value: c && c.rtsp_url, placeholder: 'rtsp://user:password@192.168.1.101:554/stream1' },
        { name: 'stream_url', label: 'Direct stream URL (optional, overrides the above for live view)', value: c && c.stream_url, placeholder: 'webcam  or  http://...' },
        { name: 'status', label: 'Status', type: 'select', options: [['online', 'Online'], ['offline', 'Offline'], ['maintenance', 'Maintenance']], value: c ? c.status : 'online' },
        { name: 'installed_on', label: 'Installed on (optional)', type: 'date', value: c && c.installed_on },
      ],
      onSubmit: async v => { if (c) v.camera_id = c.camera_id; await api('camera_save', { body: v }); toast('Camera saved'); refresh(); }
    });
  };
  $('#addCam').onclick = () => camForm(null);
  view.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => camForm(d.cameras.find(c => c.camera_id == b.dataset.ed)));
  view.querySelectorAll('[data-del]').forEach(b => b.onclick = () => confirmModal('Delete this camera? Its recordings and alerts are deleted too. Incidents keep their text but lose the camera link.',
    async () => { if (await run(api('camera_delete', { body: { camera_id: +b.dataset.del } }), 'Camera deleted')) refresh(); }));

  const zoneForm = z => formModal({
    title: z ? 'Edit zone' : 'Add zone',
    fields: [{ name: 'zone_name', label: 'Zone name', required: true, value: z && z.zone_name }, { name: 'building', label: 'Building (optional)', value: z && z.building }],
    onSubmit: async v => { if (z) v.zone_id = z.zone_id; await api('zone_save', { body: v }); toast('Zone saved'); refresh(); }
  });
  $('#addZone').onclick = () => zoneForm(null);
  view.querySelectorAll('[data-zed]').forEach(b => b.onclick = () => zoneForm(d.zones.find(z => z.zone_id == b.dataset.zed)));
  view.querySelectorAll('[data-zdel]').forEach(b => b.onclick = () => confirmModal('Delete this zone? This only works if no cameras are assigned to it.',
    async () => { if (await run(api('zone_delete', { body: { zone_id: +b.dataset.zdel } }), 'Zone deleted')) refresh(); }));
};

// ---------- incidents
const SEV = [['low', 'Low'], ['medium', 'Medium'], ['high', 'High']];
const STAT = [['open', 'Open'], ['under_review', 'Under review'], ['closed', 'Closed']];
let incFilter = { status: '', q: '' };
PAGES.incidents = async function () {
  const d = await api('incidents_list', { params: incFilter });
  view.innerHTML =
    '<div class="filters"><div><label>Status</label><select id="fStatus"><option value="">All</option>' + STAT.map(s => '<option value="' + s[0] + '"' + (incFilter.status === s[0] ? ' selected' : '') + '>' + s[1] + '</option>').join('') + '</select></div>' +
    '<div><label>Search title</label><input id="fQ" value="' + esc(incFilter.q) + '" placeholder="e.g. gate"></div>' +
    '<button class="btn ghost" id="fGo">Filter</button><div class="spacer"></div>' +
    (CAN_OPERATE ? '<button class="btn" id="addInc">Report incident</button>' : '') + '</div>' +
    '<div class="list">' + (d.incidents.map(incRow).join('') || '<div class="empty">No incidents match.</div>') + '</div>';
  const go = () => { incFilter = { status: $('#fStatus').value, q: $('#fQ').value.trim() }; refresh(); };
  $('#fGo').onclick = go; $('#fQ').onkeydown = e => { if (e.key === 'Enter') go(); }; $('#fStatus').onchange = go;
  if ($('#addInc')) $('#addInc').onclick = () => incidentForm(null);
  hookCommon(view);
};

async function incidentForm(inc, camId) {
  const cd = CAN_OPERATE ? await run(api('cameras_list')) : null; if (!cd) return;
  const copts = [['0', 'No camera']].concat(cd.cameras.map(c => [c.camera_id, c.camera_code + ' - ' + c.location]));
  formModal({
    title: inc ? 'Edit incident' : 'Report incident', submit: inc ? 'Save changes' : 'Log incident',
    fields: [
      { name: 'title', label: 'Title', required: true, value: inc && inc.title },
      { name: 'camera_id', label: 'Camera', type: 'select', options: copts, value: inc ? (inc.camera_id || 0) : (camId || 0) },
      { name: 'severity', label: 'Severity', type: 'select', options: SEV, value: inc ? inc.severity : 'medium' },
      { name: 'status', label: 'Status', type: 'select', options: STAT, value: inc ? inc.status : 'open' },
      { name: 'description', label: 'What happened?', type: 'textarea', value: inc && inc.description },
    ],
    onSubmit: async v => { if (inc) v.incident_id = inc.incident_id; await api('incident_save', { body: v }); toast('Incident saved'); refresh(); }
  });
}

async function openIncident(id) {
  const d = await run(api('incidents_list')); if (!d) return;
  const i = d.incidents.find(x => x.incident_id == id); if (!i) return;
  const m = openModal(
    '<h3>' + esc(i.title) + '</h3><div class="detail">' +
    '<p><span class="badge ' + esc(i.severity) + '">' + label(i.severity) + '</span> <span class="badge ' + esc(i.status) + '">' + label(i.status) + '</span></p>' +
    '<p>' + (i.description ? esc(i.description).replace(/\n/g, '<br>') : '<span class="meta">No description added.</span>') + '</p>' +
    '<p class="meta">Camera: ' + esc(i.camera_code || 'none') + '<br>Reported by: ' + esc(i.reporter || 'unknown') + ' on ' + fmt(i.created_at) +
    (i.closed_at ? '<br>Closed: ' + fmt(i.closed_at) : '') + '</p></div>' +
    '<div class="actions">' +
      (CAN_OPERATE ? (i.status !== 'under_review' ? '<button class="btn ghost" data-st="under_review">Mark under review</button>' : '') +
        (i.status !== 'closed' ? '<button class="btn" data-st="closed">Close incident</button>' : '<button class="btn ghost" data-st="open">Reopen</button>') +
        '<button class="btn ghost" id="edInc">Edit</button>' : '') +
      (IS_ADMIN ? '<button class="btn danger" id="delInc">Delete</button>' : '') +
      '<button class="btn ghost" id="xInc">Close window</button></div>');
  $('#xInc', m).onclick = closeModal;
  m.querySelectorAll('[data-st]').forEach(b => b.onclick = async () => { if (await run(api('incident_status', { body: { incident_id: i.incident_id, status: b.dataset.st } }), 'Incident updated')) { closeModal(); refresh(); } });
  if ($('#edInc', m)) $('#edInc', m).onclick = () => incidentForm(i);
  if ($('#delInc', m)) $('#delInc', m).onclick = () => confirmModal('Delete this incident permanently?', async () => { if (await run(api('incident_delete', { body: { incident_id: i.incident_id } }), 'Incident deleted')) refresh(); });
}

// ---------- recordings
let recFilter = { camera_id: '', from: '', to: '' };
PAGES.recordings = async function () {
  const [cd, d] = await Promise.all([api('cameras_list'), api('recordings_list', { params: recFilter })]);
  view.innerHTML =
    '<div class="note-box">Cameras with a camera address are recorded around the clock by the recorder service (start-recorder.bat). Each row is one video file. Click Play to watch it.</div>' +
    '<div class="filters"><div><label>Camera</label><select id="rCam"><option value="">All</option>' + cd.cameras.map(c => '<option value="' + c.camera_id + '"' + (recFilter.camera_id == c.camera_id ? ' selected' : '') + '>' + esc(c.camera_code) + '</option>').join('') + '</select></div>' +
    '<div><label>From</label><input type="date" id="rFrom" value="' + esc(recFilter.from) + '"></div><div><label>To</label><input type="date" id="rTo" value="' + esc(recFilter.to) + '"></div>' +
    '<button class="btn ghost" id="rGo">Filter</button><div class="spacer"></div>' +
    (IS_ADMIN ? '<button class="btn danger" id="purge">Delete old recordings</button>' : '') + '</div>' +
    '<div class="list scroll"><table><thead><tr><th>Camera</th><th>Started</th><th>Ended</th><th>Size</th><th>File</th><th></th></tr></thead><tbody>' +
    (d.recordings.map(r => '<tr><td>' + esc(r.camera_code) + '</td><td>' + fmt(r.start_time) + '</td><td>' + (r.end_time ? fmt(r.end_time) : '<span class="badge open">Recording</span>') +
      '</td><td>' + (r.end_time ? (r.file_size_mb / 1024).toFixed(2) + ' GB' : '-') + '</td><td class="mono" style="font-size:11px">' + esc(r.file_path || '-') +
      '</td><td><div class="actions">' + (r.file_path && r.file_path.indexOf('recordings/') === 0 ? '<button class="btn small" data-play="' + r.recording_id + '">Play</button><a class="btn small ghost" style="text-decoration:none" href="play.php?id=' + r.recording_id + '&dl=1">Download</a>' : '') +
      (!r.end_time && CAN_OPERATE && r.started_by ? '<button class="btn small" data-stop="' + r.recording_id + '">Stop</button>' : '') +
      (IS_ADMIN ? '<button class="btn small danger" data-rdel="' + r.recording_id + '">Delete</button>' : '') + '</div></td></tr>').join('') ||
      '<tr><td colspan="6"><div class="empty">No recordings match.</div></td></tr>') + '</tbody></table></div>';
  const go = () => { recFilter = { camera_id: $('#rCam').value, from: $('#rFrom').value, to: $('#rTo').value }; refresh(); };
  $('#rGo').onclick = go;
  view.querySelectorAll('[data-play]').forEach(b => b.onclick = () => {
    const m = openModal('<h3>Recording #' + esc(b.dataset.play) + '</h3><div class="feed"><video src="play.php?id=' + esc(b.dataset.play) + '" controls autoplay playsinline></video></div>' +
      '<div class="feed-note">If nothing plays, the file may have been deleted from the server.</div><div class="actions"><button class="btn ghost" id="closeM">Close</button></div>', true);
    $('#closeM', m).onclick = closeModal;
  });
  view.querySelectorAll('[data-stop]').forEach(b => b.onclick = async () => { if (await run(api('recording_stop', { body: { recording_id: +b.dataset.stop } }), 'Recording stopped')) refresh(); });
  view.querySelectorAll('[data-rdel]').forEach(b => b.onclick = () => confirmModal('Delete this recording entry?', async () => { if (await run(api('recording_delete', { body: { recording_id: +b.dataset.rdel } }), 'Recording deleted')) refresh(); }));
  if ($('#purge')) $('#purge').onclick = () => confirmModal('Delete every finished recording older than the retention period set in Settings?', async () => {
    const r = await run(api('recordings_purge', { body: {} })); if (r) { toast(r.deleted + ' recording(s) older than ' + r.days + ' days deleted'); refresh(); }
  });
};

// ---------- system health (admin)
PAGES.health = async function () {
  const h = await api('health');
  const gb = mb => (mb / 1024).toFixed(1) + ' GB';
  const pct = h.total_mb ? Math.min(100, Math.round((h.total_mb - h.free_mb) / h.total_mb * 100)) : 0;
  const ok = (good, yes, no) => '<span class="badge ' + (good ? 'online' : 'offline') + '">' + (good ? yes : no) + '</span>';
  view.innerHTML =
    '<div class="stats">' +
    '<div class="stat"><div class="n" style="font-size:18px">' + ok(h.recorder_running, 'Running', 'Not running') + '</div><div class="l">Recorder service' + (h.recorder_age != null ? ' (last seen ' + h.recorder_age + ' s ago)' : ' (never started)') + '</div></div>' +
    '<div class="stat"><div class="n" style="font-size:18px">' + ok(h.ffmpeg, 'Found', 'Not found') + '</div><div class="l">ffmpeg (video converter)</div></div>' +
    '<div class="stat"><div class="n">' + gb(h.used_mb) + '</div><div class="l">Footage stored</div></div>' +
    '<div class="stat"><div class="n">' + gb(h.free_mb) + '</div><div class="l">Free disk space (' + pct + '% of disk used)</div></div></div>' +
    (!h.recorder_running ? '<div class="note-box">The recorder is not running. Double-click <b>start-recorder.bat</b> in the cctv folder and leave that window open. Footage is only saved while it runs.</div>' : '') +
    (!h.ffmpeg ? '<div class="note-box">ffmpeg was not found. Install it and set FFMPEG_PATH at the top of db.php (see DEPLOYMENT.txt).</div>' : '') +
    '<section><div class="head-row"><h2>Cameras</h2></div><div class="list scroll"><table><thead><tr><th>Camera</th><th>Location</th><th>Status</th><th>Recording</th><th>Last footage</th><th>Stored</th></tr></thead><tbody>' +
    h.cameras.map(c => '<tr><td>' + esc(c.camera_code) + '</td><td>' + esc(c.location) + '</td><td><span class="badge ' + esc(c.status) + '">' + label(c.status) + '</span></td><td>' +
      (c.recorded ? 'Automatic (24/7)' : '<span style="color:var(--muted)">No camera address set</span>') + '</td><td>' + fmt(c.last_recording) + '</td><td>' + gb(c.used_mb) + '</td></tr>').join('') +
    '</tbody></table></div></section>' +
    '<p class="feed-note">Footage older than ' + h.retention_days + ' days is deleted automatically (change this under Settings).</p>';
};

// ---------- reports
let repRange = null;
PAGES.reports = async function () {
  if (!repRange) { const t = new Date(), f = new Date(Date.now() - 29 * 864e5), iso = x => x.toISOString().slice(0, 10); repRange = { from: iso(f), to: iso(t) }; }
  const d = await api('report', { params: repRange });
  const bars = (rows, order) => {
    const list = order ? order.map(k => rows.find(r => r.label === k) || { label: k, n: 0 }) : rows;
    const max = Math.max(1, ...list.map(r => +r.n));
    return list.map(r => '<div class="bar-row"><span class="lbl">' + esc(label(r.label)) + '</span><span class="bar"><i style="width:' + (r.n / max * 100) + '%"></i></span><span class="n">' + r.n + '</span></div>').join('') || '<div class="empty">No data</div>';
  };
  const camOnline = (d.camera_status.find(x => x.label === 'online') || { n: 0 }).n;
  const camTotal = d.camera_status.reduce((a, x) => a + (+x.n), 0);
  view.innerHTML =
    '<div class="filters no-print"><div><label>From</label><input type="date" id="pFrom" value="' + d.from + '"></div><div><label>To</label><input type="date" id="pTo" value="' + d.to + '"></div>' +
    '<button class="btn" id="pGo">Generate</button><div class="spacer"></div><button class="btn ghost" id="pCsv">Export CSV</button><button class="btn ghost" id="pPrint">Print</button></div>' +
    '<p style="font-size:12px;color:var(--muted);margin:0 0 16px">Report for ' + d.from + ' to ' + d.to + '</p>' +
    '<div class="stats"><div class="stat"><div class="label">Incidents</div><div class="val">' + d.total_incidents + '</div></div>' +
    '<div class="stat"><div class="label">Cameras online now</div><div class="val">' + camOnline + ' / ' + camTotal + '</div></div>' +
    '<div class="stat"><div class="label">Recordings made</div><div class="val">' + d.recordings.count + '</div><div class="note">' + d.recordings.gb + ' GB</div></div>' +
    '<div class="stat"><div class="label">Alerts raised</div><div class="val">' + d.alerts_by_type.reduce((a, x) => a + (+x.n), 0) + '</div></div></div>' +
    '<div class="grid2"><div class="card"><h3>Incidents by status</h3>' + bars(d.by_status, ['open', 'under_review', 'closed']) + '</div>' +
    '<div class="card"><h3>Incidents by severity</h3>' + bars(d.by_severity, ['high', 'medium', 'low']) + '</div>' +
    '<div class="card"><h3>Incidents by camera</h3>' + bars(d.by_camera) + '</div>' +
    '<div class="card"><h3>Alerts by type</h3>' + bars(d.alerts_by_type) + '</div></div>' +
    '<section style="margin-top:24px"><h2>Incident list</h2><div class="list scroll"><table><thead><tr><th>Date</th><th>Title</th><th>Camera</th><th>Severity</th><th>Status</th></tr></thead><tbody>' +
    (d.incidents.map(i => '<tr><td>' + fmt(i.created_at) + '</td><td>' + esc(i.title) + '</td><td>' + esc(i.camera || '-') + '</td><td>' + label(i.severity) + '</td><td>' + label(i.status) + '</td></tr>').join('') ||
      '<tr><td colspan="5"><div class="empty">No incidents in this period.</div></td></tr>') + '</tbody></table></div></section>';
  $('#pGo').onclick = () => { repRange = { from: $('#pFrom').value, to: $('#pTo').value }; refresh(); };
  $('#pPrint').onclick = () => window.print();
  $('#pCsv').onclick = () => {
    const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
    const rows = [['Date', 'Title', 'Camera', 'Severity', 'Status', 'Closed at']].concat(d.incidents.map(i => [i.created_at, i.title, i.camera, i.severity, i.status, i.closed_at || '']));
    const blob = new Blob(['﻿' + rows.map(r => r.map(q).join(',')).join('\r\n')], { type: 'text/csv' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'incident-report-' + d.from + '-to-' + d.to + '.csv'; a.click();
  };
};

// ---------- users (admin)
PAGES.users = async function () {
  const [d, a] = await Promise.all([api('users_list'), api('audit_list')]);
  view.innerHTML =
    '<section><div class="head-row"><h2>Accounts</h2><button class="btn" id="addUser">Add user</button></div><div class="list scroll"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>' +
    d.users.map(u => '<tr><td>' + esc(u.full_name) + '</td><td>' + esc(u.username) + '</td><td>' + label(u.role) + '</td><td><span class="badge ' + esc(u.status) + '">' + label(u.status) + '</span></td><td>' + fmt(u.last_login) +
      '</td><td><div class="actions"><button class="btn small ghost" data-ued="' + u.user_id + '">Edit</button>' + (u.user_id == U.id ? '' : '<button class="btn small danger" data-udel="' + u.user_id + '">Delete</button>') + '</div></td></tr>').join('') +
    '</tbody></table></div></section>' +
    '<section><h2>Activity log</h2><div class="list scroll"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>' +
    (a.log.map(l => '<tr><td>' + fmt(l.created_at) + '</td><td>' + esc(l.username || '-') + '</td><td>' + esc(l.action) + '</td><td>' + esc(l.details || '') + '</td></tr>').join('') || '<tr><td colspan="4"><div class="empty">Nothing logged yet.</div></td></tr>') +
    '</tbody></table></div></section>';
  const form = u => formModal({
    title: u ? 'Edit user' : 'Add user', submit: u ? 'Save changes' : 'Add user',
    fields: [
      { name: 'full_name', label: 'Full name', required: true, value: u && u.full_name },
      { name: 'username', label: 'Username', required: true, value: u && u.username },
      { name: 'email', label: 'Email (optional)', type: 'email', value: u && u.email },
      { name: 'role', label: 'Role', type: 'select', options: [['admin', 'Administrator'], ['operator', 'Security personnel'], ['management', 'University management']], value: u ? u.role : 'operator' },
      { name: 'status', label: 'Status', type: 'select', options: [['active', 'Active'], ['disabled', 'Disabled']], value: u ? u.status : 'active' },
      { name: 'password', label: u ? 'New password (leave blank to keep the current one)' : 'Password (8+ characters)', type: 'password' },
    ],
    onSubmit: async v => { if (u) v.user_id = u.user_id; await api('user_save', { body: v }); toast('User saved'); refresh(); }
  });
  $('#addUser').onclick = () => form(null);
  view.querySelectorAll('[data-ued]').forEach(b => b.onclick = () => form(d.users.find(u => u.user_id == b.dataset.ued)));
  view.querySelectorAll('[data-udel]').forEach(b => b.onclick = () => confirmModal('Delete this account permanently?', async () => { if (await run(api('user_delete', { body: { user_id: +b.dataset.udel } }), 'User deleted')) refresh(); }));
};

// ---------- settings
PAGES.settings = async function () {
  const s = (await api('settings_get')).settings;
  view.innerHTML =
    '<div class="grid2"><div class="card"><h3>Change your password</h3><form id="pwForm"><div class="err" id="pwErr" style="display:none;background:var(--alert-bg);border:1px solid var(--alert);color:#F0B4A6;font-size:13px;padding:9px 12px;border-radius:6px;margin-bottom:12px"></div>' +
    '<div class="f"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>' +
    '<div class="f"><label>New password (8+ characters)</label><input type="password" name="new" autocomplete="new-password" required></div>' +
    '<button class="btn">Update password</button></form></div>' +
    (IS_ADMIN ? '<div class="card"><h3>System settings</h3><form id="sysForm">' +
      '<div class="f"><label>Organisation name</label><input name="org_name" value="' + esc(s.org_name) + '"></div>' +
      '<div class="f"><label>Storage capacity (GB)</label><input name="storage_capacity_gb" type="number" min="1" step="any" value="' + esc(s.storage_capacity_gb) + '"></div>' +
      '<div class="f"><label>Keep recordings for (days)</label><input name="retention_days" type="number" min="1" max="3650" value="' + esc(s.retention_days) + '"></div>' +
      '<div class="f check"><input type="checkbox" id="s1" name="motion_alerts"' + (s.motion_alerts === '1' ? ' checked' : '') + '><label for="s1">Motion alerts</label></div>' +
      '<div class="f check"><input type="checkbox" id="s2" name="auto_archive"' + (s.auto_archive === '1' ? ' checked' : '') + '><label for="s2">Auto-archive footage</label></div>' +
      '<div class="f check"><input type="checkbox" id="s3" name="email_summary"' + (s.email_summary === '1' ? ' checked' : '') + '><label for="s3">Email daily summary</label></div>' +
      '<button class="btn">Save settings</button></form>' +
      '<p style="font-size:11px;color:var(--muted);line-height:1.5;margin:12px 0 0">Motion alerts controls whether test alerts can be raised. Retention is used by "Delete old recordings". Auto-archive and email summary are saved as preferences for your recorder or mail service to read.</p></div>' : '') +
    (IS_ADMIN ? '<div class="card"><h3>Backup</h3><p style="font-size:13px;color:var(--muted);line-height:1.6;margin:0 0 14px">Downloads all system data: accounts, cameras, incidents, alerts, the list of recordings and the activity log. Video files are not included; they are in the recordings folder.</p><a class="btn" style="text-decoration:none;display:inline-block" href="backup.php">Download backup</a></div>' : '') + '</div>';
  $('#pwForm').onsubmit = async e => {
    e.preventDefault(); const f = e.target, box = $('#pwErr'); box.style.display = 'none';
    try { await api('password_change', { body: { current: f.current.value, new: f.new.value } }); f.reset(); toast('Password updated'); }
    catch (er) { box.textContent = er.message; box.style.display = 'block'; }
  };
  if (IS_ADMIN) $('#sysForm').onsubmit = async e => {
    e.preventDefault(); const f = e.target;
    await run(api('settings_save', { body: { org_name: f.org_name.value, storage_capacity_gb: f.storage_capacity_gb.value, retention_days: f.retention_days.value,
      motion_alerts: f.motion_alerts.checked, auto_archive: f.auto_archive.checked, email_summary: f.email_summary.checked } }), 'Settings saved');
  };
};

// ---------- start
const tick = () => { $('#clock').textContent = new Date().toLocaleTimeString('en-GB', { hour12: false }); };
tick(); setInterval(tick, 1000);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
route();
})();
</script>
</body>
</html>
