<?php
require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$message = '';
$messageType = '';

$userId = current_user_id();

// 1. PROSES UPDATE PROFIL & PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_profile'])) {
    $nip         = trim($_POST['nip'] ?? '');
    $fullName    = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Sesi form tidak valid. Muat ulang halaman lalu coba lagi.');
        }
        if ($nip === '' || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('NIP, nama lengkap, dan email valid wajib diisi.');
        }

        // Update NIP, Nama & Email
        $stmt = $pdo->prepare("UPDATE users SET nip = :nip, full_name = :name, email = :email WHERE user_id = :id");
        $stmt->execute(['nip' => $nip, 'name' => $fullName, 'email' => $email, 'id' => $userId]);
        
        $message = "Profil berhasil diperbarui.";
        $messageType = "success";

        // Update Password (Jika Diisi)
        if (!empty($oldPassword) && !empty($newPassword)) {
            $stmtUser = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
            $stmtUser->execute(['id' => $userId]);
            $userData = $stmtUser->fetch();

            if ($userData && password_verify($oldPassword, $userData['password_hash'])) {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmtPass = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
                $stmtPass->execute(['hash' => $newHash, 'id' => $userId]);
                $message .= " Password berhasil diubah.";
            } else {
                $message = "Password lama tidak sesuai.";
                $messageType = "danger";
            }
        }
    } catch (Throwable $e) {
        error_log('Update profile error: ' . $e->getMessage());
        $message = $e instanceof RuntimeException ? $e->getMessage() : "Gagal memperbarui profil.";
        $messageType = "danger";
    }
}

// Ambil Data Profil User TERBARU
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
$stmtUser->execute(['id' => $userId]);
$user = $stmtUser->fetch() ?: [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profil - United Tractors</title>
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

    /* Layout Sidebar */
    .sidebar { width: 220px; background-color: var(--sidebar-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 15px; flex-shrink: 0; }
    .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 30px; }
    .brand-logo { background-color: #fff; width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 3px; }
    .brand-logo img { width: 100%; height: 100%; object-fit: contain; }
    .brand-text h2 { font-size: 13px; font-weight: 800; }
    .brand-text p { font-size: 9px; color: var(--text-muted); }
    .nav-menu { display: flex; flex-direction: column; gap: 8px; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; transition: all 0.2s; }
    .nav-item:hover { color: #fff; background-color: rgba(255, 255, 255, 0.05); }
    .nav-item.active { background-color: var(--accent-yellow); color: #000; font-weight: 700; }

    /* Content Area */
    .main-content { flex: 1; padding: 25px 30px; overflow-y: auto; }
    .page-title h1 { font-size: 24px; font-weight: 800; }
    .page-title p { color: var(--text-muted); font-size: 12px; margin-top: 4px; margin-bottom: 25px; }

    /* Grid Layout Form */
    .settings-grid { display: grid; grid-template-columns: minmax(320px, 720px); gap: 20px; }
    .card { background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; padding: 20px; }
    .card-title { font-size: 14px; font-weight: 700; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }

    /* Form Inputs */
    .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 15px; }
    .form-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
    .form-control { background-color: var(--bg-dark); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 12px; border-radius: 6px; font-size: 12px; outline: none; }
    .btn-primary { background-color: var(--accent-yellow); border: none; color: #000; font-weight: 700; padding: 10px 16px; border-radius: 6px; font-size: 12px; cursor: pointer; }

    /* Alert Banner */
    .alert-banner { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 600; font-size: 12px; }
    .alert-success { background-color: rgba(16, 185, 129, 0.15); color: var(--green-growth); border: 1px solid var(--green-growth); }
    .alert-danger { background-color: rgba(239, 68, 68, 0.15); color: var(--red-alert); border: 1px solid var(--red-alert); }

    /* Table Component */
    .data-table { width: 100%; border-collapse: collapse; text-align: left; }
    .data-table th { background-color: #202020; color: var(--text-muted); padding: 10px; font-size: 11px; border-bottom: 1px solid var(--border-color); }
    .data-table td { padding: 10px; border-bottom: 1px solid rgba(255, 255, 255, 0.03); font-size: 12px; }

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
        <a href="riwayat.php" class="nav-item"><i data-lucide="history" size="18"></i> Riwayat Pengisian</a>
        <a href="laporan.php" class="nav-item"><i data-lucide="file-text" size="18"></i> Laporan</a>
        <a href="profil.php" class="nav-item active"><i data-lucide="user-cog" size="18"></i> Profil</a>
        <a href="qr_fuel.php" class="nav-item"><i data-lucide="qr-code" size="18"></i> QR Fuel</a>
        <a href="logout.php" class="nav-item"><i data-lucide="log-out" size="18"></i> Logout</a>
        <button type="button" class="theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>
      </nav>
    </div>
  </aside>

  <main class="main-content">
    <div class="page-title">
      <h1>PROFIL ADMIN</h1>
      <p>Kelola data profil dan keamanan akun admin</p>
    </div>

    <?php if (!empty($message)): ?>
      <div class="alert-banner alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <div class="settings-grid">
      
      <div class="card">
        <div class="card-title"><i data-lucide="user" size="18"></i> Profil & Keamanan Admin</div>
        
        <form action="profil.php" method="POST" autocomplete="off">
          <!-- TRIK PENGECOH AUTOFILL BROWSER -->
          <input type="text" name="prevent_autofill_username" id="prevent_autofill_username" value="" style="display:none;" tabindex="-1" autocomplete="off" />
          <input type="password" name="prevent_autofill_password" id="prevent_autofill_password" value="" style="display:none;" tabindex="-1" autocomplete="off" />

          <input type="hidden" name="action_profile" value="1">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          
          <div class="form-group">
            <label>NIP / Pegawai</label>
            <input type="text" name="nip" class="form-control" value="<?= e($user['nip'] ?? '') ?>" autocomplete="off" required>
          </div>

          <div class="form-group">
            <label>Nama Lengkap</label>
            <input type="text" name="full_name" class="form-control" value="<?= e($user['full_name'] ?? '') ?>" autocomplete="off" required>
          </div>

          <div class="form-group">
            <label>Email Utama</label>
            <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" autocomplete="off" required>
          </div>

          <hr style="border: none; border-top: 1px solid var(--border-color); margin: 20px 0;">

          <div class="form-group">
            <label>Password Lama (Kosongkan jika tak diubah)</label>
            <input type="password" name="old_password" class="form-control" placeholder="••••••••" autocomplete="new-password">
          </div>

          <div class="form-group">
            <label>Password Baru</label>
            <input type="password" name="new_password" class="form-control" placeholder="••••••••" autocomplete="new-password">
          </div>

          <button type="submit" class="btn-primary" style="margin-top: 10px;">Simpan Perubahan Profil</button>
        </form>
      </div>
    </div>
  </main>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/session-keepalive.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>lucide.createIcons();</script>
</body>
</html>
