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
        $password_plain = $_POST['password']; // Simpan password asli untuk auto-login
        $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);
        $role = $_POST['role'] ?? 'user';

        // Cek duplikasi email
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            redirect_with_message('register.php', 'error', 'Email sudah terdaftar. Silakan gunakan email lain.');
            $stmt->close();
            break;
        }
        $stmt->close();

        // Cek duplikasi username
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            redirect_with_message('register.php', 'error', 'Username sudah digunakan. Silakan pilih yang lain.');
            $stmt->close();
            break;
        }
        $stmt->close();

        // Jika tidak ada duplikasi, lanjutkan registrasi
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $password_hashed, $role);

        if ($stmt->execute()) {
            // MODIFIKASI: Auto-login setelah registrasi berhasil
            $user_id = $stmt->insert_id;
            session_regenerate_id();
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $user_id;
            $_SESSION["username"] = $username;
            $_SESSION["role"] = $role;
            $_SESSION["user_details"] = [
                'email' => $email,
                'profile_picture' => 'default.png' // default setelah register
            ];
            header("location: gba_tasks.php");
        } else {
            redirect_with_message('register.php', 'error', 'Gagal mendaftar. Terjadi kesalahan tidak terduga.');
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
                $_SESSION["user_details"] = [
                    'email' => $user['email'],
                    'profile_picture' => $user['profile_picture']
                ];
                
                // MODIFIKASI: Logika "Ingat Saya"
                if (!empty($_POST["remember"])) {
                    // Set cookie selama 30 hari
                    setcookie("username", $username, time() + (86400 * 30), "/");
                    setcookie("password", $password, time() + (86400 * 30), "/");
                } else {
                    // Hapus cookie jika tidak dicentang
                    setcookie("username", "", time() - 3600, "/");
                    setcookie("password", "", time() - 3600, "/");
                }

                header("location: gba_tasks.php");
            } else {
                redirect_with_message('login.php', 'error', 'Password yang Anda masukkan salah.');
            }
        } else {
            redirect_with_message('login.php', 'error', 'Username tidak ditemukan.');
        }
        $stmt->close();
        break;

    // ... (case lainnya tetap sama) ...
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
                $old_pic = $_SESSION['user_details']['profile_picture'];
                if ($old_pic != 'default.png' && file_exists($upload_dir . $old_pic)) {
                    unlink($upload_dir . $old_pic);
                }

                $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt->bind_param("si", $new_filename, $_SESSION['id']);
                $stmt->execute();
                $stmt->close();
                
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