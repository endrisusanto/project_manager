<?php
include 'config.php';
session_start();

$action = $_POST['action'] ?? '';

// --- FUNGSI HELPER ---
function redirect_with_message($page, $type, $message) {
    header("Location: $page?$type=" . urlencode($message));
    exit();
}

switch ($action) {
    case 'register':
        $username = $_POST['username'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'] ?? 'user'; // Default role is user

        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $password, $role);

        if ($stmt->execute()) {
            redirect_with_message('login.php', 'success', 'Registrasi berhasil! Silakan login.');
        } else {
            redirect_with_message('register.php', 'error', 'Gagal mendaftar. Username atau email mungkin sudah digunakan.');
        }
        $stmt->close();
        break;

    case 'login':
        $username = $_POST['username'];
        $password = $_POST['password'];

        $stmt = $conn->prepare("SELECT id, username, password, role, email, profile_picture FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                session_regenerate_id();
                $_SESSION["loggedin"] = true;
                $_SESSION["id"] = $user['id'];
                $_SESSION["username"] = $user['username'];
                $_SESSION["role"] = $user['role'];
                // Simpan detail user di session untuk akses mudah (misal: di navbar)
                $_SESSION["user_details"] = [
                    'email' => $user['email'],
                    'profile_picture' => $user['profile_picture']
                ];
                header("location: index.php");
            } else {
                redirect_with_message('login.php', 'error', 'Password yang Anda masukkan salah.');
            }
        } else {
            redirect_with_message('login.php', 'error', 'Username tidak ditemukan.');
        }
        $stmt->close();
        break;

    case 'change_password':
        if (!isset($_SESSION["loggedin"])) exit('Akses ditolak.');
        
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            redirect_with_message('profile.php', 'error', 'Password baru tidak cocok.');
        }

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (password_verify($current_password, $result['password'])) {
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_new_password, $_SESSION['id']);
            $stmt->execute();
            $stmt->close();
            redirect_with_message('profile.php', 'success', 'Password berhasil diubah.');
        } else {
            redirect_with_message('profile.php', 'error', 'Password saat ini salah.');
        }
        break;

    case 'update_profile_picture':
        if (!isset($_SESSION["loggedin"])) exit('Akses ditolak.');

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            $file_info = pathinfo($_FILES['profile_picture']['name']);
            $file_ext = strtolower($file_info['extension']);
            
            if (!in_array($file_ext, $allowed_types)) {
                redirect_with_message('profile.php', 'error', 'Format file tidak diizinkan. Gunakan JPG, PNG, atau GIF.');
            }

            if ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) { // 2MB
                redirect_with_message('profile.php', 'error', 'Ukuran file terlalu besar. Maksimal 2MB.');
            }

            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $new_filename = uniqid('user_' . $_SESSION['id'] . '_', true) . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                // Hapus foto lama jika bukan default.png
                $old_pic = $_SESSION['user_details']['profile_picture'];
                if ($old_pic != 'default.png' && file_exists($upload_dir . $old_pic)) {
                    unlink($upload_dir . $old_pic);
                }

                $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->bind_param("si", $new_filename, $_SESSION['id']);
                $stmt->execute();
                $stmt->close();
                
                // Update session
                $_SESSION['user_details']['profile_picture'] = $new_filename;
                
                redirect_with_message('profile.php', 'success', 'Foto profil berhasil diperbarui.');
            } else {
                redirect_with_message('profile.php', 'error', 'Gagal mengunggah file.');
            }
        } else {
            redirect_with_message('profile.php', 'error', 'Tidak ada file yang diunggah atau terjadi error.');
        }
        break;
}

$conn->close();
?>