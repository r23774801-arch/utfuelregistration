<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

// Filter Bulan & Tahun
$selectedMonth = $_GET['month'] ?? date('Y-m');
$startDate = $selectedMonth . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

$todayDate = date('Y-m-d');

try {
    $stmtCurrentUser = $pdo->prepare("SELECT full_name, email, nip, role FROM users WHERE user_id = :user_id LIMIT 1");
    $stmtCurrentUser->execute(['user_id' => current_user_id()]);
    $currentUser = $stmtCurrentUser->fetch() ?: [];
    $profileName = $currentUser['full_name'] ?: ($currentUser['email'] ?: 'Admin');
    $profileRole = $currentUser['role'] ?: 'Admin';
    $nameParts = preg_split('/\s+/', trim($profileName));
    $profileInitials = '';
    foreach ($nameParts as $part) {
        if ($part !== '') {
            $profileInitials .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($profileInitials) >= 2) {
            break;
        }
    }
    $profileInitials = $profileInitials !== '' ? $profileInitials : 'AD';

    // 1. STATISTIK UTAMA
    $stmtToday = $pdo->prepare("SELECT SUM(volume_liters) FROM fuel_logs WHERE DATE(fuel_date) = :today");
    $stmtToday->execute(['today' => $todayDate]);
    $totalHariIni = $stmtToday->fetchColumn() ?: 0;

    $stmtMonth = $pdo->prepare("SELECT SUM(volume_liters) FROM fuel_logs WHERE DATE(fuel_date) BETWEEN :start AND :end");
    $stmtMonth->execute(['start' => $startDate, 'end' => $endDate]);
    $totalBulanIni = $stmtMonth->fetchColumn() ?: 0;

    $stmtTrx = $pdo->prepare("SELECT COUNT(*) FROM fuel_logs WHERE DATE(fuel_date) BETWEEN :start AND :end");
    $stmtTrx->execute(['start' => $startDate, 'end' => $endDate]);
    $trxBulanIni = $stmtTrx->fetchColumn() ?: 0;

    $rataRataPengisian = $trxBulanIni > 0 ? ($totalBulanIni / $trxBulanIni) : 0;

    // 2. TREN PENGISIAN
    $stmtTrend = $pdo->prepare("
        SELECT DATE(fuel_date) as tgl, SUM(volume_liters) as total 
        FROM fuel_logs 
        WHERE DATE(fuel_date) BETWEEN :start AND :end
        GROUP BY DATE(fuel_date) 
        ORDER BY tgl ASC
    ");
    $stmtTrend->execute(['start' => $startDate, 'end' => $endDate]);
    $trendRows = $stmtTrend->fetchAll();

    $trendDates = [];
    $trendTotals = [];
    foreach ($trendRows as $row) {
        $trendDates[]  = date('d/m', strtotime($row['tgl']));
        $trendTotals[] = (float)$row['total'];
    }

    // 3. TOP 5 UNIT PENGISIAN
    $stmtTopUnit = $pdo->prepare("
        SELECT u.unit_code, c.category_name, SUM(f.volume_liters) as total_vol
        FROM fuel_logs f
        JOIN units u ON f.unit_id = u.unit_id
        LEFT JOIN unit_categories c ON u.category_id = c.category_id
        WHERE DATE(f.fuel_date) BETWEEN :start AND :end
        GROUP BY f.unit_id
        ORDER BY total_vol DESC
        LIMIT 5
    ");
    $stmtTopUnit->execute(['start' => $startDate, 'end' => $endDate]);
    $topUnits = $stmtTopUnit->fetchAll();

    // 4. RINGKASAN HARI INI
    $stmtTrxToday = $pdo->prepare("SELECT COUNT(*) FROM fuel_logs WHERE DATE(fuel_date) = :today");
    $stmtTrxToday->execute(['today' => $todayDate]);
    $trxTodayCount = $stmtTrxToday->fetchColumn() ?: 0;

    $stmtActiveUnits = $pdo->query("SELECT COUNT(DISTINCT unit_id) FROM fuel_logs WHERE DATE(fuel_date) = CURDATE()")->fetchColumn() ?: 0;

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    die("Gagal memuat data dashboard.");
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - United Tractors Fuel Monitoring System</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('solar-theme') || 'dark');</script>
  
  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/ui-polish.css">

  <style>
    /* RESET CSS UNTUK KONSISTENSI LINTAS HALAMAN */
    *, *::before, *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg-dark: #0a0a0a;
      --sidebar-bg: #111111;
      --card-bg: #181818;
      --input-bg: #202020;
      --border-color: #303030;
      --accent-yellow: #ffb800;
      --accent-blue: #2563eb;
      --accent-green: #10b981;
      --accent-purple: #8b5cf6;
      --text-main: #ffffff;
      --text-muted: #8192a6;
      --sidebar-width: 250px;
    }

    body {
      background-color: var(--bg-dark);
      color: var(--text-main);
      display: flex;
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      font-size: 12px;
      overflow-x: hidden;
    }

    /* 1. SIDEBAR FIXED WIDTH */
    .sidebar {
      width: var(--sidebar-width) !important;
      min-width: var(--sidebar-width) !important;
      max-width: var(--sidebar-width) !important;
      flex-shrink: 0 !important;
      background: var(--sidebar-bg);
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      padding: 20px 15px;
      min-height: 100vh;
      box-sizing: border-box;
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
      z-index: 20;
    }
    
    .sidebar-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 35px;
      padding-left: 5px;
    }

    .brand-icon {
      background: #ffffff;
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      overflow: hidden;
      padding: 4px;
    }

    .brand-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .brand-text h2 {
      font-size: 15px;
      font-weight: 800;
      color: #ffffff;
      letter-spacing: 0.5px;
      line-height: 1.1;
    }

    .brand-text p {
      font-size: 10px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .sidebar-menu {
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .sidebar-menu li {
      width: 100%;
    }

    .sidebar-menu a {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 12px 16px;
      color: var(--text-muted);
      text-decoration: none;
      font-weight: 700;
      font-size: 13px;
      border-radius: 12px;
      transition: background 0.2s ease, color 0.2s ease;
      width: 100%;
      box-sizing: border-box;
    }

    .sidebar-menu a:hover {
      color: #ffffff;
      background: rgba(255, 255, 255, 0.05);
    }
    
    .sidebar-menu a.active {
      background: var(--accent-yellow);
      color: #000000;
      font-weight: 800;
      box-shadow: 0 4px 12px rgba(255, 184, 0, 0.2);
    }

    .sidebar-menu a.active i {
      color: #000000 !important;
    }

    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-thumb { background: #303030; border-radius: 999px; }

    /* 2. MAIN WRAPPER */
    .main-wrapper {
      flex: 1;
      min-width: 0 !important;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
      margin-left: var(--sidebar-width);
    }

    .top-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 18px 25px;
      background: var(--sidebar-bg);
      border-bottom: 1px solid var(--border-color);
    }

    .header-title h1 {
      font-size: 20px;
      font-weight: 900;
    }

    .header-title p {
      color: var(--text-muted);
      font-size: 11px;
      margin-top: 2px;
    }

    .header-controls {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .btn-control {
      background: var(--input-bg);
      border: 1px solid var(--border-color);
      color: #ffffff;
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: 0.2s;
    }

    .btn-control:hover {
      background: rgba(255, 184, 0, 0.1);
      border-color: var(--accent-yellow);
      color: var(--accent-yellow);
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 10px;
      padding-left: 15px;
      border-left: 1px solid var(--border-color);
    }

    .user-avatar {
      width: 32px;
      height: 32px;
      background: var(--accent-yellow);
      color: #000000;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
    }

    .dashboard-container {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    /* METRICS GRID */
    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
    }

    .metric-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 15px;
      position: relative;
      overflow: hidden;
    }

    .metric-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 4px;
      height: 100%;
    }

    .metric-card.blue::before { background: var(--accent-blue); }
    .metric-card.green::before { background: var(--accent-green); }
    .metric-card.purple::before { background: var(--accent-purple); }
    .metric-card.yellow::before { background: var(--accent-yellow); }

    .metric-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 10px;
    }

    .metric-icon {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .metric-title {
      font-size: 9px;
      font-weight: 800;
      text-transform: uppercase;
      color: var(--text-muted);
    }

    .metric-value {
      font-size: 20px;
      font-weight: 900;
      margin-bottom: 4px;
    }

    .metric-unit {
      font-size: 11px;
      color: var(--text-muted);
      font-weight: normal;
    }

    .metric-sub {
      font-size: 10px;
      color: var(--accent-green);
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .card-panel {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 16px;
      display: flex;
      flex-direction: column;
    }

    .panel-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
    }

    .panel-title {
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .top-list {
      list-style: none;
    }

    .top-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 0;
      border-bottom: 1px solid var(--border-color);
    }

    .top-item:last-child {
      border-bottom: none;
    }

    .top-rank {
      font-weight: 800;
      width: 20px;
      color: var(--text-muted);
    }

    .top-info {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-grow: 1;
    }

    .top-vol {
      font-weight: 800;
      color: var(--accent-yellow);
    }

    .bottom-bar {
      background: var(--sidebar-bg);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 15px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .summary-group {
      display: flex;
      align-items: center;
      gap: 25px;
    }

    .summary-item {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .summary-icon {
      background: var(--input-bg);
      padding: 8px;
      border-radius: 6px;
      color: var(--accent-yellow);
    }

    .footer {
      text-align: center;
      color: var(--text-muted);
      font-size: 11px;
      padding: 15px 0;
      border-top: 1px solid var(--border-color);
      margin-top: auto;
    }

    @media (max-width: 1200px) {
      .metrics-grid { grid-template-columns: repeat(2, 1fr); }
    }
  </style>
</head>
<body>

  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon"><img src="img/logo.png" alt="United Tractors"></div>
      <div class="brand-text">
        <h2>UNITED TRACTORS</h2>
        <p>Fuel Monitoring System</p>
      </div>
    </div>

    <ul class="sidebar-menu">
      <li>
        <a href="dashboard.php" class="<?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
          <i data-lucide="layout-grid" size="18"></i> Dashboard
        </a>
      </li>
      <li>
        <a href="riwayat.php" class="<?= ($current_page == 'riwayat.php') ? 'active' : '' ?>">
          <i data-lucide="history" size="18"></i> Riwayat Pengisian
        </a>
      </li>
      <li>
        <a href="laporan.php" class="<?= ($current_page == 'laporan.php') ? 'active' : '' ?>">
          <i data-lucide="file-text" size="18"></i> Laporan
        </a>
      </li>
      <li>
        <a href="profil.php" class="<?= ($current_page == 'profil.php') ? 'active' : '' ?>">
          <i data-lucide="user-cog" size="18"></i> Profil
        </a>
      </li>
      <li>
        <a href="qr_fuel.php">
          <i data-lucide="qr-code" size="18"></i> QR Fuel
        </a>
      </li>
      <li>
        <a href="logout.php">
          <i data-lucide="log-out" size="18"></i> Logout
        </a>
      </li>
    </ul>
    <button type="button" class="theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>
  </aside>

  <div class="main-wrapper">
    
    <header class="top-header">
      <div class="header-title">
        <h1>DASHBOARD</h1>
        <p>Monitoring pengisian solar secara real-time</p>
      </div>

      <div class="header-controls">
        <form method="GET" action="dashboard.php">
          <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" onchange="this.form.submit()" class="btn-control" style="outline:none;">
        </form>

        <div class="user-profile">
          <div class="user-avatar"><?= e($profileInitials) ?></div>
          <div>
            <div style="font-weight:800;"><?= e($profileName) ?></div>
            <div style="font-size:10px; color: var(--text-muted);"><?= e($profileRole) ?></div>
          </div>
        </div>
      </div>
    </header>

    <div class="dashboard-container" id="dashboard-content">

      <div class="metrics-grid">
        <div class="metric-card blue">
          <div class="metric-header">
            <span class="metric-title">Total Pengisian Hari Ini</span>
            <div class="metric-icon" style="background: rgba(37,99,235,0.15); color: var(--accent-blue);"><i data-lucide="fuel" size="16"></i></div>
          </div>
          <div class="metric-value"><?= number_format($totalHariIni, 0, ',', '.') ?> <span class="metric-unit">Liter</span></div>
          <div class="metric-sub"><i data-lucide="info" size="12"></i> <?= $totalHariIni > 0 ? 'Data hari ini tercatat' : 'Belum ada data hari ini' ?></div>
        </div>

        <div class="metric-card green">
          <div class="metric-header">
            <span class="metric-title">Total Pengisian Bulan Ini</span>
            <div class="metric-icon" style="background: rgba(16,185,129,0.15); color: var(--accent-green);"><i data-lucide="calendar" size="16"></i></div>
          </div>
          <div class="metric-value"><?= number_format($totalBulanIni, 0, ',', '.') ?> <span class="metric-unit">Liter</span></div>
          <div class="metric-sub"><i data-lucide="info" size="12"></i> <?= $totalBulanIni > 0 ? 'Data bulan ini tercatat' : 'Belum ada data bulan ini' ?></div>
        </div>

        <div class="metric-card purple">
          <div class="metric-header">
            <span class="metric-title">Jumlah Transaksi Bulan Ini</span>
            <div class="metric-icon" style="background: rgba(139,92,246,0.15); color: var(--accent-purple);"><i data-lucide="list-checks" size="16"></i></div>
          </div>
          <div class="metric-value"><?= $trxBulanIni ?> <span class="metric-unit">Transaksi</span></div>
          <div class="metric-sub"><i data-lucide="info" size="12"></i> <?= $trxBulanIni > 0 ? 'Transaksi bulan ini tercatat' : 'Belum ada transaksi bulan ini' ?></div>
        </div>

        <div class="metric-card yellow">
          <div class="metric-header">
            <span class="metric-title">Rata-rata Pengisian</span>
            <div class="metric-icon" style="background: rgba(255,184,0,0.15); color: var(--accent-yellow);"><i data-lucide="gauge" size="16"></i></div>
          </div>
          <div class="metric-value"><?= number_format($rataRataPengisian, 1) ?> <span class="metric-unit">Liter / Transaksi</span></div>
          <div class="metric-sub"><i data-lucide="info" size="12"></i> <?= $trxBulanIni > 0 ? 'Rata-rata dari transaksi bulan ini' : 'Menunggu data transaksi' ?></div>
        </div>
      </div>

      <div class="card-panel">
        <div class="panel-header">
          <span class="panel-title"><i data-lucide="trending-up" size="16"></i> Trend Pengisian (Liter)</span>
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span style="display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:800; color:#ffb800; background:rgba(255,184,0,0.12); border:1px solid rgba(255,184,0,0.24); padding:6px 9px; border-radius:999px;">
              <i data-lucide="arrow-up-right" size="13"></i> Naik
            </span>
            <span style="display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:800; color:#ef4444; background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.24); padding:6px 9px; border-radius:999px;">
              <i data-lucide="arrow-down-right" size="13"></i> Turun
            </span>
            <span style="font-size:10px; color:var(--text-muted);">Bulan Ini</span>
          </div>
        </div>
        <div style="position: relative; height: 260px;">
          <canvas id="trendChart"></canvas>
        </div>
      </div>

      <div class="card-panel">
        <div class="panel-header">
          <span class="panel-title"><i data-lucide="award" size="16"></i> Top 5 Unit Pengisian Terbanyak</span>
        </div>
        <ul class="top-list">
          <?php if(empty($topUnits)): ?>
            <li style="color: var(--text-muted); text-align:center; padding: 20px;">Belum ada data unit.</li>
          <?php else: ?>
            <?php foreach($topUnits as $i => $unit): ?>
              <li class="top-item">
                <span class="top-rank"><?= $i + 1 ?></span>
                <div class="top-info">
                  <i data-lucide="truck" size="14" style="color: var(--accent-yellow);"></i>
                  <div>
                    <div style="font-weight: 800;"><?= htmlspecialchars($unit['unit_code']) ?></div>
                    <div style="font-size:10px; color: var(--text-muted);"><?= htmlspecialchars($unit['category_name'] ?? 'Heavy Equipment') ?></div>
                  </div>
                </div>
                <span class="top-vol"><?= number_format($unit['total_vol'], 0, ',', '.') ?> L</span>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="bottom-bar">
        <div style="display:flex; align-items:center; gap:10px; font-weight:800; text-transform:uppercase; color: var(--accent-yellow);">
          <i data-lucide="fuel" size="18"></i> Ringkasan Hari Ini
        </div>
        <div class="summary-group">
          <div class="summary-item">
            <div class="summary-icon"><i data-lucide="droplet" size="16"></i></div>
            <div>
              <div style="color: var(--text-muted); font-size:10px;">Total Pengisian</div>
              <div style="font-weight:900; font-size:14px;"><?= number_format($totalHariIni, 0, ',', '.') ?> L</div>
            </div>
          </div>
          <div class="summary-item">
            <div class="summary-icon"><i data-lucide="repeat" size="16"></i></div>
            <div>
              <div style="color: var(--text-muted); font-size:10px;">Total Transaksi</div>
              <div style="font-weight:900; font-size:14px;"><?= $trxTodayCount ?></div>
            </div>
          </div>
          <div class="summary-item">
            <div class="summary-icon"><i data-lucide="gauge" size="16"></i></div>
            <div>
              <div style="color: var(--text-muted); font-size:10px;">Rata-rata Pengisian</div>
              <div style="font-weight:900; font-size:14px;"><?= number_format($rataRataPengisian, 1) ?> L/Transaksi</div>
            </div>
          </div>
          <div class="summary-item">
            <div class="summary-icon"><i data-lucide="truck" size="16"></i></div>
            <div>
              <div style="color: var(--text-muted); font-size:10px;">Unit Aktif</div>
              <div style="font-weight:900; font-size:14px;"><?= $stmtActiveUnits ?> Unit</div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <footer class="footer">
      &copy; <?= date('Y') ?> United Tractors. All Rights Reserved.
    </footer>

  </div>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/session-keepalive.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>
    lucide.createIcons();
    let idleLogoutTimer;
    function resetIdleLogoutTimer() {
      clearTimeout(idleLogoutTimer);
      idleLogoutTimer = setTimeout(() => {
        window.location.href = 'logout.php?timeout=1';
      }, 10 * 60 * 1000);
    }
    ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(eventName => {
      window.addEventListener(eventName, resetIdleLogoutTimer, { passive: true });
    });
    resetIdleLogoutTimer();

    const chartGridColor = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || '#303030';
    const chartTextColor = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#8192a6';

    // CHART TREN PENGISIAN
    const trendCanvas = document.getElementById('trendChart');
    const trendCtx = trendCanvas.getContext('2d');
    const trendGradient = trendCtx.createLinearGradient(0, 0, 0, trendCanvas.offsetHeight || 320);
    trendGradient.addColorStop(0, 'rgba(255, 184, 0, 0.32)');
    trendGradient.addColorStop(0.55, 'rgba(255, 184, 0, 0.10)');
    trendGradient.addColorStop(1, 'rgba(255, 184, 0, 0.00)');

    new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels: <?= json_encode($trendDates) ?>,
        datasets: [{
          label: 'Liter',
          data: <?= json_encode($trendTotals) ?>,
          borderColor: '#ffb800',
          backgroundColor: trendGradient,
          fill: true,
          tension: 0.38,
          borderWidth: 4,
          borderCapStyle: 'round',
          borderJoinStyle: 'round',
          pointRadius: 5,
          pointHoverRadius: 8,
          pointBorderWidth: 3,
          pointBorderColor: '#111111',
          pointBackgroundColor: function (ctx) {
            const values = ctx.dataset.data;
            const index = ctx.dataIndex;
            if (index === 0 || values[index] >= values[index - 1]) {
              return '#ffb800';
            }
            return '#ef4444';
          },
          segment: {
            borderColor: function (ctx) {
              return ctx.p1.parsed.y >= ctx.p0.parsed.y ? '#ffb800' : '#ef4444';
            },
            backgroundColor: function (ctx) {
              return ctx.p1.parsed.y >= ctx.p0.parsed.y ? 'rgba(255, 184, 0, 0.10)' : 'rgba(239, 68, 68, 0.08)';
            }
          }
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        normalized: true,
        animation: {
          duration: 1300,
          easing: 'easeOutCubic'
        },
        animations: {
          x: {
            type: 'number',
            easing: 'easeOutCubic',
            duration: 900,
            from: NaN,
            delay: function (ctx) {
              return ctx.type === 'data' ? ctx.dataIndex * 120 : 0;
            }
          },
          y: {
            type: 'number',
            easing: 'easeOutBack',
            duration: 1100,
            from: function (ctx) {
              return ctx.chart.scales.y.getPixelForValue(0);
            },
            delay: function (ctx) {
              return ctx.type === 'data' ? ctx.dataIndex * 120 : 0;
            }
          },
          tension: {
            duration: 1200,
            easing: 'easeOutCubic',
            from: 0.12,
            to: 0.38,
            loop: false
          }
        },
        interaction: {
          intersect: false,
          mode: 'index'
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.86)',
            borderColor: '#ffb800',
            borderWidth: 1,
            titleColor: '#ffb800',
            bodyColor: '#ffffff',
            padding: 12,
            displayColors: false,
            callbacks: {
              label: function (ctx) {
                const current = Number(ctx.parsed.y || 0);
                const previous = ctx.dataIndex > 0 ? Number(ctx.dataset.data[ctx.dataIndex - 1] || 0) : current;
                const delta = current - previous;
                const trend = delta > 0 ? ' naik +' + delta.toLocaleString('id-ID') : (delta < 0 ? ' turun ' + delta.toLocaleString('id-ID') : ' stabil');
                return current.toLocaleString('id-ID') + ' Liter ·' + trend;
              }
            }
          }
        },
        scales: {
          x: {
            ticks: { color: chartTextColor },
            grid: { color: chartGridColor, drawBorder: false }
          },
          y: {
            beginAtZero: true,
            ticks: { color: chartTextColor },
            grid: { color: chartGridColor, drawBorder: false }
          }
        }
      }
    });
  </script>
</body>
</html>
