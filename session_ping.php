<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (current_user_id() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$_SESSION['last_activity_at'] = time();

echo json_encode(['ok' => true]);
