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
    <title>Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white flex items-center justify-center h-screen">
    <div class="w-full max-w-md p-8 space-y-6 bg-gray-800 rounded-lg shadow-lg">
        <h2 class="text-2xl font-bold text-center">Register</h2>
        <form action="auth_handler.php" method="post">
            <input type="hidden" name="action" value="register">
            <div class="space-y-4">
                <div>
                    <label for="username" class="block mb-2 text-sm font-medium">Username</label>
                    <input type="text" name="username" id="username" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg" required>
                </div>
                <div>
                    <label for="email" class="block mb-2 text-sm font-medium">Email</label>
                    <input type="email" name="email" id="email" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg" required>
                </div>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium">Password</label>
                    <input type="password" name="password" id="password" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg" required>
                </div>
                 <div>
                    <label for="role" class="block mb-2 text-sm font-medium">Role</label>
                    <select name="role" id="role" class="w-full p-2.5 bg-gray-700 border border-gray-600 rounded-lg">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="w-full px-5 py-2.5 mt-6 text-sm font-medium text-center text-white bg-blue-600 rounded-lg hover:bg-blue-700">Register</button>
            <p class="text-sm text-center mt-4">Sudah punya akun? <a href="login.php" class="font-medium text-blue-500 hover:underline">Login di sini</a></p>
        </form>
    </div>
</body>
</html>