<?php
require_once __DIR__ . '/env.php';

// Konfigurasi Database
// Saat hosting, sebaiknya isi lewat environment variable:
// DB_HOST, DB_NAME, DB_USER, DB_PASS
$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'ut_fuel_monitoring';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: ''; // Lokal XAMPP biasanya kosong

try {
    // Membuat koneksi menggunakan PDO (PHP Data Objects)
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Menampilkan error jika terjadi masalah
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Hasil fetch otomatis berupa array asosiatif
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Mencegah SQL Injection
    ]);
    
} catch (PDOException $e) {
    // Tangani jika koneksi gagal
    error_log("Koneksi ke database gagal: " . $e->getMessage());
    http_response_code(500);
    die("Koneksi ke database gagal. Periksa konfigurasi server.");
}
?>
