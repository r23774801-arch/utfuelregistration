<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/koneksi.php';
require_once __DIR__ . '/includes/auth.php';

$message = '';
$messageType = '';

$stmt = $pdo->query("SELECT user_id, nip, full_name, email, password_hash FROM users ORDER BY user_id ASC LIMIT 1");
$admin = $stmt->fetch();
$needsSetup = !$admin || !password_get_info((string) ($admin['password_hash'] ?? ''))['algo'];

if (!$needsSetup) {
    header('Location: login.php');
    exit;
}

$hostName = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = preg_match('/^(localhost|127\.0\.0\.1|::1)(:\d+)?$/', $hostName) === 1;
$allowHostedSetup = filter_var(getenv('ALLOW_ADMIN_SETUP') ?: 'false', FILTER_VALIDATE_BOOLEAN);

if (!$isLocalHost && !$allowHostedSetup) {
    http_response_code(403);
    exit('Setup admin hanya diizinkan di localhost. Untuk hosting, import database yang sudah punya admin atau aktifkan ALLOW_ADMIN_SETUP=true sementara.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nip = trim($_POST['nip'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Sesi form tidak valid. Muat ulang halaman lalu coba lagi.');
        }
        if ($nip === '' || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('NIP, nama lengkap, dan email valid wajib diisi.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password minimal 8 karakter.');
        }
        if ($password !== $confirmPassword) {
            throw new RuntimeException('Konfirmasi password tidak sama.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        if ($admin) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET nip = :nip, full_name = :full_name, email = :email, password_hash = :password_hash, role = 'Admin'
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                'nip' => $nip,
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => $hash,
                'user_id' => $admin['user_id'],
            ]);
            $userId = (int) $admin['user_id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO users (nip, full_name, email, password_hash, role)
                VALUES (:nip, :full_name, :email, :password_hash, 'Admin')
            ");
            $stmt->execute([
                'nip' => $nip,
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => $hash,
            ]);
            $userId = (int) $pdo->lastInsertId();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_role'] = 'Admin';
        $_SESSION['last_activity_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        error_log('Admin setup error: ' . $e->getMessage());
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Setup admin gagal.';
        $messageType = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Setup Admin - United Tractors</title>
  <link rel="icon" type="image/png" href="img/logo.png">
  <style>
    :root { --bg-dark:#0a0a0a; --card-bg:#181818; --input-bg:#101010; --border-color:#303030; --accent-yellow:#ffb800; --text-main:#fff; --text-muted:#8a99ad; --red-alert:#ef4444; }
    * { box-sizing:border-box; margin:0; padding:0; font-family:'Segoe UI', sans-serif; }
    body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg-dark); color:var(--text-main); padding:20px; }
    .card { width:100%; max-width:430px; background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:28px; }
    h1 { font-size:22px; margin-bottom:8px; }
    p { color:var(--text-muted); font-size:13px; line-height:1.5; margin-bottom:20px; }
    .form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
    label { font-size:12px; color:var(--text-muted); font-weight:700; }
    input { background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); padding:11px 12px; border-radius:6px; outline:none; }
    input:focus { border-color:var(--accent-yellow); }
    button { width:100%; background:var(--accent-yellow); color:#000; border:0; border-radius:6px; padding:12px; font-weight:800; cursor:pointer; margin-top:8px; }
    .alert { background:rgba(239,68,68,.15); color:var(--red-alert); border:1px solid var(--red-alert); padding:10px 12px; border-radius:6px; margin-bottom:16px; font-size:12px; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Setup Admin</h1>
    <p>Buat password admin terlebih dahulu karena data user lama belum punya hash password yang valid.</p>
    <?php if ($message !== ''): ?>
      <div class="alert"><?= e($message) ?></div>
    <?php endif; ?>
    <form action="setup_admin.php" method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="form-group">
        <label>NIP</label>
        <input type="text" name="nip" value="<?= e($admin['nip'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Nama Lengkap</label>
        <input type="text" name="full_name" value="<?= e($admin['full_name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="<?= e($admin['email'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Password Baru</label>
        <input type="password" name="password" minlength="8" required>
      </div>
      <div class="form-group">
        <label>Konfirmasi Password</label>
        <input type="password" name="confirm_password" minlength="8" required>
      </div>
      <button type="submit">Simpan Admin</button>
    </form>
  </main>
</body>
</html>
