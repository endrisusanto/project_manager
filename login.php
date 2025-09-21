<?php
session_start();
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white flex items-center justify-center h-screen">
    <div class="w-full max-w-md p-8 space-y-6 bg-gray-800 rounded-lg shadow-lg">
        <h2 class="text-2xl font-bold text-center">Login</h2>
        <form action="auth_handler.php" method="post">
            <input type="hidden" name="action" value="login">
            <div class="space-y-4">
                <div>
                    <label for="username" class="block mb-2 text-sm font-medium">Username</label>
                    <input type="text" name="username" id="username" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg" required>
                </div>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium">Password</label>
                    <input type="password" name="password" id="password" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg" required>
                </div>
            </div>
            <button type="submit" class="w-full px-5 py-2.5 mt-6 text-sm font-medium text-center text-white bg-blue-600 rounded-lg hover:bg-blue-700">Login</button>
            <p class="text-sm text-center mt-4">Belum punya akun? <a href="register.php" class="font-medium text-blue-500 hover:underline">Daftar di sini</a></p>
        </form>
    </div>
</body>
</html>