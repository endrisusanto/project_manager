--
-- Skrip untuk inisialisasi database `project_manager_db`
--
-- Versi Server: 10.4.28-MariaDB
--

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

--
-- Database: `project_manager_db`
--
CREATE DATABASE IF NOT EXISTS `project_manager_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `project_manager_db`;

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `projects`
-- (Dengan kolom `due_date` dihapus, dan `software_released` serta `use_gba_testing` ditambahkan)
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
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
  `use_gba_testing` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `gba_tasks`
-- (Dengan kolom `project_id`, `qb_user`, dan `qb_eng` ditambahkan)
--

CREATE TABLE `gba_tasks` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `model_name` varchar(100) NOT NULL,
  `ap` varchar(100) DEFAULT NULL,
  `cp` varchar(100) DEFAULT NULL,
  `csc` varchar(100) DEFAULT NULL,
  `qb_user` varchar(50) DEFAULT NULL,
  `qb_eng` varchar(50) DEFAULT NULL,
  `pic_email` varchar(100) NOT NULL,
  `test_plan_type` varchar(50) NOT NULL,
  `progress_status` varchar(50) NOT NULL,
  `request_date` date DEFAULT NULL,
  `submission_date` date DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `sign_off_date` date DEFAULT NULL,
  `base_submission_id` varchar(100) DEFAULT NULL,
  `submission_id` varchar(100) DEFAULT NULL,
  `reviewer_email` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `test_items_checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`test_items_checklist`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indeks untuk tabel `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `gba_tasks`
--
ALTER TABLE `gba_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT untuk tabel `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT untuk tabel `gba_tasks`
--
ALTER TABLE `gba_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;
COMMIT;