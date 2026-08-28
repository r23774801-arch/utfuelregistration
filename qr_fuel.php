<?php
require_once __DIR__ . '/includes/auth.php';

require_login();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$fuelUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/user.php';
$qrUrl = 'https://quickchart.io/qr?size=320&margin=2&text=' . urlencode($fuelUrl);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QR Fuel Registration</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('solar-theme') || 'dark');</script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/ui-polish.css">
  <style>
    :root { --bg-dark:#0a0a0a; --sidebar-bg:#111111; --card-bg:#181818; --input-bg:#202020; --border-color:#303030; --accent-yellow:#ffb800; --text-main:#fff; --text-muted:#8a99ad; --sidebar-width:250px; }
    * { box-sizing:border-box; margin:0; padding:0; font-family:'Segoe UI', sans-serif; }
    body { min-height:100vh; background:var(--bg-dark); color:var(--text-main); display:flex; font-size:13px; }
    .sidebar { width:var(--sidebar-width); min-width:var(--sidebar-width); background:var(--sidebar-bg); border-right:1px solid var(--border-color); padding:20px 15px; position:fixed; inset:0 auto 0 0; overflow-y:auto; }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom:35px; padding-left:5px; }
    .brand-logo { background:#fff; width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; overflow:hidden; padding:4px; flex-shrink:0; }
    .brand-logo img { width:100%; height:100%; object-fit:contain; }
    .brand-text h2 { font-size:15px; font-weight:800; color:var(--text-main); line-height:1.1; }
    .brand-text p { font-size:10px; color:var(--text-muted); margin-top:2px; }
    .nav-menu { display:flex; flex-direction:column; gap:8px; }
    .nav-item { display:flex; align-items:center; gap:14px; padding:12px 16px; color:var(--text-muted); text-decoration:none; border-radius:12px; font-weight:700; }
    .nav-item:hover { color:var(--text-main); background:rgba(255,255,255,.05); }
    .nav-item.active { background:var(--accent-yellow); color:#000; font-weight:900; box-shadow:0 4px 12px rgba(255,184,0,.2); }
    .nav-item.active i { color:#000 !important; }
    .main-content { margin-left:var(--sidebar-width); min-height:100vh; flex:1; padding:30px; }
    .page-title { margin-bottom:24px; }
    .page-title h1 { font-size:24px; font-weight:900; }
    .page-title p { color:var(--text-muted); font-size:12px; margin-top:5px; }
    .qr-grid { display:grid; grid-template-columns:minmax(320px,460px) minmax(280px,1fr); gap:20px; align-items:start; }
    .card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:14px; padding:24px; box-shadow:0 18px 34px rgba(0,0,0,.28); }
    .qr-card { text-align:center; }
    .qr { background:#fff; border-radius:14px; padding:16px; display:inline-flex; margin:10px 0 18px; }
    .qr img { width:320px; max-width:100%; height:auto; display:block; }
    .link { display:block; color:var(--accent-yellow); word-break:break-all; font-size:12px; margin-bottom:18px; }
    .actions { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .btn { background:var(--accent-yellow); color:#000; text-decoration:none; border:0; border-radius:9px; padding:11px 12px; font-weight:900; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
    .btn.secondary { background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); }
    .info-list { display:grid; gap:12px; color:var(--text-muted); line-height:1.55; }
    .info-list strong { color:var(--text-main); }
    @media (max-width:900px) { .sidebar { position:static; width:100%; min-width:0; height:auto; } body { display:block; } .main-content { margin-left:0; padding:20px; } .qr-grid { grid-template-columns:1fr; } }
    @media print { .sidebar, .page-title, .info-card, .theme-toggle { display:none !important; } .main-content { margin:0; padding:0; } body { background:#fff; } .card { box-shadow:none; border:0; color:#000; } .actions { display:none; } }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-logo"><img src="img/logo.png" alt="United Tractors"></div>
      <div class="brand-text">
        <h2>UNITED TRACTORS</h2>
        <p>Fuel Monitoring System</p>
      </div>
    </div>
    <nav class="nav-menu">
      <a href="dashboard.php" class="nav-item"><i data-lucide="layout-dashboard" size="18"></i> Dashboard</a>
      <a href="riwayat.php" class="nav-item"><i data-lucide="history" size="18"></i> Riwayat Pengisian</a>
      <a href="laporan.php" class="nav-item"><i data-lucide="file-text" size="18"></i> Laporan</a>
      <a href="profil.php" class="nav-item"><i data-lucide="user-cog" size="18"></i> Profil</a>
      <a href="qr_fuel.php" class="nav-item active"><i data-lucide="qr-code" size="18"></i> QR Fuel</a>
      <a href="logout.php" class="nav-item"><i data-lucide="log-out" size="18"></i> Logout</a>
      <button type="button" class="theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>
    </nav>
  </aside>

  <main class="main-content">
    <div class="page-title">
      <h1>QR FUEL REGISTRATION</h1>
      <p>Print atau tampilkan QR ini agar operator bisa membuka form pengisian fuel.</p>
    </div>

    <div class="qr-grid">
      <section class="card qr-card">
        <div class="qr"><img src="<?= e($qrUrl) ?>" alt="QR Fuel Registration"></div>
        <a class="link" href="<?= e($fuelUrl) ?>"><?= e($fuelUrl) ?></a>
        <div class="actions">
          <a class="btn" href="<?= e($fuelUrl) ?>"><i data-lucide="external-link" size="16"></i> Buka Form</a>
          <button class="btn secondary" onclick="window.print()"><i data-lucide="printer" size="16"></i> Print QR</button>
        </div>
      </section>

      <section class="card info-card">
        <div class="info-list">
          <p><strong>Alur:</strong> operator scan QR, isi Fuel Registration, data masuk database, laporan admin, spreadsheet lokal, dan email admin dicoba dikirim.</p>
          <p><strong>Untuk HP:</strong> buka halaman QR ini dari IP/domain yang bisa diakses HP. Kalau masih localhost, QR hanya berlaku di komputer lokal.</p>
          <p><strong>Hosting:</strong> setelah website punya domain, QR otomatis mengikuti domain saat halaman ini dibuka dari domain tersebut.</p>
        </div>
      </section>
    </div>
  </main>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/session-keepalive.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>lucide.createIcons();</script>
</body>
</html>
