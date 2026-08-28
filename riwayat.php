<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media.php';

require_login();

function riwayat_query(array $override = []): string
{
    $params = array_merge($_GET, $override);
    return http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
}

// Ambil parameter filter & pencarian dari URL (GET)
$search    = $_GET['search'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';
$category  = $_GET['category'] ?? '';
$perPage   = (int) ($_GET['per_page'] ?? 10);
$allowedPerPage = [10, 25, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

// Query Dasar dengan JOIN
$querySql = "
    SELECT f.*, u.unit_code, c.category_name, us.full_name 
    FROM fuel_logs f
    JOIN units u ON f.unit_id = u.unit_id
    JOIN unit_categories c ON u.category_id = c.category_id
    JOIN users us ON f.user_id = us.user_id
    WHERE 1=1";

$params = [];

// Filter berdasarkan Pencarian Teks
if (!empty($search)) {
    $querySql .= " AND (u.unit_code LIKE :search_unit OR us.full_name LIKE :search_user OR f.notes LIKE :search_notes)";
    $params['search_unit'] = "%$search%";
    $params['search_user'] = "%$search%";
    $params['search_notes'] = "%$search%";
}

// Filter berdasarkan Kategori Unit
if (!empty($category)) {
    $querySql .= " AND c.category_id = :category";
    $params['category'] = $category;
}

// Filter berdasarkan Rentang Tanggal
if (!empty($startDate) && !empty($endDate)) {
    $querySql .= " AND DATE(f.fuel_date) BETWEEN :start_date AND :end_date";
    $params['start_date'] = $startDate;
    $params['end_date']   = $endDate;
}

$countSql = "SELECT COUNT(*)
    FROM fuel_logs f
    JOIN units u ON f.unit_id = u.unit_id
    JOIN unit_categories c ON u.category_id = c.category_id
    JOIN users us ON f.user_id = us.user_id
    WHERE 1=1";
$countSql .= str_replace(substr($querySql, 0, strpos($querySql, ' WHERE 1=1') + strlen(' WHERE 1=1')), '', $querySql);

$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRows = (int) $stmtCount->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$querySql .= " ORDER BY f.fuel_date DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($querySql);
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$logs = $stmt->fetchAll();

// Ambil daftar kategori unit untuk dropdown filter
$categories = $pdo->query("SELECT * FROM unit_categories ORDER BY category_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Riwayat Pengisian - United Tractors</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('solar-theme') || 'dark');</script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/ui-polish.css">
  <style>
    :root {
      --bg-dark: #0a0a0a;
      --sidebar-bg: #111111;
      --card-bg: #181818;
      --accent-yellow: #ffb800;
      --text-main: #ffffff;
      --text-muted: #7e8b9b;
      --border-color: #303030;
      --green-growth: #10b981;
      --red-alert: #ef4444;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
    body { background-color: var(--bg-dark); color: var(--text-main); display: flex; min-height: 100vh; font-size: 13px; }

    /* Sidebar Styles */
    .sidebar { width: 220px; background-color: var(--sidebar-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 15px; flex-shrink: 0; }
    .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 30px; }
    .brand-logo { background-color: var(--accent-yellow); color: #000; font-weight: 900; width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .brand-logo { background-color: #fff; overflow: hidden; padding: 3px; }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; }
    .brand-text h2 { font-size: 13px; font-weight: 800; }
    .brand-text p { font-size: 9px; color: var(--text-muted); }
    .nav-menu { display: flex; flex-direction: column; gap: 8px; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; transition: all 0.2s; }
    .nav-item:hover { color: #fff; background-color: rgba(255, 255, 255, 0.05); }
    .nav-item.active { background-color: var(--accent-yellow); color: #000; font-weight: 700; }

    /* Main Content */
    .main-content { flex: 1; padding: 25px 30px; overflow-y: auto; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
    .page-title h1 { font-size: 24px; font-weight: 800; }
    .page-title p { color: var(--text-muted); font-size: 12px; margin-top: 4px; }

    /* Filter Bar */
    .filter-card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; margin-bottom: 20px; }
    .filter-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    .filter-group { display: flex; align-items: center; gap: 8px; }
    .filter-group label { font-size: 11px; color: var(--text-muted); font-weight: 600; }
    .form-control { background-color: var(--bg-dark); border: 1px solid var(--border-color); color: var(--text-main); padding: 8px 12px; border-radius: 6px; font-size: 12px; outline: none; }
    .btn { background-color: var(--card-bg); border: 1px solid var(--border-color); color: var(--text-main); padding: 8px 14px; border-radius: 6px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-primary { background-color: var(--accent-yellow); color: #000; border: none; font-weight: 700; }

    /* Table Component */
    .table-card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
    .table-header { padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .table-header h3 { font-size: 14px; font-weight: 700; }
    .data-table { width: 100%; border-collapse: collapse; text-align: left; }
    .data-table th { background-color: #111111; color: var(--accent-yellow); padding: 12px 16px; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid var(--border-color); }
    .data-table td { padding: 12px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.03); font-size: 12px; }
    .data-table tbody tr:hover { background-color: rgba(255, 255, 255, 0.02); }

    .unit-tag { font-weight: 700; color: var(--accent-yellow); }
    .img-thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer; }
    .no-photo { color: var(--text-muted); font-size: 11px; }
    .photo-modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.88); align-items: center; justify-content: center; padding: 24px; }
    .photo-modal img { max-width: 92%; max-height: 85vh; object-fit: contain; border-radius: 8px; border: 2px solid var(--accent-yellow); }
    .photo-modal-close { position: absolute; top: 18px; right: 26px; color: #fff; font-size: 32px; font-weight: 800; cursor: pointer; }
    .empty-state { text-align: center; color: var(--text-muted); padding: 30px 0; font-size: 13px; }
    .table-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; color: var(--text-muted); font-size: 12px; border-top: 1px solid var(--border-color); }
    .pagination { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .page-link { background: var(--card-bg); border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none; padding: 8px 11px; border-radius: 8px; font-size: 12px; font-weight: 800; }
    .page-link.active { background: var(--accent-yellow); border-color: var(--accent-yellow); color: #000; }
    .page-link.disabled { opacity: 0.45; pointer-events: none; }

    .sidebar { width: 250px !important; min-width: 250px !important; max-width: 250px !important; justify-content: flex-start !important; padding: 20px 15px !important; box-sizing: border-box; position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; overflow-x: hidden; z-index: 20; }
    .main-content { margin-left: 250px; min-height: 100vh; }
    .brand { gap: 12px !important; margin-bottom: 35px !important; padding-left: 5px; }
    .brand-logo { width: 42px !important; height: 42px !important; border-radius: 12px !important; padding: 4px !important; flex-shrink: 0; }
    .brand-text h2 { font-size: 15px !important; letter-spacing: 0.5px; line-height: 1.1; color: #fff; }
    .brand-text p { font-size: 10px !important; margin-top: 2px; }
    .nav-item { gap: 14px !important; padding: 12px 16px !important; border-radius: 12px !important; font-size: 13px !important; font-weight: 700 !important; width: 100%; box-sizing: border-box; }
    .nav-item.active { font-weight: 800 !important; box-shadow: 0 4px 12px rgba(255, 184, 0, 0.2); }
    .nav-item.active i { color: #000 !important; }
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-thumb { background: #303030; border-radius: 999px; }
  </style>
</head>
<body>

  <aside class="sidebar">
    <div>
      <div class="brand">
        <div class="brand-logo"><img src="img/logo.png" alt="United Tractors"></div>
        <div class="brand-text">
          <h2>UNITED TRACTORS</h2>
          <p>Fuel Monitoring System</p>
        </div>
      </div>
      <nav class="nav-menu">
        <a href="dashboard.php" class="nav-item"><i data-lucide="layout-dashboard" size="18"></i> Dashboard</a>
        <a href="riwayat.php" class="nav-item active"><i data-lucide="history" size="18"></i> Riwayat Pengisian</a>
        <a href="laporan.php" class="nav-item"><i data-lucide="file-text" size="18"></i> Laporan</a>
        <a href="profil.php" class="nav-item"><i data-lucide="user-cog" size="18"></i> Profil</a>
        <a href="qr_fuel.php" class="nav-item"><i data-lucide="qr-code" size="18"></i> QR Fuel</a>
        <a href="logout.php" class="nav-item"><i data-lucide="log-out" size="18"></i> Logout</a>
        <button type="button" class="theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>
      </nav>
    </div>
  </aside>

  <main class="main-content">
    <div class="page-header">
      <div class="page-title">
        <h1>RIWAYAT PENGISIAN BBM</h1>
        <p>Log mutasi & histori pengisian solar unit operasional</p>
      </div>
    </div>

    <div class="filter-card">
      <form method="GET" action="riwayat.php" class="filter-form" id="historyFilterForm">
        <div class="filter-group">
          <label>Cari:</label>
          <input type="text" name="search" class="form-control" placeholder="Kode Unit / Operator..." value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="filter-group">
          <label>Kategori:</label>
          <select name="category" class="form-control">
            <option value="">Semua Kategori</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['category_id'] ?>" <?= $category == $cat['category_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['category_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filter-group">
          <label>Periode:</label>
          <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
          <span style="color: var(--text-muted);">-</span>
          <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
        </div>

        <div class="filter-group">
          <label>Per Halaman:</label>
          <select name="per_page" class="form-control">
            <?php foreach ([10, 25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <a href="riwayat.php" class="btn btn-primary"><i data-lucide="rotate-ccw" size="14"></i> Reset</a>
      </form>
    </div>

    <div class="table-card">
      <div class="table-header">
        <h3>LOG DOKUMEN TRANSAKSI</h3>
        <span style="font-size: 11px; color: var(--text-muted);">Total <strong><?= e($totalRows) ?></strong> data ditemukan</span>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Waktu / Tanggal</th>
            <th>Kode Unit</th>
            <th>Kategori Model</th>
            <th>Meteran Besar Flow Meter</th>
            <th>Control</th>
            <th>Meteran Kecil Flow Meter</th>
            <th>HM/KM Kendaraan</th>
            <th>Petugas / Operator</th>
            <th>Dokumentasi</th>
            <th>Foto Flow Meter</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr>
              <td colspan="10" class="empty-state">
                <i data-lucide="inbox" size="32" style="margin-bottom: 8px; opacity: 0.5;"></i><br>
                Belum ada data riwayat pengisian BBM.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td><?= date('d/m/Y H:i', strtotime($log['fuel_date'])) ?></td>
                <td><span class="unit-tag"><?= htmlspecialchars($log['unit_code']) ?></span></td>
                <td><?= htmlspecialchars($log['category_name']) ?></td>
                <td><strong><?= number_format($log['ltr_besar'] ?? $log['volume_liters'], 2, ',', '.') ?> L</strong></td>
                <td><?= number_format($log['control'] ?? $log['ltr_kecil'] ?? 0, 2, ',', '.') ?> L</td>
                <td><?= number_format($log['ltr_kecil'] ?? 0, 2, ',', '.') ?> L</td>
                <td><?= number_format($log['hm_awal'] ?? 0, 1, ',', '.') ?></td>
                <td><?= htmlspecialchars($log['full_name']) ?></td>
                <td>
                  <?php $fotoFormUrl = local_upload_url($log['foto_form'] ?? null); ?>
                  <?php if ($fotoFormUrl): ?>
                    <img src="<?= e($fotoFormUrl) ?>" class="img-thumb" alt="Dokumentasi Pengisian" onclick="zoomHistoryPhoto(this.src)" title="Klik untuk memperbesar">
                  <?php else: ?>
                    <span class="no-photo">Tidak ada</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php $fotoKmUrl = local_upload_url($log['foto_km'] ?? null); ?>
                  <?php if ($fotoKmUrl): ?>
                    <img src="<?= e($fotoKmUrl) ?>" class="img-thumb" alt="Foto Flow Meter" onclick="zoomHistoryPhoto(this.src)" title="Klik untuk memperbesar">
                  <?php else: ?>
                    <span class="no-photo">Tidak ada</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="table-footer">
        <span>Menampilkan <?= e($totalRows === 0 ? 0 : $offset + 1) ?>-<?= e(min($offset + $perPage, $totalRows)) ?> dari <?= e($totalRows) ?> data</span>
        <div class="pagination">
          <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="riwayat.php?<?= e(riwayat_query(['page' => max(1, $page - 1)])) ?>">Sebelumnya</a>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="riwayat.php?<?= e(riwayat_query(['page' => $i])) ?>"><?= e($i) ?></a>
          <?php endfor; ?>
          <a class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>" href="riwayat.php?<?= e(riwayat_query(['page' => min($totalPages, $page + 1)])) ?>">Berikutnya</a>
        </div>
      </div>
    </div>

  </main>

  <div id="historyPhotoModal" class="photo-modal" onclick="closeHistoryPhoto()">
    <span class="photo-modal-close">&times;</span>
    <img id="historyPhotoImage" src="" alt="Pratinjau foto">
  </div>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/session-keepalive.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>
    lucide.createIcons();

    const historyFilterForm = document.getElementById('historyFilterForm');
    const historySearchInput = historyFilterForm.querySelector('[name="search"]');
    const instantHistoryFilters = historyFilterForm.querySelectorAll('select, input[type="date"]');
    let historySearchTimer;

    instantHistoryFilters.forEach((filter) => {
      filter.addEventListener('change', () => historyFilterForm.requestSubmit());
    });

    historySearchInput.addEventListener('input', () => {
      clearTimeout(historySearchTimer);
      historySearchTimer = setTimeout(() => historyFilterForm.requestSubmit(), 500);
    });

    function zoomHistoryPhoto(src) {
      document.getElementById('historyPhotoImage').src = src;
      document.getElementById('historyPhotoModal').style.display = 'flex';
    }

    function closeHistoryPhoto() {
      document.getElementById('historyPhotoModal').style.display = 'none';
      document.getElementById('historyPhotoImage').src = '';
    }
  </script>
</body>
</html>
