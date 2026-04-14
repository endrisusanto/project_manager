<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Fungsi untuk memeriksa peran admin
function is_admin() {
    return isset($_SESSION["role"]) && $_SESSION["role"] === 'admin';
}

// Fungsi baru untuk memeriksa izin khusus (Admin atau Endri)
function is_endri_or_admin() {
    $is_admin = is_admin();
    $is_endri = (strtolower($_SESSION['user_details']['email'] ?? '') === 'endri@samsung.com');
    return $is_admin || $is_endri;
}
?>