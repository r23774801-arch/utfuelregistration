<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/media.php';

require_login();

$appConfig = require __DIR__ . '/includes/config.php';

function pdf_escape_text(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function report_code(array $row): string
{
    return 'FL-' . str_pad((string) ($row['log_id'] ?? 0), 6, '0', STR_PAD_LEFT);
}

function current_filter_query(array $override = []): string
{
    $params = array_merge($_GET, $override);
    return http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
}

function upload_public_url(?string $filename): string
{
    if (!$filename) {
        return '-';
    }

    $safeName = basename($filename);
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($path)) {
        return '-';
    }

    global $appConfig;
    if (!empty($appConfig['app_base_url'])) {
        return rtrim((string) $appConfig['app_base_url'], '/') . '/uploads/' . rawurlencode($safeName);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    return $scheme . '://' . $host . $basePath . '/uploads/' . rawurlencode($safeName);
}

function send_simple_pdf(array $rows): void
{
    $lines = ['LAPORAN PENGISIAN SOLAR', 'Generated: ' . date('d/m/Y H:i'), ''];
    $lines[] = 'Kode | Waktu | Operator | Type | Polisi | Lambung | Area | Meteran Besar | Control | Meteran Kecil | HM/KM Kendaraan';

    if (empty($rows)) {
        $lines[] = 'Tidak ada data pengisian pada filter/periode yang dipilih.';
    }

    foreach ($rows as $row) {
        $lines[] = implode(' | ', [
            report_code($row),
            date('d/m/Y H:i', strtotime($row['fuel_date'])),
            $row['operator_name'] ?: '-',
            $row['type_unit'] ?: '-',
            $row['nomor_polisi'] ?: '-',
            $row['no_lambung'] ?: ($row['unit_code'] ?: '-'),
            $row['area_location'] ?: '-',
            number_format((float) ($row['ltr_besar'] ?? 0), 2, '.', '') . ' L',
            number_format((float) ($row['control'] ?? $row['ltr_kecil'] ?? 0), 2, '.', '') . ' L',
            number_format((float) ($row['ltr_kecil'] ?? 0), 2, '.', '') . ' L',
            number_format((float) ($row['hm_awal'] ?? 0), 1, '.', ''),
        ]);
        $lines[] = 'Dokumentasi Pengisian: ' . upload_public_url($row['foto_form'] ?? null);
        $lines[] = 'Foto Flow Meter: ' . upload_public_url($row['foto_km'] ?? null);
    }

    $content = "BT\n/F1 8 Tf\n40 800 Td\n11 TL\n";
    foreach ($lines as $line) {
        $content .= '(' . pdf_escape_text(substr($line, 0, 190)) . ") Tj\nT*\n";
    }
    $content .= "ET";

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="laporan_pengisian_solar_' . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function send_excel(array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_pengisian_solar_' . date('Ymd_His') . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<tr><th>Kode</th><th>Waktu & Tanggal</th><th>Nama Operator</th><th>Type Unit</th><th>Nomor Polisi</th><th>No. Lambung</th><th>Area</th><th>Meteran Besar Flow Meter</th><th>Control</th><th>Meteran Kecil Flow Meter</th><th>Liter Pengisian</th><th>HM/KM Kendaraan</th><th>Selisih / Liter Pengisian</th><th>Dokumentasi Pengisian</th><th>Foto Flow Meter</th></tr>';
    if (empty($rows)) {
        echo '<tr><td colspan="15">Tidak ada data pengisian pada filter/periode yang dipilih.</td></tr>';
    }
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . e(report_code($row)) . '</td>';
        echo '<td>' . e(date('d/m/Y H:i', strtotime($row['fuel_date']))) . '</td>';
        echo '<td>' . e($row['operator_name'] ?? '-') . '</td>';
        echo '<td>' . e($row['type_unit'] ?? '-') . '</td>';
        echo '<td>' . e($row['nomor_polisi'] ?? '-') . '</td>';
        echo '<td>' . e($row['no_lambung'] ?? ($row['unit_code'] ?? '-')) . '</td>';
        echo '<td>' . e($row['area_location'] ?? '-') . '</td>';
        echo '<td>' . number_format((float) ($row['ltr_besar'] ?? 0), 2, '.', '') . '</td>';
        echo '<td>' . number_format((float) ($row['control'] ?? $row['ltr_kecil'] ?? 0), 2, '.', '') . '</td>';
        echo '<td>' . number_format((float) ($row['ltr_kecil'] ?? 0), 2, '.', '') . '</td>';
        echo '<td>' . number_format((float) ($row['total_liters'] ?? 0), 2, '.', '') . '</td>';
        echo '<td>' . number_format((float) ($row['hm_awal'] ?? 0), 1, '.', '') . '</td>';
        echo '<td>' . number_format((float) ($row['total_hm'] ?? 0), 1, '.', '') . '</td>';
        $fotoFormUrl = upload_public_url($row['foto_form'] ?? null);
        $fotoKmUrl = upload_public_url($row['foto_km'] ?? null);
        echo '<td>' . ($fotoFormUrl !== '-' ? '<a href="' . e($fotoFormUrl) . '">' . e($fotoFormUrl) . '</a>' : '-') . '</td>';
        echo '<td>' . ($fotoKmUrl !== '-' ? '<a href="' . e($fotoKmUrl) . '">' . e($fotoKmUrl) . '</a>' : '-') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

try {
    // 1. Cek ketersediaan kolom di tabel fuel_logs agar tidak terjadi error SQL
    $columnsStmt = $pdo->query("SHOW COLUMNS FROM fuel_logs");
    $existingColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);

    $hasOpName   = in_array('operator_name', $existingColumns);
    $hasArea     = in_array('area_location', $existingColumns);
    $hasLtrB     = in_array('ltr_besar', $existingColumns);
    $hasLtrK     = in_array('ltr_kecil', $existingColumns);
    $hasControl  = in_array('control', $existingColumns);
    $hasHmAwal   = in_array('hm_awal', $existingColumns);
    $hasHmAkhir  = in_array('hm_akhir', $existingColumns);
    $hasTypeUnit = in_array('type_unit', $existingColumns);
    $hasPolisi   = in_array('nomor_polisi', $existingColumns);
    $hasLambung  = in_array('no_lambung', $existingColumns);
    $hasFotoForm = in_array('foto_form', $existingColumns);
    $hasFotoKm   = in_array('foto_km', $existingColumns);

    // 2. Susun Query SQL secara dinamis & aman
    $selectOpName  = $hasOpName   ? "fl.operator_name" : "NULL AS operator_name";
    $selectArea    = $hasArea     ? "fl.area_location" : "NULL AS area_location";
    $selectLtrB    = $hasLtrB     ? "fl.ltr_besar"     : "0 AS ltr_besar";
    $selectLtrK    = $hasLtrK     ? "fl.ltr_kecil"     : "0 AS ltr_kecil";
    $selectControl = $hasControl  ? "fl.control"       : ($hasLtrK ? "fl.ltr_kecil AS control" : "0 AS control");
    $selectHmAwal  = $hasHmAwal   ? "fl.hm_awal"       : "0 AS hm_awal";
    $selectHmAkhir = $hasHmAkhir  ? "fl.hm_akhir"      : "0 AS hm_akhir";
    $selectType    = $hasTypeUnit ? "fl.type_unit"     : "NULL AS type_unit";
    $selectPolisi  = $hasPolisi   ? "fl.nomor_polisi"  : "NULL AS nomor_polisi";
    $selectLambung = $hasLambung  ? "fl.no_lambung"    : "NULL AS no_lambung";
    $selectFotoF   = $hasFotoForm ? "fl.foto_form"     : "NULL AS foto_form";
    $selectFotoK   = $hasFotoKm   ? "fl.foto_km"       : "NULL AS foto_km";

    $search = trim($_GET['search'] ?? '');
    $startDate = trim($_GET['start_date'] ?? '');
    $endDate = trim($_GET['end_date'] ?? '');
    $areaFilter = trim($_GET['area'] ?? '');
    $typeFilter = trim($_GET['type_unit'] ?? '');
    $perPage = (int) ($_GET['per_page'] ?? 10);
    $allowedPerPage = [10, 25, 50, 100];
    if (!in_array($perPage, $allowedPerPage, true)) {
        $perPage = 10;
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(fl.log_id = :search_log_id OR fl.operator_name LIKE :search_operator OR u.unit_code LIKE :search_unit OR {$selectType} LIKE :search_type OR {$selectPolisi} LIKE :search_polisi OR {$selectLambung} LIKE :search_lambung OR {$selectArea} LIKE :search_area)";
        $searchTerm = '%' . $search . '%';
        $params['search_log_id'] = (int) preg_replace('/\D/', '', $search);
        $params['search_operator'] = $searchTerm;
        $params['search_unit'] = $searchTerm;
        $params['search_type'] = $searchTerm;
        $params['search_polisi'] = $searchTerm;
        $params['search_lambung'] = $searchTerm;
        $params['search_area'] = $searchTerm;
    }
    if ($startDate !== '') {
        $where[] = "DATE(fl.fuel_date) >= :start_date";
        $params['start_date'] = $startDate;
    }
    if ($endDate !== '') {
        $where[] = "DATE(fl.fuel_date) <= :end_date";
        $params['end_date'] = $endDate;
    }
    if ($areaFilter !== '') {
        $where[] = "{$selectArea} = :area_filter";
        $params['area_filter'] = $areaFilter;
    }
    if ($typeFilter !== '') {
        $where[] = "{$selectType} = :type_filter";
        $params['type_filter'] = $typeFilter;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT 
                fl.log_id,
                {$selectOpName},
                u.unit_code,
                {$selectType},
                {$selectPolisi},
                {$selectLambung},
                fl.fuel_date,
                {$selectArea},
                {$selectLtrB},
                {$selectControl},
                {$selectLtrK},
                fl.volume_liters AS total_liters,
                {$selectHmAwal},
                {$selectHmAkhir},
                fl.hour_meter AS total_hm,
                fl.notes,
                {$selectFotoF},
                {$selectFotoK}
            FROM fuel_logs fl
            LEFT JOIN units u ON fl.unit_id = u.unit_id
            {$whereSql}
            ORDER BY fl.fuel_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allFilteredLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        send_excel($allFilteredLogs);
    }
    if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
        send_simple_pdf($allFilteredLogs);
    }

    $totalRows = count($allFilteredLogs);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $logs = array_slice($allFilteredLogs, $offset, $perPage);

    $areaOptions = $pdo->query("SELECT DISTINCT area_location FROM fuel_logs WHERE area_location IS NOT NULL AND area_location <> '' ORDER BY area_location ASC")->fetchAll(PDO::FETCH_COLUMN);
    $typeOptions = $pdo->query("SELECT DISTINCT type_unit FROM fuel_logs WHERE type_unit IS NOT NULL AND type_unit <> '' ORDER BY type_unit ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Laporan error: " . $e->getMessage());
    die("Gagal mengambil data laporan.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Laporan Pengisian Solar - United Tractors</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('solar-theme') || 'dark');</script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/ui-polish.css">
  <style>
    :root {
      --bg-dark: #0a0a0a;
      --card-bg: #181818;
      --sidebar-bg: #111111;
      --border-color: #303030;
      --accent-yellow: #ffb800;
      --text-main: #ffffff;
      --text-muted: #8a99ad;
      --input-bg: #202020;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { background-color: var(--bg-dark); color: var(--text-main); display: flex; min-height: 100vh; overflow-x: hidden; font-size: 13px; }

    /* LAYOUT SIDEBAR */
    .sidebar {
      width: 250px;
      background-color: var(--sidebar-bg);
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      padding: 20px 15px;
      flex-shrink: 0;
    }

    .brand-logo {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 35px;
      padding: 0 10px;
    }

    .brand-logo-icon {
      background-color: var(--accent-yellow);
      color: #000;
      font-weight: 900;
      font-size: 16px;
      width: 36px;
      height: 36px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .brand-text h1 { font-size: 15px; font-weight: 900; letter-spacing: 0.5px; line-height: 1.2; }
    .brand-text p { font-size: 10px; color: var(--text-muted); }
    .brand-logo-icon { background: #fff !important; overflow: hidden; padding: 4px; }
    .brand-logo-icon img { width: 100%; height: 100%; object-fit: contain; }

    .nav-menu { display: flex; flex-direction: column; gap: 8px; list-style: none; }
    
    .nav-link {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      color: #8a99ad;
      text-decoration: none;
      font-weight: 600;
      font-size: 13px;
      border-radius: 10px;
      transition: all 0.2s ease;
    }

    .nav-link:hover { color: var(--text-main); background-color: rgba(255, 255, 255, 0.04); }

    .nav-link.active {
      background-color: var(--accent-yellow);
      color: #000000;
      font-weight: 800;
    }

    .sidebar { width: 250px !important; min-width: 250px !important; max-width: 250px !important; padding: 20px 15px !important; box-sizing: border-box; position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto; overflow-x: hidden; z-index: 20; }
    .main-content { margin-left: 250px; min-height: 100vh; }
    .brand-logo { margin-bottom: 35px !important; padding-left: 5px !important; padding-right: 0 !important; }
    .brand-logo-icon { width: 42px !important; height: 42px !important; border-radius: 12px !important; flex-shrink: 0; }
    .brand-text h1 { font-size: 15px !important; font-weight: 800 !important; line-height: 1.1 !important; }
    .brand-text p { font-size: 10px !important; margin-top: 2px; }
    .nav-link { gap: 14px !important; border-radius: 12px !important; font-weight: 700 !important; width: 100%; box-sizing: border-box; }
    .nav-link.active { box-shadow: 0 4px 12px rgba(255, 184, 0, 0.2); }
    .nav-link.active i { color: #000 !important; }
    .sidebar::-webkit-scrollbar { width: 6px; }
    .sidebar::-webkit-scrollbar-thumb { background: #303030; border-radius: 999px; }

    /* MAIN CONTENT AREA */
    .main-content {
      flex: 1;
      padding: 30px;
      overflow-y: auto;
      background-color: var(--bg-dark);
    }

    .header-title {
      margin-bottom: 25px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .header-title h2 {
      font-size: 22px;
      font-weight: 900;
      letter-spacing: 0.5px;
    }

    .header-title h2 span {
      color: var(--accent-yellow);
    }

    .export-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn-export {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background-color: var(--input-bg);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      text-decoration: none;
      padding: 10px 14px;
      border-radius: 8px;
      font-weight: 800;
      font-size: 12px;
    }

    .btn-export.primary {
      background-color: var(--accent-yellow);
      color: #000;
      border-color: var(--accent-yellow);
    }

    .filter-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 18px;
    }

    .filter-form {
      display: grid;
      grid-template-columns: 1.4fr repeat(4, minmax(130px, 1fr)) auto auto;
      gap: 10px;
      align-items: end;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .filter-group label {
      color: var(--text-muted);
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .form-control {
      background: var(--input-bg);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      border-radius: 8px;
      padding: 10px 12px;
      outline: none;
      font-size: 12px;
    }

    .form-control:focus {
      border-color: var(--accent-yellow);
    }

    .btn-filter {
      background: var(--accent-yellow);
      color: #000;
      border: 0;
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 12px;
      font-weight: 900;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-height: 38px;
    }

    .btn-filter.secondary {
      background: var(--input-bg);
      color: var(--text-main);
      border: 1px solid var(--border-color);
    }

    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 16px;
      border-bottom: 1px solid var(--border-color);
    }

    .table-header h3 {
      font-size: 14px;
      font-weight: 700;
    }

    .table-header span,
    .table-footer {
      color: var(--text-muted);
      font-size: 12px;
    }

    .pagination {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .page-link {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      text-decoration: none;
      padding: 8px 11px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 800;
    }

    .page-link.active {
      background: var(--accent-yellow);
      border-color: var(--accent-yellow);
      color: #000;
    }

    .page-link.disabled {
      opacity: 0.45;
      pointer-events: none;
    }

    .table-card {
      background-color: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 15px 30px rgba(0,0,0,0.4);
    }

    .table-scroll { overflow-x: auto; }

    .table-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border-top: 1px solid var(--border-color);
    }

    table { width: 100%; border-collapse: collapse; text-align: left; min-width: 1100px; }
    
    th {
      background-color: #111111;
      color: var(--accent-yellow);
      padding: 14px 16px;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid var(--border-color);
      white-space: nowrap;
    }

    td {
      padding: 12px 16px; 
      border-bottom: 1px solid var(--border-color); 
      vertical-align: middle; 
      white-space: nowrap; 
      font-size: 12px;
    }

    tbody tr:last-child td { border-bottom: 0; }

    tr:hover { background-color: rgba(255, 184, 0, 0.02); }

    .img-thumb {
      width: 42px;
      height: 42px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid var(--border-color);
      cursor: pointer;
      transition: 0.2s;
    }

    .img-thumb:hover { 
      border-color: var(--accent-yellow); 
      transform: scale(1.08); 
    }

    .no-photo { color: var(--text-muted); font-size: 11px; font-style: italic; }

    /* MODAL ZOOM FOTO */
    .modal {
      display: none;
      position: fixed;
      z-index: 9999;
      left: 0; top: 0;
      width: 100%; height: 100%;
      background-color: rgba(0,0,0,0.85);
      justify-content: center;
      align-items: center;
    }
    .modal img { max-width: 90%; max-height: 80vh; border-radius: 8px; border: 2px solid var(--accent-yellow); }
    .modal-close {
      position: absolute; top: 20px; right: 25px; color: #fff; font-size: 30px; font-weight: bold; cursor: pointer;
    }
  </style>
</head>
<body>

  <aside class="sidebar">
    <div class="brand-logo">
      <div class="brand-logo-icon"><img src="img/logo.png" alt="United Tractors"></div>
      <div class="brand-text">
        <h1>UNITED TRACTORS</h1>
        <p>Fuel Monitoring System</p>
      </div>
    </div>

    <ul class="nav-menu">
      <li>
        <a href="dashboard.php" class="nav-link">
          <i data-lucide="layout-grid" size="18"></i> Dashboard
        </a>
      </li>
      <li>
        <a href="riwayat.php" class="nav-link">
          <i data-lucide="history" size="18"></i> Riwayat Pengisian
        </a>
      </li>
      <li>
        <a href="laporan.php" class="nav-link active">
          <i data-lucide="file-text" size="18"></i> Laporan
        </a>
      </li>
      <li>
        <a href="profil.php" class="nav-link">
          <i data-lucide="user-cog" size="18"></i> Profil
        </a>
      </li>
      <li>
        <a href="qr_fuel.php" class="nav-link">
          <i data-lucide="qr-code" size="18"></i> QR Fuel
        </a>
      </li>
      <li>
        <a href="logout.php" class="nav-link">
          <i data-lucide="log-out" size="18"></i> Logout
        </a>
      </li>
    </ul>
    <button type="button" class="theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>
  </aside>

  <main class="main-content">
    <div class="header-title">
      <h2>LAPORAN <span>PENGISIAN SOLAR</span></h2>
      <div class="export-actions">
        <a href="laporan.php?<?= e(current_filter_query(['export' => 'excel', 'page' => null])) ?>" class="btn-export primary"><i data-lucide="file-spreadsheet" size="16"></i> Export Excel</a>
        <a href="laporan.php?<?= e(current_filter_query(['export' => 'pdf', 'page' => null])) ?>" class="btn-export"><i data-lucide="file-down" size="16"></i> Export PDF</a>
      </div>
    </div>

    <div class="filter-card">
      <form method="GET" action="laporan.php" class="filter-form" id="reportFilterForm">
        <div class="filter-group">
          <label>Cari</label>
          <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Kode, operator, polisi, lambung, area">
        </div>
        <div class="filter-group">
          <label>Dari Tanggal</label>
          <input type="date" name="start_date" class="form-control" value="<?= e($startDate) ?>">
        </div>
        <div class="filter-group">
          <label>Sampai Tanggal</label>
          <input type="date" name="end_date" class="form-control" value="<?= e($endDate) ?>">
        </div>
        <div class="filter-group">
          <label>Area</label>
          <select name="area" class="form-control">
            <option value="">Semua area</option>
            <?php foreach ($areaOptions as $option): ?>
              <option value="<?= e($option) ?>" <?= $areaFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Type Unit</label>
          <select name="type_unit" class="form-control">
            <option value="">Semua type</option>
            <?php foreach ($typeOptions as $option): ?>
              <option value="<?= e($option) ?>" <?= $typeFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Per Halaman</label>
          <select name="per_page" class="form-control">
            <?php foreach ([10, 25, 50, 100] as $size): ?>
              <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <a href="laporan.php" class="btn-filter"><i data-lucide="rotate-ccw" size="15"></i> Reset</a>
      </form>
    </div>

    <div class="table-card">
      <div class="table-header">
        <h3>DATA LAPORAN PENGISIAN</h3>
        <span>Total <strong><?= e($totalRows) ?></strong> data ditemukan</span>
      </div>
      <div class="table-scroll">
        <table>
        <thead>
          <tr>
            <th>KODE</th>
            <th>WAKTU & TANGGAL</th>
            <th>NAMA OPERATOR</th>
            <th>TYPE UNIT</th>
            <th>NOMOR POLISI</th>
            <th>NO. LAMBUNG</th>
            <th>AREA</th>
            <th>METERAN BESAR FLOW METER</th>
            <th>CONTROL</th>
            <th>METERAN KECIL FLOW METER</th>
            <th>LITER PENGISIAN</th>
            <th>HM/KM KENDARAAN</th>
            <th>SELISIH / LITER PENGISIAN</th>
            <th>DOKUMENTASI PENGISIAN</th>
            <th>FOTO FLOW METER</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($logs)): ?>
            <?php foreach ($logs as $row): ?>
              <tr>
                <td><span style="color: var(--accent-yellow); font-weight:800;"><?= e(report_code($row)) ?></span></td>
                <td><?= date('d/m/Y H:i', strtotime($row['fuel_date'])) ?></td>
                <td><strong><?= htmlspecialchars($row['operator_name'] ?? '-') ?></strong></td>
                <td><?= htmlspecialchars($row['type_unit'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['nomor_polisi'] ?? '-') ?></td>
                <td><span style="color: var(--accent-yellow); font-weight:800;"><?= htmlspecialchars($row['no_lambung'] ?? ($row['unit_code'] ?? '-')) ?></span></td>
                <td><?= htmlspecialchars($row['area_location'] ?? '-') ?></td>
                <td><?= number_format($row['ltr_besar'] ?? 0, 2, ',', '.') ?> L</td>
                <td><?= number_format($row['control'] ?? $row['ltr_kecil'] ?? 0, 2, ',', '.') ?> L</td>
                <td><?= number_format($row['ltr_kecil'] ?? 0, 2, ',', '.') ?> L</td>
                <td><strong><?= number_format($row['total_liters'] ?? 0, 2, ',', '.') ?> L</strong></td>
                <td><?= number_format($row['hm_awal'] ?? 0, 1, ',', '.') ?></td>
                <td><strong style="color: var(--accent-yellow);"><?= number_format($row['total_hm'] ?? 0, 1, ',', '.') ?> L</strong></td>
                
                <td>
                  <?php $fotoFormLocalUrl = local_upload_url($row['foto_form'] ?? null); ?>
                  <?php if ($fotoFormLocalUrl): ?>
                    <img src="<?= e($fotoFormLocalUrl) ?>" class="img-thumb" onclick="zoomImage(this.src)" title="Klik untuk memperbesar" alt="Dokumentasi Pengisian">
                  <?php else: ?>
                    <span class="no-photo">Tidak ada</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php $fotoKmLocalUrl = local_upload_url($row['foto_km'] ?? null); ?>
                  <?php if ($fotoKmLocalUrl): ?>
                    <img src="<?= e($fotoKmLocalUrl) ?>" class="img-thumb" onclick="zoomImage(this.src)" title="Klik untuk memperbesar" alt="Foto Flow Meter">
                  <?php else: ?>
                    <span class="no-photo">Tidak ada</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="15" style="text-align: center; color: var(--text-muted); padding: 25px;">Belum ada data laporan pengisian solar.</td>
            </tr>
          <?php endif; ?>
        </tbody>
        </table>
      </div>
      <div class="table-footer">
        <span>Menampilkan <?= e($totalRows === 0 ? 0 : $offset + 1) ?>-<?= e(min($offset + $perPage, $totalRows)) ?> dari <?= e($totalRows) ?> data</span>
        <div class="pagination">
          <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="laporan.php?<?= e(current_filter_query(['page' => max(1, $page - 1), 'export' => null])) ?>">Sebelumnya</a>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="laporan.php?<?= e(current_filter_query(['page' => $i, 'export' => null])) ?>"><?= e($i) ?></a>
          <?php endfor; ?>
          <a class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>" href="laporan.php?<?= e(current_filter_query(['page' => min($totalPages, $page + 1), 'export' => null])) ?>">Berikutnya</a>
        </div>
      </div>
    </div>
  </main>

  <div id="imageModal" class="modal" onclick="closeZoom()">
    <span class="modal-close">&times;</span>
    <img id="modalImg" src="" alt="Zoom Foto">
  </div>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/session-keepalive.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>
    lucide.createIcons();

    const reportFilterForm = document.getElementById('reportFilterForm');
    const reportSearchInput = reportFilterForm.querySelector('[name="search"]');
    const instantReportFilters = reportFilterForm.querySelectorAll('select, input[type="date"]');
    let reportSearchTimer;

    instantReportFilters.forEach((filter) => {
      filter.addEventListener('change', () => reportFilterForm.requestSubmit());
    });

    reportSearchInput.addEventListener('input', () => {
      clearTimeout(reportSearchTimer);
      reportSearchTimer = setTimeout(() => reportFilterForm.requestSubmit(), 500);
    });

    function zoomImage(src) {
      document.getElementById('modalImg').src = src;
      document.getElementById('imageModal').style.display = 'flex';
    }

    function closeZoom() {
      document.getElementById('imageModal').style.display = 'none';
    }
  </script>
</body>
</html>
