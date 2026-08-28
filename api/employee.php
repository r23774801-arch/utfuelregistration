<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$nrp = strtoupper(trim((string) ($_GET['nrp'] ?? '')));
if ($nrp === '' || strlen($nrp) > 30) {
    json_response(['data' => null], 400);
}

try {
    $stmt = $pdo->prepare(
        'SELECT nrp, full_name AS name, department
         FROM employees
         WHERE nrp = :nrp AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['nrp' => $nrp]);
    $employee = $stmt->fetch();
    json_response(['data' => $employee ?: null], $employee ? 200 : 404);
} catch (Throwable $e) {
    error_log('Employee lookup error: ' . $e->getMessage());
    json_response(['error' => 'Data pegawai tidak dapat diakses.'], 500);
}
