<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user_id() !== null) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
if (($_GET['timeout'] ?? '') === '1') {
    $message = 'Sesi admin berakhir karena tidak ada aktivitas selama 10 menit. Silakan login kembali.';
}
$needsSetup = false;

try {
    $setupUser = $pdo->query("SELECT password_hash FROM users ORDER BY user_id ASC LIMIT 1")->fetch();
    $needsSetup = !$setupUser || !password_get_info((string) ($setupUser['password_hash'] ?? ''))['algo'];
} catch (PDOException $e) {
    error_log('Login setup check error: ' . $e->getMessage());
}

if ($needsSetup) {
    header('Location: setup_admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'Sesi form tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif ($identity === '' || $password === '') {
        $message = 'Username/email dan password wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT user_id, nip, full_name, email, password_hash, role
                FROM users
                WHERE email = :identity_email OR nip = :identity_nip OR full_name = :identity_name
                LIMIT 1
            ");
            $stmt->execute([
                'identity_email' => $identity,
                'identity_nip' => $identity,
                'identity_name' => $identity,
            ]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, (string) $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['user_name'] = $user['full_name'] ?: $user['email'] ?: $user['nip'];
                $_SESSION['user_role'] = $user['role'] ?? 'admin';
                $_SESSION['last_activity_at'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $message = 'Login gagal. Periksa username/email dan password.';
        } catch (PDOException $e) {
            error_log('Login error: ' . $e->getMessage());
            $message = 'Login belum bisa diproses. Coba lagi nanti.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login Admin - PT United Tractors</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('solar-theme') || 'dark');</script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="assets/css/theme.css">
  <link rel="stylesheet" href="assets/css/ui-polish.css">
  <style>
    :root {
      --accent-yellow: #ffc700;
      --accent-yellow-hover: #e6b400;
      --login-card-bg: rgba(24, 24, 24, 0.94);
      --login-input-bg: #101010;
      --login-input-border: #343434;
      --login-text-main: #ffffff;
      --login-text-muted: #9aa4b2;
      --text-main: #ffffff;
      --text-muted: #8c9ba5;
      --red-alert: #ef4444;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    body { min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; background: linear-gradient(rgba(0,0,0,.36), rgba(0,0,0,.58)), url('img/latar.jpeg') center/cover no-repeat fixed; color: var(--text-main); }
    .top-header { padding: 30px 45px; display: flex; align-items: center; gap: 12px; z-index: 2; }
    .logo-container { display: flex; align-items: center; gap: 10px; }
    .logo-icon { background-color: #fff; width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 3px; }
    .logo-icon img { width: 100%; height: 100%; object-fit: contain; }
    .logo-text h2 { font-size: 16px; font-weight: 800; letter-spacing: .5px; line-height: 1.1; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,.5); }
    .logo-text p { font-size: 10px; color: #e2e8f0; text-shadow: 0 1px 3px rgba(0,0,0,.5); }
    .main-container { display: flex; justify-content: flex-end; align-items: center; padding: 20px 8%; flex-grow: 1; z-index: 2; }
    .login-card { background: var(--login-card-bg); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--login-input-border); border-radius: 18px; padding: 45px 38px; width: 100%; max-width: 430px; box-shadow: 0 25px 50px rgba(0,0,0,.55); text-align: center; }
    .user-avatar-circle { width: 64px; height: 64px; border: 2px solid var(--accent-yellow); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; color: var(--accent-yellow); }
    .login-card h1 { font-size: 22px; font-weight: 700; margin-bottom: 10px; letter-spacing: .5px; }
    .login-card h1 span { color: var(--accent-yellow); }
    .subtitle { font-size: 13px; color: var(--login-text-muted); line-height: 1.5; margin-bottom: 28px; }
    .form-group { text-align: left; margin-bottom: 18px; }
    .form-group label { display: block; font-size: 12px; color: var(--login-text-main); margin-bottom: 8px; font-weight: 500; }
    .input-wrapper { position: relative; display: flex; align-items: center; }
    .input-icon { position: absolute; left: 14px; color: var(--text-muted); }
    .toggle-password {
      position: absolute;
      right: 10px;
      width: 34px;
      height: 34px;
      border: 0;
      border-radius: 10px;
      background: transparent;
      color: var(--text-muted);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all .2s ease;
    }
    .toggle-password:hover {
      color: var(--accent-yellow);
      background: rgba(255, 184, 0, .10);
    }
    .form-control { width: 100%; background-color: var(--login-input-bg); border: 1px solid var(--login-input-border); border-radius: 8px; padding: 13px 42px; color: var(--login-text-main); font-size: 13px; outline: none; transition: all .2s; }
    .form-control:focus { border-color: var(--accent-yellow); }
    .form-options { display: flex; justify-content: flex-end; align-items: center; font-size: 12px; margin-bottom: 24px; }
    .forgot-link { color: var(--accent-yellow); text-decoration: none; }
    .forgot-link:hover { text-decoration: underline; }
    .btn-login { width: 100%; background-color: var(--accent-yellow); color: #000; border: none; padding: 13px; border-radius: 8px; font-size: 14px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: background .2s; letter-spacing: .5px; }
    .btn-login:hover { background-color: var(--accent-yellow-hover); }
    .alert { background-color: rgba(239,68,68,.15); color: var(--red-alert); border: 1px solid var(--red-alert); padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: 12px; font-weight: 600; text-align: left; }
    .admin-notice { margin-top: 28px; padding-top: 22px; border-top: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 11px; color: var(--text-muted); }
    .admin-notice i { color: var(--accent-yellow); flex-shrink: 0; }
    .footer { background-color: #0a0a0a; padding: 20px 45px; display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--text-muted); border-top: 1px solid rgba(255,255,255,.06); z-index: 2; }
    .footer-center { text-align: center; line-height: 1.4; }
    .login-theme-toggle { margin-top: 0; position: fixed; top: 24px; right: 28px; width: auto; min-width: 132px; z-index: 5; background: rgba(24,24,24,.88); border-color: #343434; color: #fff; }
    html[data-theme="light"] {
      --login-card-bg: rgba(255, 255, 255, 0.96);
      --login-input-bg: #f3f5f8;
      --login-input-border: #cfd6df;
      --login-text-main: #141414;
      --login-text-muted: #5d6775;
    }
    html[data-theme="light"] body { background: linear-gradient(rgba(255,255,255,.28), rgba(255,255,255,.48)), url('img/latar.jpeg') center/cover no-repeat fixed; }
    html[data-theme="light"] .login-card { border-color: #d7dce3; box-shadow: 0 25px 50px rgba(16,24,40,.18); }
    html[data-theme="light"] .footer { background: #ffffff; border-top-color: #d7dce3; }
    html[data-theme="light"] .logo-text h2,
    html[data-theme="light"] .login-card h1 { color: #141414; text-shadow: none; }
    html[data-theme="light"] .logo-text p { color: #5d6775; text-shadow: none; }
    html[data-theme="light"] .login-theme-toggle { background: rgba(255,255,255,.92); border-color: #cfd6df; color: #141414; box-shadow: 0 12px 26px rgba(16,24,40,.12); }
    html[data-theme="light"] .admin-notice { border-top-color: #e2e8f0; }
    @media (max-width: 768px) {
      .main-container { justify-content: center; padding: 20px 15px; }
      .top-header { padding: 20px; }
      .login-theme-toggle { position: absolute; top: 18px; right: 18px; min-width: 116px; padding: 9px 11px; }
      .footer { flex-direction: column; gap: 12px; text-align: center; padding: 20px; }
    }
  </style>
</head>
<body>
  <button type="button" class="theme-toggle login-theme-toggle" data-theme-toggle><i data-lucide="sun" size="16"></i> Mode Light</button>

  <header class="top-header">
    <div class="logo-container">
      <div class="logo-icon"><img src="img/logo.png" alt="United Tractors"></div>
      <div class="logo-text">
        <h2>UNITED TRACTORS</h2>
        <p>member of ASTRA</p>
      </div>
    </div>
  </header>

  <main class="main-container">
    <div class="login-card">
      <div class="user-avatar-circle"><i data-lucide="user" size="30"></i></div>
      <h1>LOGIN <span>ADMIN</span></h1>
      <p class="subtitle">Data Pengisian Solar<br>PT United Tractors</p>

      <?php if ($message !== ''): ?>
        <div class="alert"><?= e($message) ?></div>
      <?php endif; ?>

      <form action="login.php" method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label>Username / Email</label>
          <div class="input-wrapper">
            <i data-lucide="user" class="input-icon" size="18"></i>
            <input type="text" name="identity" class="form-control" placeholder="Masukkan NIP atau email" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label>Password</label>
          <div class="input-wrapper">
            <i data-lucide="lock" class="input-icon" size="18"></i>
            <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Masukkan password" required>
            <button type="button" id="togglePassword" class="toggle-password" aria-label="Lihat password" aria-pressed="false">
              <i data-lucide="eye" size="18"></i>
            </button>
          </div>
        </div>
        <div class="form-options">
          <a href="profil.php" class="forgot-link">Ubah password di profil</a>
        </div>
        <button type="submit" class="btn-login"><i data-lucide="log-in" size="18"></i> LOGIN</button>
      </form>

      <div class="admin-notice">
        <i data-lucide="shield-check" size="20"></i>
        <span>Akses hanya untuk admin yang berwenang</span>
      </div>
    </div>
  </main>

  <footer class="footer">
    <div class="logo-container">
      <div class="logo-icon" style="width:24px;height:24px;"><img src="img/logo.png" alt="United Tractors"></div>
      <div class="logo-text">
        <h2 style="font-size:12px;text-shadow:none;">UNITED TRACTORS</h2>
        <p style="font-size:9px;text-shadow:none;color:var(--text-muted);">member of ASTRA</p>
      </div>
    </div>
    <div class="footer-center">
      <p>Sistem Data Pengisian Solar</p>
      <p>PT United Tractors</p>
    </div>
    <div>&copy; 2024 PT United Tractors Tbk. All rights reserved.</div>
  </footer>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/ui-polish.js"></script>
  <script>
    lucide.createIcons();

    const passwordInput = document.getElementById('passwordInput');
    const togglePassword = document.getElementById('togglePassword');

    if (passwordInput && togglePassword) {
      togglePassword.addEventListener('click', function () {
        const willShow = passwordInput.type === 'password';
        passwordInput.type = willShow ? 'text' : 'password';
        togglePassword.setAttribute('aria-label', willShow ? 'Sembunyikan password' : 'Lihat password');
        togglePassword.setAttribute('aria-pressed', willShow ? 'true' : 'false');
        togglePassword.innerHTML = '<i data-lucide="' + (willShow ? 'eye-off' : 'eye') + '" size="18"></i>';
        lucide.createIcons();
      });
    }
  </script>
</body>
</html>
