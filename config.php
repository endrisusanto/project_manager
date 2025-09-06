<?php
// Pengaturan untuk koneksi database
define('DB_SERVER', 'localhost'); // Ganti dengan server database Anda
define('DB_USERNAME', 'root');    // Ganti dengan username database Anda
define('DB_PASSWORD', '');        // Ganti dengan password database Anda
define('DB_NAME', 'project_manager_db'); // Ganti dengan nama database Anda

// Membuat koneksi ke database menggunakan MySQLi
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Memeriksa koneksi
if($conn->connect_error){
    // Jika koneksi gagal, hentikan skrip dan tampilkan pesan error
    die("ERROR: Tidak dapat terhubung. " . $conn->connect_error);
}

// Mengatur zona waktu default
date_default_timezone_set('Asia/Jakarta');
?>
