--
-- Skrip lengkap dan ter-audit untuk inisialisasi database `project_manager_db`
--
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

CREATE DATABASE IF NOT EXISTS `project_manager_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `project_manager_db`;

-- --------------------------------------------------------
-- Struktur dari tabel `users`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `profile_picture` varchar(255) DEFAULT 'default.png',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Struktur dari tabel `projects`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_name` varchar(255) NOT NULL,
  `product_model` varchar(100) NOT NULL,
  `project_type` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ap` varchar(100) DEFAULT NULL,
  `cp` varchar(100) DEFAULT NULL,
  `csc` varchar(100) DEFAULT NULL,
  `qb_user` varchar(50) DEFAULT NULL,
  `qb_userdebug` varchar(50) DEFAULT NULL,
  `software_released` tinyint(1) NOT NULL DEFAULT 0,
  `use_gba_testing` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Struktur dari tabel `gba_tasks`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gba_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) DEFAULT NULL,
  `project_name` varchar(255) DEFAULT NULL,
  `model_name` varchar(100) NOT NULL,
  `ap` varchar(100) DEFAULT NULL,
  `cp` varchar(100) DEFAULT NULL,
  `csc` varchar(100) DEFAULT NULL,
  `qb_user` varchar(50) DEFAULT NULL,
  `qb_userdebug` varchar(50) DEFAULT NULL,
  `qb_eng` varchar(50) DEFAULT NULL,
  `pic_email` varchar(100) NOT NULL,
  `test_plan_type` varchar(50) NOT NULL,
  `progress_status` varchar(50) NOT NULL,
  `request_date` date DEFAULT NULL,
  `submission_date` date DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `sign_off_date` date DEFAULT NULL,
  `approved_date` date DEFAULT NULL,
  `base_submission_id` varchar(100) DEFAULT NULL,
  `submission_id` varchar(100) DEFAULT NULL,
  `reviewer_email` varchar(100) DEFAULT NULL,
  `is_urgent` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `test_items_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`test_items_checklist`)),
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by_email` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Struktur dari tabel `activity_log`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `action_time` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Struktur dari tabel `activity_logs`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) DEFAULT NULL,
  `pic_name` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Struktur dari tabel `user_notes`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_email` varchar(255) NOT NULL,
  `note_date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `priority` varchar(50) DEFAULT 'Normal',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Struktur dari tabel `new_tasks`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `new_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `model_name` varchar(100) NOT NULL,
  `ap` varchar(150) DEFAULT NULL,
  `cp` varchar(150) DEFAULT NULL,
  `csc` varchar(150) DEFAULT NULL,
  `request_type` varchar(50) DEFAULT NULL,
  `qb_user` text DEFAULT NULL,
  `qb_userdebug` text DEFAULT NULL,
  `is_manual` tinyint(1) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;