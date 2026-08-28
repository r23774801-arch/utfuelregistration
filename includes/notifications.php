<?php
declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    }
    return $config;
}

function public_upload_url(?string $filename): string
{
    if (!$filename) {
        return '';
    }

    $safeName = basename($filename);
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($path)) {
        return '';
    }

    $config = app_config();
    if (!empty($config['app_base_url'])) {
        return rtrim((string) $config['app_base_url'], '/') . '/uploads/' . rawurlencode($safeName);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

    return $scheme . '://' . $host . $basePath . '/uploads/' . rawurlencode($safeName);
}

function append_fuel_spreadsheet(array $data): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'exports';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'fuel_registration.csv';
    $isNew = !file_exists($file);
    $handle = fopen($file, 'ab');

    if ($handle === false) {
        throw new RuntimeException('File spreadsheet lokal tidak bisa dibuat.');
    }

    if ($isNew) {
        fputcsv($handle, [
            'Kode',
            'Tanggal Input',
            'Operator',
            'Type Unit',
            'Nomor Polisi',
            'No Lambung',
            'Area',
            'Meteran Besar Flow Meter',
            'Control',
            'Meteran Kecil Flow Meter',
            'Liter Pengisian',
            'HM/KM Kendaraan',
            'Liter Pengisian',
            'Dokumentasi Pengisian',
            'Foto Flow Meter',
        ]);
    }

    fputcsv($handle, [
        $data['code'],
        $data['fuel_date'],
        $data['operator_name'],
        $data['type_unit'],
        $data['nomor_polisi'],
        $data['no_lambung'],
        $data['area'],
        $data['ltr_besar'],
        $data['control'] ?? $data['ltr_kecil'],
        $data['ltr_kecil'],
        $data['total_liters'],
        $data['hm_awal'],
        $data['total_usage'],
        $data['foto_form_url'] ?? '',
        $data['foto_km_url'] ?? '',
    ]);

    fclose($handle);
}

function sync_google_sheet(array $data): bool
{
    $config = app_config();
    $url = trim((string) ($config['google_sheet_webhook_url'] ?? ''));

    if ($url === '') {
        return false;
    }

    $payload = $data;
    $payload['foto_form_url'] = $payload['foto_form_url'] ?? public_upload_url($payload['foto_form'] ?? null);
    $payload['foto_km_url'] = $payload['foto_km_url'] ?? public_upload_url($payload['foto_km'] ?? null);
    $payload['secret'] = (string) ($config['google_sheet_secret'] ?? '');

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('Google Sheet sync failed: ' . ($error ?: 'HTTP ' . $status));
        return false;
    }

    $decoded = json_decode((string) $response, true);
    return is_array($decoded) && ($decoded['ok'] ?? false) === true;
}

function notify_admin_email(PDO $pdo, array $data): bool
{
    $config = app_config();
    $adminEmail = trim((string) ($config['admin_email'] ?? ''));
    if ($adminEmail === '') {
        $adminEmail = (string) $pdo->query("SELECT email FROM users WHERE email IS NOT NULL AND email <> '' ORDER BY user_id ASC LIMIT 1")->fetchColumn();
    }
    if (!$adminEmail) {
        return false;
    }

    $subject = 'Fuel Registration Baru - ' . $data['code'];
    $body = build_fuel_email_html($data);

    $attachments = [];
    foreach (['foto_form' => 'Dokumentasi Pengisian', 'foto_km' => 'Foto Flow Meter'] as $key => $label) {
        if (!empty($data[$key])) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename((string) $data[$key]);
            if (is_file($path)) {
                $attachments[] = ['path' => $path, 'name' => $label . ' - ' . basename($path)];
            }
        }
    }

    if (!($config['smtp_enabled'] ?? false)) {
        return false;
    }

    return send_smtp_mail((string) $adminEmail, $subject, $body, $config, $attachments);
}

function build_fuel_email_html(array $data): string
{
    $row = static function (string $label, $value): string {
        return '<tr><td style="padding:10px 12px;color:#64748b;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:10px 12px;font-weight:700;color:#111827;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    };

    return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Segoe UI,Arial,sans-serif;color:#111827;">'
        . '<div style="max-width:640px;margin:0 auto;padding:24px;">'
        . '<div style="background:#111827;color:#fff;border-radius:14px 14px 0 0;padding:22px 24px;">'
        . '<div style="font-size:12px;color:#fbbf24;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Fuel Monitoring System</div>'
        . '<h1 style="margin:8px 0 0;font-size:24px;">Fuel Registration Baru</h1>'
        . '<p style="margin:8px 0 0;color:#cbd5e1;">Data pengisian fuel baru telah masuk ke sistem.</p>'
        . '</div>'
        . '<div style="background:#ffffff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:22px 24px;">'
        . '<div style="display:inline-block;background:#fbbf24;color:#000;border-radius:999px;padding:8px 14px;font-weight:900;margin-bottom:16px;">' . htmlspecialchars((string) $data['code'], ENT_QUOTES, 'UTF-8') . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
        . $row('Tanggal', $data['fuel_date'] ?? '-')
        . $row('Operator', $data['operator_name'] ?? '-')
        . $row('Type Unit', $data['type_unit'] ?? '-')
        . $row('Nomor Polisi', $data['nomor_polisi'] ?? '-')
        . $row('No Lambung', $data['no_lambung'] ?? '-')
        . $row('Area', $data['area'] ?? '-')
        . $row('Meteran Besar Flow Meter', ($data['ltr_besar'] ?? 0) . ' L')
        . $row('Control', ($data['control'] ?? $data['ltr_kecil'] ?? 0) . ' L')
        . $row('Meteran Kecil Flow Meter', ($data['ltr_kecil'] ?? 0) . ' L')
        . $row('Liter Pengisian', ($data['total_liters'] ?? 0) . ' L')
        . $row('HM/KM Kendaraan', $data['hm_awal'] ?? 0)
        . $row('Selisih / Liter Pengisian', ($data['total_usage'] ?? 0) . ' L')
        . '</table>'
        . '<p style="margin:18px 0 0;color:#64748b;font-size:13px;">Dokumentasi Pengisian dan Foto Flow Meter dilampirkan pada email ini.</p>'
        . '</div></div></body></html>';
}

function send_smtp_mail(string $to, string $subject, string $body, array $config, array $attachments = []): bool
{
    $host = (string) ($config['smtp_host'] ?? '');
    $port = (int) ($config['smtp_port'] ?? 587);
    $secure = strtolower((string) ($config['smtp_secure'] ?? 'tls'));
    $username = (string) ($config['smtp_username'] ?? '');
    $password = str_replace(' ', '', (string) ($config['smtp_password'] ?? ''));
    $fromEmail = (string) (($config['smtp_from_email'] ?? '') ?: $username);
    $fromName = (string) ($config['smtp_from_name'] ?? 'Fuel Monitoring');

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        error_log('SMTP config is incomplete.');
        return false;
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log("SMTP connection failed: {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    $write = static function (string $command) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        return $read();
    };

    $expect = static function (string $response, array $codes): bool {
        return in_array(substr($response, 0, 3), $codes, true);
    };

    if (!$expect($read(), ['220'])) {
        fclose($socket);
        return false;
    }

    if (!$expect($write('EHLO localhost'), ['250'])) {
        fclose($socket);
        return false;
    }

    if ($secure === 'tls') {
        if (!$expect($write('STARTTLS'), ['220'])) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP STARTTLS failed.');
            fclose($socket);
            return false;
        }
        if (!$expect($write('EHLO localhost'), ['250'])) {
            fclose($socket);
            return false;
        }
    }

    if (!$expect($write('AUTH LOGIN'), ['334'])) {
        fclose($socket);
        return false;
    }
    if (!$expect($write(base64_encode($username)), ['334'])) {
        fclose($socket);
        return false;
    }
    if (!$expect($write(base64_encode($password)), ['235'])) {
        fclose($socket);
        return false;
    }

    if (!$expect($write('MAIL FROM:<' . $fromEmail . '>'), ['250'])) {
        fclose($socket);
        return false;
    }
    if (!$expect($write('RCPT TO:<' . $to . '>'), ['250', '251'])) {
        fclose($socket);
        return false;
    }
    if (!$expect($write('DATA'), ['354'])) {
        fclose($socket);
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $boundary = '=_fuel_mixed_' . bin2hex(random_bytes(12));
    $altBoundary = '=_fuel_alt_' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . smtp_header_address($fromName, $fromEmail),
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];

    $parts = [];
    $parts[] = "--{$boundary}\r\n"
        . "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n"
        . "--{$altBoundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)) . "\r\n"
        . "--{$altBoundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($body) . "\r\n"
        . "--{$altBoundary}--\r\n";

    foreach ($attachments as $attachment) {
        $path = $attachment['path'] ?? '';
        if (!is_file($path)) {
            continue;
        }
        $name = preg_replace('/[^A-Za-z0-9_. -]/', '_', (string) ($attachment['name'] ?? basename($path)));
        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
        $content = chunk_split(base64_encode((string) file_get_contents($path)));
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: {$mime}; name=\"{$name}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
            . $content . "\r\n";
    }

    $parts[] = "--{$boundary}--\r\n";
    $message = implode("\r\n", $headers) . "\r\n\r\n" . implode('', $parts) . "\r\n.";

    if (!$expect($write($message), ['250'])) {
        fclose($socket);
        return false;
    }

    $write('QUIT');
    fclose($socket);
    return true;
}

function smtp_header_address(string $name, string $email): string
{
    return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
}
