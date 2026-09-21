<?php
// ponytail: read DB connection from environment variables with XAMPP fallback
define('DB_SERVER', getenv('DB_SERVER') ?: 'localhost');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'project_manager_db');

// Membuat koneksi ke database menggunakan MySQLi
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Memeriksa koneksi
if($conn->connect_error){
    // Jika koneksi gagal, hentikan skrip dan tampilkan pesan error
    die("ERROR: Tidak dapat terhubung. " . $conn->connect_error);
}

// Mengatur charset & zona waktu default
$conn->set_charset("utf8mb4");
date_default_timezone_set('Asia/Jakarta');
?>
