<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$unitId = filter_var($_GET['unit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($unitId === false || $unitId === null) {
    json_response(['error' => 'Kendaraan tidak valid.'], 400);
}

try {
    $unitStmt = $pdo->prepare("SELECT unit_id FROM units WHERE unit_id = :unit_id AND status = 'active' LIMIT 1");
    $unitStmt->execute(['unit_id' => $unitId]);
    if (!$unitStmt->fetchColumn()) {
        json_response(['error' => 'Kendaraan tidak ditemukan.'], 404);
    }

    $stmt = $pdo->prepare(
        'SELECT control FROM fuel_logs
         WHERE unit_id = :unit_id
         ORDER BY fuel_date DESC, log_id DESC
         LIMIT 1'
    );
    $stmt->execute(['unit_id' => $unitId]);
    $control = $stmt->fetchColumn();
    json_response(['data' => ['previous_control' => $control === false ? 0 : (float) $control]]);
} catch (Throwable $e) {
    error_log('Control lookup error: ' . $e->getMessage());
    json_response(['error' => 'Control terakhir tidak dapat diakses.'], 500);
}
