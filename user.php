<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

date_default_timezone_set('Asia/Makassar');

$message = '';
$messageType = '';
$fieldErrors = [];
$oldInput = [];
if (isset($_SESSION['fuel_flash']) && is_array($_SESSION['fuel_flash'])) {
    $message = (string) ($_SESSION['fuel_flash']['message'] ?? '');
    $messageType = (string) ($_SESSION['fuel_flash']['type'] ?? '');
    $fieldErrors = is_array($_SESSION['fuel_flash']['field_errors'] ?? null) ? $_SESSION['fuel_flash']['field_errors'] : [];
    $oldInput = is_array($_SESSION['fuel_flash']['old_input'] ?? null) ? $_SESSION['fuel_flash']['old_input'] : [];
    unset($_SESSION['fuel_flash']);
}

function uploaded_image_name(string $fieldName, string $prefix, string $uploadDir): ?string
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto gagal. Silakan pilih ulang file.');
    }

    if ($_FILES[$fieldName]['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 3 MB.');
    }

    if ($_FILES[$fieldName]['size'] <= 0 || !is_uploaded_file($_FILES[$fieldName]['tmp_name'])) {
        throw new RuntimeException('File upload tidak valid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES[$fieldName]['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedTypes[$mime])) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    }

    $imageInfo = @getimagesize($_FILES[$fieldName]['tmp_name']);
    if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime) {
        throw new RuntimeException('Isi file foto tidak valid.');
    }

    $safeName = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(12)), $allowedTypes[$mime]);
    $targetPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
        throw new RuntimeException('Foto gagal disimpan.');
    }

    return $safeName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operator_nrp = strtoupper(trim((string) ($_POST['operator_nrp'] ?? '')));
    $operator_name = '';
    $operator_department = '';
    $type_unit     = '';
    $nomor_polisi  = '';
    $no_lambung    = strtoupper(trim($_POST['no_lambung'] ?? ''));
    $vehicle_id    = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $area          = '';
    $ltr_besar_raw = trim($_POST['ltr_besar'] ?? '');
    $ltr_kecil_raw = trim($_POST['ltr_kecil'] ?? '');
    $hm_awal_raw   = trim($_POST['hm_awal'] ?? '');
    $ltr_besar     = $ltr_besar_raw === '' ? false : filter_var($ltr_besar_raw, FILTER_VALIDATE_FLOAT);
    $ltr_kecil     = $ltr_kecil_raw === '' ? false : filter_var($ltr_kecil_raw, FILTER_VALIDATE_FLOAT);
    $hm_awal       = $hm_awal_raw === '' ? false : filter_var($hm_awal_raw, FILTER_VALIDATE_FLOAT);
    $control       = 0.0;

    $fuel_datetime = date('Y-m-d H:i:s');
    $hm_akhir      = 0;
    $total_usage   = 0;
    $total_liters  = 0;
    $foto_form_name = null;
    $foto_km_name = null;
    $fuelLogSaved = false;

    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Sesi form tidak valid. Muat ulang halaman lalu coba lagi.');
        }

        $employeeStmt = $pdo->prepare(
            'SELECT nrp, full_name, department FROM employees WHERE nrp = :nrp AND is_active = 1 LIMIT 1'
        );
        $employeeStmt->execute(['nrp' => $operator_nrp]);
        $permitPerson = $employeeStmt->fetch();
        if ($operator_nrp === '') {
            $fieldErrors['operator_nrp'] = 'NRP wajib diisi.';
        } elseif (strlen($operator_nrp) > 30) {
            $fieldErrors['operator_nrp'] = 'NRP tidak valid.';
        } elseif (!$permitPerson) {
            $fieldErrors['operator_nrp'] = 'NRP tidak ditemukan di data permit.';
        } else {
            $operator_name = $permitPerson['full_name'];
            $operator_department = $permitPerson['department'];
        }
        if ($operator_name === '') {
            $fieldErrors['operator_name'] = 'Nama Lengkap tidak ditemukan.';
        }
        if ($vehicle_id === false || $vehicle_id === null || $no_lambung === '') {
            $fieldErrors['vehicle'] = 'Pilih Kendaraan wajib diisi.';
        }
        if ($ltr_besar_raw === '') {
            $fieldErrors['ltr_besar'] = 'Meteran Besar Flow Meter wajib diisi.';
        }
        if ($hm_awal_raw === '') {
            $fieldErrors['hm_awal'] = 'HM/KM Kendaraan wajib diisi.';
        }
        if (!isset($_FILES['foto_form']) || $_FILES['foto_form']['error'] === UPLOAD_ERR_NO_FILE) {
            $fieldErrors['foto_form'] = 'Dokumentasi Pengisian wajib diisi.';
        }
        if (!isset($_FILES['foto_km']) || $_FILES['foto_km']['error'] === UPLOAD_ERR_NO_FILE) {
            $fieldErrors['foto_km'] = 'Foto Flow Meter wajib diisi.';
        }
        if (!empty($fieldErrors)) {
            throw new RuntimeException('Mohon lengkapi semua field wajib. Jika masih mengalami kendala, silakan hubungi GA.');
        }

        $vehicleStmt = $pdo->prepare(
            "SELECT unit_id, type_unit, nomor_polisi, no_lambung, area_location
             FROM units WHERE unit_id = :unit_id AND no_lambung = :no_lambung AND status = 'active' LIMIT 1"
        );
        $vehicleStmt->execute(['unit_id' => $vehicle_id, 'no_lambung' => $no_lambung]);
        $vehicle = $vehicleStmt->fetch();
        if (!$vehicle) {
            throw new RuntimeException('Data kendaraan yang dipilih tidak valid.');
        }
        $unit_id = (int) $vehicle['unit_id'];
        $type_unit = (string) $vehicle['type_unit'];
        $nomor_polisi = (string) $vehicle['nomor_polisi'];
        $no_lambung = (string) $vehicle['no_lambung'];
        $area = (string) $vehicle['area_location'];
        if ($ltr_besar === false || $ltr_kecil === false || $hm_awal === false) {
            throw new RuntimeException('Nilai liter dan kilometer harus berupa angka.');
        }
        if ($ltr_besar < 0 || $ltr_kecil < 0 || $hm_awal < 0) {
            throw new RuntimeException('Nilai liter dan kilometer tidak boleh negatif.');
        }

        if ($ltr_besar <= 0) {
            throw new RuntimeException('Meteran Besar Flow Meter harus lebih dari 0.');
        }

        $pdo->beginTransaction();
        $controlStmt = $pdo->prepare(
            'SELECT control FROM fuel_logs WHERE unit_id = :unit_id ORDER BY fuel_date DESC, log_id DESC LIMIT 1 FOR UPDATE'
        );
        $controlStmt->execute(['unit_id' => $unit_id]);
        $lastControl = $controlStmt->fetchColumn();
        $previousControl = $lastControl === false ? 0.0 : (float) $lastControl;
        $control = $previousControl + $ltr_besar;
        $hm_akhir = $control;
        if (abs($ltr_kecil - $control) > 0.00001) {
            $fieldErrors['ltr_kecil'] = 'Meteran Kecil Flow Meter harus sama dengan Control.';
            throw new RuntimeException(
                "Data pengisian tidak sesuai.\n\nMeteran Kecil Flow Meter harus sama dengan Control. Jika masih terkendala, hubungi tim GA."
            );
        }
        $total_usage = $ltr_besar;
        $total_liters = $ltr_besar;

        // --- PROSES UPLOAD FOTO ---
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $foto_form_name = uploaded_image_name('foto_form', 'form', $uploadDir);
        $foto_km_name   = uploaded_image_name('foto_km', 'km', $uploadDir);

        // 1. Dapatkan user_id default secara aman
        $stmtUserDefault = $pdo->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 1");
        $defaultUser = $stmtUserDefault->fetch();

        if (!$defaultUser) {
            $systemHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $stmtInsertUser = $pdo->prepare("INSERT INTO users (nip, full_name, email, password_hash, role) VALUES (:nip, 'System Operator', :email, :password_hash, 'Operator')");
            $stmtInsertUser->execute([
                'nip' => 'SYSTEM-' . bin2hex(random_bytes(4)),
                'email' => 'operator-' . bin2hex(random_bytes(4)) . '@invalid.local',
                'password_hash' => $systemHash,
            ]);
            $default_user_id = $pdo->lastInsertId();
        } else {
            $default_user_id = $defaultUser['user_id'];
        }

        // Simpan transaksi dengan data pegawai dan kendaraan hasil lookup database.
        $sql = "INSERT INTO fuel_logs (
                    unit_id, 
                    user_id, 
                    operator_name, 
                    type_unit,
                    nomor_polisi,
                    no_lambung,
                    area_location, 
                    ltr_besar, 
                    ltr_kecil, 
                    control,
                    volume_liters, 
                    hm_awal, 
                    hm_akhir, 
                    hour_meter, 
                    fuel_date, 
                    notes, 
                    foto_form, 
                    foto_km
                ) VALUES (
                    :unit_id, 
                    :user_id, 
                    :operator_name, 
                    :type_unit,
                    :nomor_polisi,
                    :no_lambung,
                    :area_location, 
                    :ltr_besar, 
                    :ltr_kecil, 
                    :control,
                    :volume_liters, 
                    :hm_awal, 
                    :hm_akhir, 
                    :hour_meter, 
                    :fuel_date, 
                    :notes, 
                    :foto_form, 
                    :foto_km
                )";
        
        $notes = "Log pengisian otomatis via Web Form";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'unit_id'       => $unit_id,
            'user_id'       => $default_user_id,
            'operator_name' => $operator_name,
            'type_unit'     => $type_unit,
            'nomor_polisi'  => $nomor_polisi,
            'no_lambung'    => $no_lambung,
            'area_location' => $area,
            'ltr_besar'     => $ltr_besar,
            'ltr_kecil'     => $ltr_kecil,
            'control'       => $control,
            'volume_liters' => $total_liters,
            'hm_awal'       => $hm_awal,
            'hm_akhir'      => $hm_akhir,
            'hour_meter'    => $total_usage,
            'fuel_date'     => $fuel_datetime,
            'notes'         => $notes,
            'foto_form'     => $foto_form_name,
            'foto_km'       => $foto_km_name
        ]);

        $pdo->commit();
        $fuelLogSaved = true;

        $log_id = (int) $pdo->lastInsertId();
        $notificationData = [
            'code' => 'FL-' . str_pad((string) $log_id, 6, '0', STR_PAD_LEFT),
            'fuel_date' => $fuel_datetime,
            'operator_name' => $operator_name,
            'type_unit' => $type_unit,
            'nomor_polisi' => $nomor_polisi,
            'no_lambung' => $no_lambung,
            'area' => $area,
            'ltr_besar' => $ltr_besar,
            'ltr_kecil' => $ltr_kecil,
            'control' => $control,
            'total_liters' => $total_liters,
            'hm_awal' => $hm_awal,
            'hm_akhir' => $hm_akhir,
            'total_usage' => $total_usage,
            'foto_form' => $foto_form_name,
            'foto_km' => $foto_km_name,
        ];
        $notificationData['foto_form_url'] = public_upload_url($foto_form_name);
        $notificationData['foto_km_url'] = public_upload_url($foto_km_name);

        append_fuel_spreadsheet($notificationData);
        $sheetSynced = sync_google_sheet($notificationData);
        $emailSent = notify_admin_email($pdo, $notificationData);

        $message = "Data & Foto berhasil dikirim ke sistem!";
        if (!$sheetSynced) {
            $message .= " Spreadsheet online belum aktif/tersinkron, data tetap tersimpan di spreadsheet lokal.";
        }
        if (!$emailSent) {
            $message .= " Email admin belum aktif/terkirim.";
        }
        $messageType = "success";
        $_SESSION['fuel_flash'] = [
            'message' => $message,
            'type' => $messageType,
            'field_errors' => [],
            'old_input' => [],
        ];
        unset($_SESSION['csrf_token']);
        header('Location: user.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($fuelLogSaved ? [] : [$foto_form_name, $foto_km_name] as $uploadedName) {
            if ($uploadedName) {
                $uploadedPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($uploadedName);
                if (is_file($uploadedPath)) {
                    unlink($uploadedPath);
                }
            }
        }
        error_log('Fuel registration error: ' . $e->getMessage());
        $message = $e instanceof RuntimeException ? $e->getMessage() : "Gagal menyimpan data.";
        $messageType = "danger";
        $_SESSION['fuel_flash'] = [
            'message' => $message,
            'type' => $messageType,
            'field_errors' => $fieldErrors,
            'old_input' => [
                'operator_nrp' => $_POST['operator_nrp'] ?? '',
                'operator_name' => $_POST['operator_name'] ?? '',
                'operator_department' => $_POST['operator_department'] ?? '',
                'vehicle_id' => $_POST['vehicle_id'] ?? '',
                'type_unit' => $_POST['type_unit'] ?? '',
                'nomor_polisi' => $_POST['nomor_polisi'] ?? '',
                'no_lambung' => $_POST['no_lambung'] ?? '',
                'area' => $_POST['area'] ?? '',
                'ltr_besar' => $_POST['ltr_besar'] ?? '',
                'ltr_kecil' => $_POST['ltr_kecil'] ?? '',
                'hm_awal' => $_POST['hm_awal'] ?? '',
            ],
        ];
        header('Location: user.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fuel Registration - United Tractors</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/ui-polish.css">
  <style>
    :root {
      --bg-dark: #0a0a0a;
      --page-bg: #121212;
      --card-bg: #181818;
      --input-bg: #101010;
      --input-bg-soft: #202020;
      --border-color: #343434;
      --border-soft: #282828;
      --accent-yellow: #ffc400;
      --accent-yellow-soft: rgba(255, 196, 0, 0.13);
      --accent-blue: #38bdf8;
      --text-main: #ffffff;
      --text-muted: #94a3b8;
      --text-soft: #cbd5e1;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body {
      background:
        radial-gradient(circle at 20% 0%, rgba(255, 196, 0, 0.10), transparent 32%),
        linear-gradient(180deg, var(--page-bg), var(--bg-dark));
      color: var(--text-main);
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
      padding: 34px 15px;
      font-size: 13px;
    }

    .brand-header {
      position: relative;
      width: 100%;
      max-width: 780px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 22px;
    }
    .brand-logo { display: inline-flex; align-items: center; gap: 10px; font-weight: 900; font-size: 17px; letter-spacing: 0.4px; }
    .brand-logo-img { width: 38px; height: 38px; background: #fff; border-radius: 10px; padding: 4px; object-fit: contain; box-shadow: 0 10px 22px rgba(0,0,0,0.25); }
    .brand-logo span { font-weight: 500; font-size: 11px; color: var(--text-muted); margin-left: 2px; }
    .brand-title { font-size: 30px; font-weight: 950; margin-top: 14px; letter-spacing: 0.8px; }
    .brand-title span { color: var(--accent-yellow); }
    .brand-subtitle { color: var(--text-muted); font-size: 12px; margin-top: 6px; }
    .brand-header .theme-toggle {
      position: absolute;
      top: 50%;
      right: 0;
      transform: translateY(-50%);
      width: auto;
      min-width: 124px;
      margin: 0;
      border-radius: 999px;
      padding: 9px 13px;
      background: rgba(255, 255, 255, 0.07);
      border: 1px solid rgba(255, 255, 255, 0.12);
      color: var(--text-soft);
      box-shadow: 0 12px 26px rgba(0, 0, 0, 0.20);
      font-size: 11px;
      letter-spacing: 0;
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .brand-header .theme-toggle:hover {
      transform: translateY(-50%) translateY(-1px);
      background: rgba(255, 196, 0, 0.14);
      color: var(--text-main);
      box-shadow: 0 14px 30px rgba(0, 0, 0, 0.24);
    }

    .form-card {
      background: linear-gradient(180deg, rgba(24, 24, 24, 0.98), rgba(14, 14, 14, 0.98));
      border: 1px solid var(--border-soft);
      border-radius: 14px;
      width: 100%;
      max-width: 780px;
      padding: 28px;
      box-shadow: 0 24px 60px rgba(0,0,0,0.42);
    }

    html[data-theme="light"] {
      --bg-dark: #eef1f5;
      --page-bg: #f8fafc;
      --card-bg: #ffffff;
      --input-bg: #ffffff;
      --input-bg-soft: #f1f5f9;
      --border-color: #d6dde7;
      --border-soft: #e2e8f0;
      --text-main: #111827;
      --text-muted: #64748b;
      --text-soft: #334155;
    }

    html[data-theme="light"] body {
      background:
        radial-gradient(circle at 18% 0%, rgba(255, 196, 0, 0.16), transparent 30%),
        linear-gradient(180deg, #ffffff, var(--bg-dark));
    }

    html[data-theme="light"] .brand-header .theme-toggle {
      background: rgba(255, 255, 255, 0.78);
      border-color: rgba(15, 23, 42, 0.10);
      color: #334155;
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.10);
    }

    html[data-theme="light"] .brand-header .theme-toggle:hover {
      background: #fff7d6;
      color: #111827;
      border-color: rgba(255, 196, 0, 0.55);
    }

    html[data-theme="light"] .form-card {
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
      border-color: var(--border-color);
      box-shadow: 0 24px 60px rgba(16, 24, 40, 0.12);
    }

    html[data-theme="light"] .form-control,
    html[data-theme="light"] .combo-list,
    html[data-theme="light"] .preview-container {
      background-color: #ffffff;
    }

    html[data-theme="light"] .form-control:focus {
      background-color: #ffffff;
    }

    html[data-theme="light"] .readonly-field {
      background-color: #f1f5f9;
      color: #334155;
    }

    html[data-theme="light"] .upload-box {
      background-color: #f8fafc;
      border-color: #cbd5e1;
    }

    html[data-theme="light"] .upload-box:hover {
      background-color: #fff8dc;
      border-color: var(--accent-yellow);
    }

    html[data-theme="light"] .combo-option {
      border-bottom-color: #edf2f7;
    }

    html[data-theme="light"] .alert[style] {
      background: rgba(56,189,248,0.08) !important;
      border-color: rgba(14,165,233,0.28) !important;
      color: #334155 !important;
    }

    .section-title { display: flex; align-items: center; gap: 8px; color: var(--accent-yellow); font-size: 12px; font-weight: 900; text-transform: uppercase; margin-top: 24px; margin-bottom: 13px; letter-spacing: 0.4px; }
    .section-title:first-of-type { margin-top: 0; }
    .section-title i { background: var(--accent-yellow-soft); border-radius: 7px; padding: 4px; }

    .form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 13px; }
    .form-group label { font-size: 11px; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.25px; }

    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }

    .form-control {
      background-color: var(--input-bg);
      border: 1px solid var(--border-color);
      color: var(--text-main);
      padding: 11px 14px;
      border-radius: 8px;
      font-size: 13px;
      outline: none;
      width: 100%;
      transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
    }
    .form-control:focus { border-color: var(--accent-yellow); box-shadow: 0 0 0 3px rgba(255, 196, 0, 0.12); background-color: #141414; }
    .form-control::placeholder { color: #64748b; }
    select.form-control { cursor: pointer; }
    .readonly-field { background-color: var(--input-bg-soft); color: var(--accent-yellow); font-weight: 800; cursor: default; }
    .required-mark { color: #ef4444; margin-left: 3px; }
    .required-note { color: var(--text-muted); font-size: 11px; font-weight: 700; margin: 4px 0 14px; }
    .field-error { color: #f87171; font-size: 11px; font-weight: 700; min-height: 14px; }
    .form-error { border-color: #ef4444; }
    .combo-wrapper { position: relative; }
    .combo-list {
      display: none;
      position: absolute;
      z-index: 50;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      max-height: 132px;
      overflow-y: auto;
      background: var(--input-bg);
      border: 1px solid var(--accent-yellow);
      border-radius: 8px;
      box-shadow: 0 18px 36px rgba(0,0,0,0.42);
    }
    .combo-list.show { display: block; }
    .combo-option {
      padding: 11px 14px;
      cursor: pointer;
      font-size: 12px;
      color: var(--text-main);
      border-bottom: 1px solid rgba(255,255,255,0.04);
    }
    .combo-option:hover,
    .combo-option.active {
      background: var(--accent-yellow);
      color: #000;
      font-weight: 800;
    }
    .combo-empty {
      padding: 10px 14px;
      color: var(--text-muted);
      font-size: 12px;
    }
    .input-unit-wrapper { position: relative; }
    .input-unit-wrapper input { padding-right: 45px; }
    .unit-suffix { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 11px; font-weight: 700; pointer-events: none; }
    .unit-suffix.yellow { color: var(--accent-yellow); font-weight: 800; }
    .expected-meter-wrapper {
      display: flex;
      justify-content: flex-end;
      margin-top: -1px;
      padding-right: 0;
    }
    .expected-meter-field {
      width: 180px;
      max-width: 58%;
      text-align: right;
      font-size: 12px;
      border-top-right-radius: 0;
      border-top-left-radius: 0;
    }
    .meter-control-group .input-unit-wrapper input {
      border-bottom-right-radius: 0;
    }
    .submit-note {
      margin: 0 0 10px;
      padding: 10px 12px;
      border: 1px solid rgba(255, 196, 0, 0.28);
      border-radius: 8px;
      background: rgba(255, 196, 0, 0.08);
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 700;
      line-height: 1.45;
    }

    /* CSS UPLOAD & PREVIEW */
    .upload-wrapper { position: relative; }
    .upload-box { 
      border: 1px dashed #334155; 
      background-color: rgba(32, 32, 32, 0.72); 
      border-radius: 10px; 
      padding: 14px; 
      text-align: left; 
      display: flex; 
      align-items: center; 
      gap: 12px; 
      cursor: pointer; 
      transition: 0.2s; 
      min-height: 64px;
    }
    .upload-box:hover { border-color: var(--accent-yellow); background-color: rgba(255, 196, 0, 0.08); }
    .upload-icon { background-color: var(--accent-yellow); color: #000; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .upload-text p { font-weight: 700; font-size: 12px; }
    .upload-text span { color: var(--text-muted); font-size: 10px; }
    
    /* AREA JIKA SUDAH ADA FOTO */
    .preview-container {
      display: none;
      align-items: center;
      justify-content: space-between;
      border: 1px solid var(--border-color);
      background-color: var(--input-bg-soft);
      border-radius: 10px;
      padding: 8px 12px;
      min-height: 64px;
    }
    .preview-info { display: flex; align-items: center; gap: 10px; cursor: pointer; flex-grow: 1; }
    .img-preview-thumb { 
      width: 44px; 
      height: 44px; 
      object-fit: cover; 
      border-radius: 6px; 
      border: 1px solid var(--accent-yellow);
    }
    .preview-actions { display: flex; gap: 6px; }
    .btn-cancel {
      background: rgba(239, 68, 68, 0.2);
      border: 1px solid #ef4444;
      color: #ef4444;
      border-radius: 6px;
      padding: 6px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .btn-cancel:hover { background: #ef4444; color: #fff; }

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

    .form-actions { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-top: 26px; }
    .btn { padding: 13px; border-radius: 9px; font-weight: 900; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; text-transform: uppercase; border: none; transition: transform 0.15s, box-shadow 0.15s, background-color 0.15s; }
    .btn:hover { transform: translateY(-1px); }
    .btn-submit { background-color: var(--accent-yellow); color: #000; box-shadow: 0 12px 22px rgba(255, 196, 0, 0.2); }
    .btn-reset { background-color: var(--input-bg-soft); border: 1px solid var(--border-color); color: var(--text-soft); }

    .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 12px; font-weight: 700; white-space: pre-line; }
    .alert-success { background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; }
    .alert-danger { background-color: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; }

    @media (max-width: 760px) {
      body { padding: 22px 12px; }
      .brand-header {
        justify-content: space-between;
        gap: 12px;
      }
      .brand-logo {
        flex: 1;
        justify-content: flex-start;
        min-width: 0;
      }
      .brand-logo-img {
        width: 34px;
        height: 34px;
        border-radius: 8px;
      }
      .brand-title {
        font-size: 22px;
        margin-top: 0;
        white-space: nowrap;
      }
      .brand-title span {
        display: block;
        font-size: 8px;
        letter-spacing: 0.8px;
        margin-top: 1px;
      }
      .brand-header .theme-toggle {
        position: static;
        transform: none;
        min-width: 96px;
        flex-shrink: 0;
        padding: 8px 10px;
        font-size: 10px;
      }
      .brand-header .theme-toggle:hover {
        transform: translateY(-1px);
      }
      .form-card { padding: 20px; }
      .grid-2,
      .grid-3,
      .grid-4,
      .form-actions { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <div class="brand-header">
    <div class="brand-logo">
      <img src="img/logo.png" alt="United Tractors" class="brand-logo-img">
      <div class="brand-title">FUEL <span>REGISTRATION</span></div>
    </div>
    <button type="button" class="theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>
  </div>

  <div class="form-card">
    <?php if (!empty($message)): ?>
      <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <div class="alert" style="background: rgba(56,189,248,0.10); border: 1px solid rgba(56,189,248,0.35); color: var(--text-soft);">
      <strong>Catatan:</strong><br>
      Pastikan No. Lambung, kilometer awal, dan angka meteran sesuai sebelum mengirim data.
    </div>

    <form action="user.php" method="POST" enctype="multipart/form-data" id="fuelForm">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      
      <div class="section-title"><i data-lucide="user" size="16"></i> DATA PENGISI</div>
      <div class="grid-3">
        <div class="form-group">
          <label>NRP<span class="required-mark">*</span></label>
          <div class="combo-wrapper" id="employee_combo">
            <input type="text" name="operator_nrp" id="operator_nrp" class="form-control <?= isset($fieldErrors['operator_nrp']) ? 'form-error' : '' ?>" placeholder="Ketik atau pilih NRP" value="<?= e($oldInput['operator_nrp'] ?? '') ?>" autocomplete="off" autocapitalize="characters" maxlength="30" required>
            <div class="combo-list" id="employee_list"></div>
          </div>
          <?php if (isset($fieldErrors['operator_nrp'])): ?><div class="field-error"><?= e($fieldErrors['operator_nrp']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label>Nama Lengkap<span class="required-mark">*</span></label>
          <input type="text" name="operator_name" id="operator_name" class="form-control readonly-field <?= isset($fieldErrors['operator_name']) ? 'form-error' : '' ?>" placeholder="Otomatis dari NRP" value="<?= e($oldInput['operator_name'] ?? '') ?>" readonly required>
          <?php if (isset($fieldErrors['operator_name'])): ?><div class="field-error"><?= e($fieldErrors['operator_name']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label>Departemen</label>
          <input type="text" name="operator_department" id="operator_department" class="form-control readonly-field" placeholder="Otomatis dari NRP" value="<?= e($oldInput['operator_department'] ?? '') ?>" readonly>
        </div>
      </div>
      <div class="required-note">* Wajib diisi</div>

      <div class="section-title"><i data-lucide="truck" size="16"></i> DATA UNIT</div>
      <div class="form-group">
        <label>Pilih Kendaraan / No. Lambung<span class="required-mark">*</span></label>
        <div class="combo-wrapper" id="vehicle_combo">
          <input type="text" id="vehicle_search" class="form-control <?= isset($fieldErrors['vehicle']) ? 'form-error' : '' ?>" placeholder="Ketik/Pilih No. Lambung" autocomplete="off" value="<?= e($oldInput['no_lambung'] ?? '') ?>" required>
          <input type="hidden" id="vehicle_selected" name="vehicle_id" value="<?= e($oldInput['vehicle_id'] ?? '') ?>">
          <div class="combo-list" id="vehicle_list"></div>
        </div>
        <?php if (isset($fieldErrors['vehicle'])): ?><div class="field-error"><?= e($fieldErrors['vehicle']) ?></div><?php endif; ?>
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>Type Unit</label>
          <input type="text" id="type_unit" name="type_unit" class="form-control readonly-field" placeholder="Otomatis dari kendaraan" value="<?= e($oldInput['type_unit'] ?? '') ?>" readonly required>
        </div>
        <div class="form-group">
          <label>Nomor Polisi</label>
          <input type="text" id="nomor_polisi" name="nomor_polisi" class="form-control readonly-field" placeholder="Otomatis dari kendaraan" value="<?= e($oldInput['nomor_polisi'] ?? '') ?>" readonly required>
        </div>
        <div class="form-group">
          <label>No. Lambung</label>
          <input type="text" id="no_lambung" name="no_lambung" class="form-control readonly-field" placeholder="Otomatis dari kendaraan" value="<?= e($oldInput['no_lambung'] ?? '') ?>" readonly required>
        </div>
        <div class="form-group">
          <label>Area</label>
          <input type="text" id="area" name="area" class="form-control readonly-field" placeholder="Otomatis dari kendaraan" value="<?= e($oldInput['area'] ?? '') ?>" readonly required>
        </div>
      </div>

      <div class="section-title"><i data-lucide="fuel" size="16"></i> DATA PENGISIAN</div>
      <div class="form-group">
        <label>Tanggal & Waktu</label>
        <input type="text" id="current_timestamp" class="form-control readonly-field" readonly>
      </div>

      <div class="submit-note">
        Syarat submit: Meteran Kecil Flow Meter harus sama dengan angka control kecil di bawahnya.
      </div>

      <div class="grid-2">
        <div class="form-group">
          <label>Meteran Besar Flow Meter<span class="required-mark">*</span></label>
          <div class="input-unit-wrapper">
            <input type="number" step="0.01" min="0" name="ltr_besar" id="ltr_besar" class="form-control <?= isset($fieldErrors['ltr_besar']) ? 'form-error' : '' ?>" placeholder="0" value="<?= e($oldInput['ltr_besar'] ?? '') ?>" required>
            <span class="unit-suffix">Liter</span>
          </div>
          <?php if (isset($fieldErrors['ltr_besar'])): ?><div class="field-error"><?= e($fieldErrors['ltr_besar']) ?></div><?php endif; ?>
        </div>
        <div class="form-group meter-control-group">
          <label>Meteran Kecil Flow Meter<span class="required-mark">*</span></label>
          <div class="input-unit-wrapper">
            <input type="number" step="0.01" min="0" name="ltr_kecil" id="ltr_kecil" class="form-control <?= isset($fieldErrors['ltr_kecil']) ? 'form-error' : '' ?>" placeholder="0" value="<?= e($oldInput['ltr_kecil'] ?? '') ?>" required>
            <span class="unit-suffix">Liter</span>
          </div>
          <div class="expected-meter-wrapper">
            <input type="text" id="control_display" class="form-control readonly-field expected-meter-field" placeholder="0" readonly>
          </div>
          <?php if (isset($fieldErrors['ltr_kecil'])): ?><div class="field-error"><?= e($fieldErrors['ltr_kecil']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="form-group">
        <label>HM/KM Kendaraan<span class="required-mark">*</span></label>
        <div class="input-unit-wrapper">
          <input type="number" step="0.01" min="0" id="hm_awal" name="hm_awal" class="form-control <?= isset($fieldErrors['hm_awal']) ? 'form-error' : '' ?>" placeholder="0" value="<?= e($oldInput['hm_awal'] ?? '') ?>" required>
          <span class="unit-suffix">KM</span>
        </div>
        <?php if (isset($fieldErrors['hm_awal'])): ?><div class="field-error"><?= e($fieldErrors['hm_awal']) ?></div><?php endif; ?>
      </div>

      <div class="section-title"><i data-lucide="camera" size="16"></i> DOKUMENTASI</div>
      <div class="grid-2">
        <div>
          <label style="font-size: 11px; color: #8a99ad; font-weight: 600; display: block; margin-bottom: 6px;">Dokumentasi Pengisian<span class="required-mark">*</span></label>
          <div class="upload-wrapper">
            <label class="upload-box" id="upload_box_form">
              <input type="file" id="input_foto_form" name="foto_form" accept="image/*" style="display: none;" onchange="handleFileSelect(this, 'form')" required>
              <div class="upload-icon"><i data-lucide="camera" size="18"></i></div>
              <div class="upload-text">
                <p>Upload Gambar</p>
                <span>Klik untuk memilih file</span>
              </div>
            </label>

            <div class="preview-container" id="preview_container_form">
              <div class="preview-info" onclick="zoomImage('img_form')">
                <img id="img_form" class="img-preview-thumb" alt="Preview">
                <div class="upload-text">
                  <p id="filename_form" style="color: var(--accent-yellow);">Foto Terpilih</p>
                  <span>Klik untuk lihat foto</span>
                </div>
              </div>
              <div class="preview-actions">
                <button type="button" class="btn-cancel" onclick="removePhoto('form')" title="Batalkan Foto">
                  <i data-lucide="x" size="16"></i>
                </button>
              </div>
            </div>
          </div>
          <?php if (isset($fieldErrors['foto_form'])): ?><div class="field-error"><?= e($fieldErrors['foto_form']) ?></div><?php endif; ?>
        </div>

        <div>
          <label style="font-size: 11px; color: #8a99ad; font-weight: 600; display: block; margin-bottom: 6px;">Foto Flow Meter<span class="required-mark">*</span></label>
          <div class="upload-wrapper">
            <label class="upload-box" id="upload_box_km">
              <input type="file" id="input_foto_km" name="foto_km" accept="image/*" style="display: none;" onchange="handleFileSelect(this, 'km')" required>
              <div class="upload-icon"><i data-lucide="camera" size="18"></i></div>
              <div class="upload-text">
                <p>Upload Gambar</p>
                <span>Klik untuk memilih file</span>
              </div>
            </label>

            <div class="preview-container" id="preview_container_km">
              <div class="preview-info" onclick="zoomImage('img_km')">
                <img id="img_km" class="img-preview-thumb" alt="Preview">
                <div class="upload-text">
                  <p id="filename_km" style="color: var(--accent-yellow);">Foto Terpilih</p>
                  <span>Klik untuk lihat foto</span>
                </div>
              </div>
              <div class="preview-actions">
                <button type="button" class="btn-cancel" onclick="removePhoto('km')" title="Batalkan Foto">
                  <i data-lucide="x" size="16"></i>
                </button>
              </div>
            </div>
          </div>
          <?php if (isset($fieldErrors['foto_km'])): ?><div class="field-error"><?= e($fieldErrors['foto_km']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-submit"><i data-lucide="send" size="16"></i> SUBMIT</button>
        <button type="reset" class="btn btn-reset" onclick="resetUsage()"><i data-lucide="rotate-ccw" size="16"></i> RESET</button>
      </div>

    </form>
  </div>

  <div id="imageModal" class="modal" onclick="closeZoom()">
    <span class="modal-close">&times;</span>
    <img id="modalImg" src="" alt="Zoom Foto">
  </div>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>
    lucide.createIcons();
    const operatorNrpInput = document.getElementById('operator_nrp');
    const operatorNameInput = document.getElementById('operator_name');
    const operatorDepartmentInput = document.getElementById('operator_department');
    const employeeList = document.getElementById('employee_list');
    const vehicleSearch = document.getElementById('vehicle_search');
    const vehicleSelected = document.getElementById('vehicle_selected');
    const vehicleList = document.getElementById('vehicle_list');
    const fuelForm = document.getElementById('fuelForm');
    const ltrBesarInput = document.getElementById('ltr_besar');
    const ltrKecilInput = document.getElementById('ltr_kecil');
    const hmAwalInput = document.getElementById('hm_awal');
    const controlDisplayInput = document.getElementById('control_display');
    let previousControl = null;
    let employeeValidNrp = '';
    let vehicleResults = [];
    let vehicleTimer = null;
    let employeeTimer = null;
    let vehicleRequest = null;
    let employeeRequest = null;
    let employeeSearchRequest = null;
    const fotoFormInput = document.getElementById('input_foto_form');
    const fotoKmInput = document.getElementById('input_foto_km');

    function vehicleLabel(vehicle) {
      return vehicle.lambung;
    }

    function vehicleDetailLabel(vehicle) {
      return `${vehicle.lambung} - ${vehicle.type} - ${vehicle.polisi} - ${vehicle.area}`;
    }

    function setVehicleStatus(message) {
      vehicleList.replaceChildren();
      const status = document.createElement('div');
      status.className = 'combo-empty';
      status.textContent = message;
      vehicleList.appendChild(status);
      vehicleList.classList.add('show');
    }

    async function renderVehicleList(keyword = '') {
      if (vehicleRequest) vehicleRequest.abort();
      vehicleRequest = new AbortController();
      setVehicleStatus('Memuat data...');
      try {
        const response = await fetch(`api/vehicles.php?q=${encodeURIComponent(keyword.trim().toUpperCase())}`, {
          signal: vehicleRequest.signal,
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('request_failed');
        const payload = await response.json();
        vehicleResults = Array.isArray(payload.data) ? payload.data.slice(0, 8) : [];
        vehicleList.replaceChildren();
        if (vehicleResults.length === 0) {
          setVehicleStatus('Data tidak ditemukan');
        } else {
          vehicleResults.forEach((vehicle, index) => {
          const option = document.createElement('div');
          option.className = 'combo-option';
          option.textContent = vehicleDetailLabel(vehicle);
          option.addEventListener('mousedown', function(e) {
            e.preventDefault();
            selectVehicle(index);
          });
          vehicleList.appendChild(option);
        });
          vehicleList.classList.add('show');
        }
      } catch (error) {
        if (error.name !== 'AbortError') setVehicleStatus('Koneksi gagal. Coba lagi.');
      }
    }

    function selectVehicle(index) {
      const vehicle = vehicleResults[index] || null;
      vehicleSelected.value = vehicle ? vehicle.id : '';
      vehicleSearch.value = vehicle ? vehicleLabel(vehicle) : '';
      setVehicleFields(vehicle);
      vehicleList.classList.remove('show');
      loadPreviousControl(vehicle ? vehicle.id : null);
    }

    function updateTimestamp() {
      const now = new Date();
      const formatted = new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'medium',
        hour12: false
      }).format(now);
      document.getElementById('current_timestamp').value = formatted;
    }

    function setVehicleFields(vehicle) {
      document.getElementById('type_unit').value = vehicle ? vehicle.type : '';
      document.getElementById('nomor_polisi').value = vehicle ? vehicle.polisi : '';
      document.getElementById('no_lambung').value = vehicle ? vehicle.lambung : '';
      document.getElementById('area').value = vehicle ? vehicle.area : '';
    }

    function setOperatorFields(person) {
      operatorNameInput.value = person ? person.name : '';
      operatorDepartmentInput.value = person ? person.department : '';
    }

    function setEmployeeStatus(message) {
      employeeList.replaceChildren();
      const status = document.createElement('div');
      status.className = 'combo-empty';
      status.textContent = message;
      employeeList.appendChild(status);
      employeeList.classList.add('show');
    }

    async function renderEmployeeList(keyword) {
      const query = keyword.trim().toUpperCase();
      if (!query) {
        employeeList.classList.remove('show');
        employeeList.replaceChildren();
        return;
      }
      if (employeeSearchRequest) employeeSearchRequest.abort();
      employeeSearchRequest = new AbortController();
      setEmployeeStatus('Memuat data...');
      try {
        const response = await fetch(`api/employee_nrps.php?q=${encodeURIComponent(query)}`, {
          signal: employeeSearchRequest.signal,
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('request_failed');
        const payload = await response.json();
        const employees = Array.isArray(payload.data) ? payload.data.slice(0, 8) : [];
        employeeList.replaceChildren();
        if (employees.length === 0) {
          setEmployeeStatus('NRP tidak ditemukan');
          return;
        }
        employees.forEach(employee => {
          const option = document.createElement('div');
          option.className = 'combo-option';
          option.textContent = `${employee.nrp} - ${employee.name}`;
          option.addEventListener('mousedown', function(e) {
            e.preventDefault();
            operatorNrpInput.value = employee.nrp;
            employeeList.classList.remove('show');
            lookupOperatorByNrp();
          });
          employeeList.appendChild(option);
        });
        employeeList.classList.add('show');
      } catch (error) {
        if (error.name !== 'AbortError') setEmployeeStatus('Koneksi gagal. Coba lagi.');
      }
    }

    async function lookupOperatorByNrp() {
      const nrp = operatorNrpInput.value.trim().toUpperCase();
      operatorNrpInput.value = nrp;
      employeeValidNrp = '';
      setOperatorFields(null);
      if (!nrp) return;
      if (employeeRequest) employeeRequest.abort();
      employeeRequest = new AbortController();
      operatorNameInput.placeholder = 'Memuat data...';
      try {
        const response = await fetch(`api/employee.php?nrp=${encodeURIComponent(nrp)}`, {
          signal: employeeRequest.signal,
          headers: { Accept: 'application/json' }
        });
        if (response.status === 404) {
          operatorNameInput.placeholder = 'Data tidak ditemukan';
          return;
        }
        if (!response.ok) throw new Error('request_failed');
        const payload = await response.json();
        if (payload.data) {
          employeeValidNrp = payload.data.nrp;
          setOperatorFields(payload.data);
        }
      } catch (error) {
        if (error.name !== 'AbortError') operatorNameInput.placeholder = 'Koneksi gagal. Coba lagi.';
      }
    }

    operatorNrpInput.addEventListener('input', function() {
      this.value = this.value.toUpperCase();
      employeeValidNrp = '';
      setOperatorFields(null);
      clearTimeout(employeeTimer);
      employeeTimer = setTimeout(() => renderEmployeeList(this.value), 300);
    });

    operatorNrpInput.addEventListener('focus', function() {
      if (this.value.trim()) renderEmployeeList(this.value);
    });

    operatorNrpInput.addEventListener('blur', function() {
      setTimeout(() => {
        employeeList.classList.remove('show');
        if (this.value.trim()) lookupOperatorByNrp();
      }, 120);
    });

    vehicleSearch.addEventListener('focus', function() {
      renderVehicleList(this.value);
    });

    vehicleSearch.addEventListener('input', function() {
      vehicleSelected.value = '';
      setVehicleFields(null);
      previousControl = null;
      updateControlMeter();
      clearTimeout(vehicleTimer);
      vehicleTimer = setTimeout(() => renderVehicleList(this.value), 300);
    });

    vehicleSearch.addEventListener('blur', function() {
      setTimeout(() => {
        vehicleList.classList.remove('show');
        if (vehicleSelected.value === '') {
          vehicleSearch.value = '';
        }
      }, 120);
    });

    document.addEventListener('click', function(e) {
      if (!document.getElementById('vehicle_combo').contains(e.target)) {
        vehicleList.classList.remove('show');
      }
      if (!document.getElementById('employee_combo').contains(e.target)) {
        employeeList.classList.remove('show');
      }
    });

    function numberFromInput(input) {
      if (!input.value.trim()) return null;
      const value = Number(input.value);
      return Number.isFinite(value) ? value : null;
    }

    function formatNumber(value) {
      return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
    }

    function updateControlMeter() {
      const ltrBesar = numberFromInput(ltrBesarInput);
      if (previousControl === null) {
        controlDisplayInput.value = '';
        return;
      }
      const control = ltrBesar !== null ? previousControl + ltrBesar : previousControl;
      const formatted = formatNumber(control);
      controlDisplayInput.value = formatted;
    }

    async function loadPreviousControl(unitId) {
      previousControl = null;
      updateControlMeter();
      if (!unitId) return;
      controlDisplayInput.placeholder = 'Memuat...';
      try {
        const response = await fetch(`api/control.php?unit_id=${encodeURIComponent(unitId)}`, {
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('request_failed');
        const payload = await response.json();
        previousControl = Number(payload.data.previous_control);
        updateControlMeter();
      } catch (error) {
        controlDisplayInput.value = '';
        controlDisplayInput.placeholder = 'Koneksi gagal';
      }
    }

    function setFieldError(input, message) {
      input.classList.add('form-error');
      const fieldRoot = input.closest('.form-group') || input.closest('.upload-wrapper').parentNode;
      let error = fieldRoot.querySelector('.field-error');
      if (!error) {
        error = document.createElement('div');
        error.className = 'field-error';
        fieldRoot.appendChild(error);
      }
      error.textContent = message;
    }

    function clearClientErrors() {
      fuelForm.querySelectorAll('.form-error').forEach(input => input.classList.remove('form-error'));
      fuelForm.querySelectorAll('.field-error').forEach(error => {
        if (!error.dataset.serverError) error.textContent = '';
      });
    }

    function showValidationMessage(message) {
      let alert = document.getElementById('client_validation_alert');
      if (!alert) {
        alert = document.createElement('div');
        alert.id = 'client_validation_alert';
        alert.className = 'alert alert-danger';
        fuelForm.parentNode.insertBefore(alert, fuelForm);
      }
      alert.textContent = message;
      alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    fuelForm.addEventListener('submit', function(e) {
      clearClientErrors();
      const errors = [];
      const nrp = operatorNrpInput.value.trim().toUpperCase();
      operatorNrpInput.value = nrp;

      if (!nrp) {
        errors.push('NRP wajib diisi.');
        setFieldError(operatorNrpInput, 'NRP wajib diisi.');
        operatorNrpInput.focus();
      } else if (employeeValidNrp !== nrp) {
        errors.push('NRP tidak ditemukan di data permit.');
        setFieldError(operatorNrpInput, 'NRP tidak ditemukan di data permit.');
        operatorNrpInput.focus();
      }

      if (vehicleSelected.value === '') {
        e.preventDefault();
        e.stopImmediatePropagation();
        errors.push('Pilih Kendaraan wajib diisi.');
        setFieldError(vehicleSearch, 'Pilih Kendaraan wajib diisi.');
        vehicleSearch.focus();
        renderVehicleList(vehicleSearch.value);
      }

      const ltrBesar = numberFromInput(ltrBesarInput);
      const ltrKecil = numberFromInput(ltrKecilInput);
      const hmAwal = numberFromInput(hmAwalInput);

      if (ltrBesar === null) {
        errors.push('Meteran Besar Flow Meter wajib diisi.');
        setFieldError(ltrBesarInput, 'Meteran Besar Flow Meter wajib diisi.');
      }
      if (ltrKecil === null) {
        errors.push('Meteran Kecil Flow Meter wajib diisi.');
        setFieldError(ltrKecilInput, 'Meteran Kecil Flow Meter wajib diisi.');
      }
      if (hmAwal === null) {
        errors.push('HM/KM Kendaraan wajib diisi.');
        setFieldError(hmAwalInput, 'HM/KM Kendaraan wajib diisi.');
      }
      if (!fotoFormInput.files.length) {
        errors.push('Dokumentasi Pengisian wajib diisi.');
        setFieldError(fotoFormInput, 'Dokumentasi Pengisian wajib diisi.');
      }
      if (!fotoKmInput.files.length) {
        errors.push('Foto Flow Meter wajib diisi.');
        setFieldError(fotoKmInput, 'Foto Flow Meter wajib diisi.');
      }

      if (ltrBesar !== null && ltrKecil !== null && previousControl !== null) {
        const expected = previousControl + ltrBesar;
        if (Math.abs(ltrKecil - expected) > 0.00001) {
          errors.push('Data pengisian tidak sesuai.\n\nMeteran Kecil Flow Meter harus sama dengan Control.');
          setFieldError(ltrKecilInput, `Harus sama dengan Control: ${formatNumber(expected)}.`);
        }
      }

      if (errors.length > 0) {
        e.preventDefault();
        e.stopImmediatePropagation();
        showValidationMessage(errors[0]);
      }
    }, true);

    updateTimestamp();
    setInterval(updateTimestamp, 1000);
    if (operatorNrpInput.value.trim()) lookupOperatorByNrp();
    if (vehicleSelected.value) loadPreviousControl(vehicleSelected.value);
    updateControlMeter();
    ltrBesarInput.addEventListener('input', updateControlMeter);

    // Fungsi Saat File Dipilih
    function handleFileSelect(input, type) {
      const file = input.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          document.getElementById('img_' + type).src = e.target.result;
          document.getElementById('filename_' + type).textContent = file.name.length > 15 ? file.name.substring(0, 12) + "..." : file.name;
          
          // Sembunyikan Box Upload, Tampilkan Box Preview
          document.getElementById('upload_box_' + type).style.display = 'none';
          document.getElementById('preview_container_' + type).style.display = 'flex';
        };
        reader.readAsDataURL(file);
      }
    }

    // Fungsi Batal / Hapus Foto
    function removePhoto(type) {
      document.getElementById('input_foto_' + type).value = '';
      document.getElementById('img_' + type).src = '';
      
      // Sembunyikan Preview, Tampilkan Box Upload Lagi
      document.getElementById('preview_container_' + type).style.display = 'none';
      document.getElementById('upload_box_' + type).style.display = 'flex';
    }

    // Fungsi Zoom Foto Full Screen Saat Diklik
    function zoomImage(imgId) {
      const imgSrc = document.getElementById(imgId).src;
      if(imgSrc) {
        document.getElementById('modalImg').src = imgSrc;
        document.getElementById('imageModal').style.display = 'flex';
      }
    }

    function closeZoom() {
      document.getElementById('imageModal').style.display = 'none';
    }

    function resetUsage() {
      setTimeout(() => { 
        fuelForm.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
          if (input.name !== 'csrf_token') input.value = '';
        });
        clearClientErrors();
        const clientAlert = document.getElementById('client_validation_alert');
        if (clientAlert) clientAlert.remove();
        vehicleSearch.value = '';
        vehicleSelected.value = '';
        setVehicleFields(null);
        previousControl = null;
        updateTimestamp();
        updateControlMeter();
        removePhoto('form');
        removePhoto('km');
      }, 50);
    }
  </script>
</body>
</html>
