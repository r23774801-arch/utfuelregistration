<?php
require_once __DIR__ . '/env.php';

$env = static function (string $key, $default = '') {
    $value = getenv($key);
    return $value === false ? $default : $value;
};

return [
    // Isi saat hosting agar link foto di email/spreadsheet/export selalu memakai domain asli.
    // Contoh: https://domainkamu.com/solar
    'app_base_url' => rtrim((string) $env('APP_BASE_URL', ''), '/'),

    // Isi setelah Google Apps Script Web App selesai di-deploy.
    // Contoh: https://script.google.com/macros/s/AKfycbxxxx/exec
    'google_sheet_webhook_url' => $env('GOOGLE_SHEET_WEBHOOK_URL', ''),

    // Token bebas untuk memastikan request berasal dari aplikasi ini.
    // Samakan dengan nilai SECRET_TOKEN di Apps Script.
    'google_sheet_secret' => $env('GOOGLE_SHEET_SECRET', ''),

    // Konfigurasi email SMTP. Untuk Gmail gunakan App Password, bukan password akun biasa.
    'smtp_enabled' => filter_var($env('SMTP_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
    'smtp_host' => $env('SMTP_HOST', 'smtp.gmail.com'),
    'smtp_port' => (int) $env('SMTP_PORT', '587'),
    'smtp_secure' => $env('SMTP_SECURE', 'tls'),
    'smtp_username' => $env('SMTP_USERNAME', ''),
    'smtp_password' => $env('SMTP_PASSWORD', ''),
    'smtp_from_email' => $env('SMTP_FROM_EMAIL', ''),
    'smtp_from_name' => $env('SMTP_FROM_NAME', 'Fuel Monitoring'),
    // Kosongkan agar email tujuan otomatis mengikuti email admin di menu Profil.
    'admin_email' => $env('ADMIN_EMAIL', ''),
];
