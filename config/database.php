<?php
// Koneksi database terpusat, di-require di semua halaman. Pakai PDO
// (bukan mysqli) untuk prepared statement -- mencegah SQL injection.

$host    = 'localhost';
$db_name = 'seblak_bunga';
$db_user = 'root';
$db_pass = '';          // default XAMPP kosong. Ganti kalau MySQL kamu dikasih password.
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // biar error kelihatan jelas pas development
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // hasil query berupa array asosiatif, bukan object
    PDO::ATTR_EMULATE_PREPARES   => false,                   // prepared statement beneran dari MySQL, lebih aman
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log('Koneksi database Seblak Bunga gagal: ' . $e->getMessage());
    http_response_code(500);
    die('Terjadi kesalahan pada sistem. Silakan coba lagi.');
}

// ------------------------------------------------------------
// Helper: format angka jadi format singkat "3rb", "12.5rb", dst
// Dipakai di banyak halaman buat nampilin harga, jadi taruh di
// sini biar bisa dipanggil dari mana aja yang sudah require file ini.
// ------------------------------------------------------------
function formatRb($angka) {
    if ($angka % 1000 === 0) {
        return ($angka / 1000) . 'rb';
    }
    return number_format($angka / 1000, 1) . 'rb';
}