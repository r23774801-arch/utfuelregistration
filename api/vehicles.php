<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$query = strtoupper(trim((string) ($_GET['q'] ?? '')));
if (strlen($query) > 50) {
    json_response(['data' => []], 400);
}

try {
    $stmt = $pdo->prepare(
        "SELECT unit_id AS id, no_lambung AS lambung, type_unit AS type,
                nomor_polisi AS polisi, area_location AS area
         FROM units
         WHERE status = 'active' AND no_lambung LIKE :query
         ORDER BY CASE WHEN no_lambung = :exact THEN 0 ELSE 1 END, no_lambung
         LIMIT 8"
    );
    $stmt->execute(['query' => '%' . $query . '%', 'exact' => $query]);
    json_response(['data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('Vehicle lookup error: ' . $e->getMessage());
    json_response(['error' => 'Data kendaraan tidak dapat diakses.'], 500);
}
