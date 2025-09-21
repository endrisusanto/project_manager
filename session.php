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
?>