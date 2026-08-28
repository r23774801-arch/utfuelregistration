<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$query = strtoupper(trim((string) ($_GET['q'] ?? '')));
if ($query === '' || strlen($query) > 30) {
    json_response(['data' => []]);
}

try {
    $stmt = $pdo->prepare(
        'SELECT nrp, full_name AS name
         FROM employees
         WHERE is_active = 1 AND nrp LIKE :query
         ORDER BY CASE WHEN nrp = :exact THEN 0 ELSE 1 END, nrp
         LIMIT 8'
    );
    $stmt->execute(['query' => $query . '%', 'exact' => $query]);
    json_response(['data' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('Employee NRP search error: ' . $e->getMessage());
    json_response(['error' => 'Data NRP tidak dapat diakses.'], 500);
}
