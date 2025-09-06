-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 06, 2025 at 03:00 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `project_manager_db`
--
CREATE DATABASE IF NOT EXISTS `project_manager_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `project_manager_db`;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
-- Catatan: Password untuk semua pengguna di bawah ini adalah 'password'
--

INSERT INTO `users` (`id`, `username`, `email`, `password`) VALUES
(1, 'Admin Project', 'admin@project.com', '$2y$10$i5f7aT.gQ9e8rT.uK2i/z.e5g8wH.f3bC.d6e7g8h9i0j'),
(2, 'PIC GBA 1', 'pic1@example.com', '$2y$10$i5f7aT.gQ9e8rT.uK2i/z.e5g8wH.f3bC.d6e7g8h9i0j'),
(3, 'PIC GBA 2', 'pic2@example.com', '$2y$10$i5f7aT.gQ9e8rT.uK2i/z.e5g8wH.f3bC.d6e7g8h9i0j'),
(4, 'Manager', 'manager@project.com', '$2y$10$i5f7aT.gQ9e8rT.uK2i/z.e5g8wH.f3bC.d6e7g8h9i0j');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `product_model` varchar(100) NOT NULL,
  `project_type` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL,
  `due_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ap` varchar(100) DEFAULT NULL,
  `cp` varchar(100) DEFAULT NULL,
  `csc` varchar(100) DEFAULT NULL,
  `qb_user` varchar(50) DEFAULT NULL,
  `qb_userdebug` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `project_name`, `product_model`, `project_type`, `status`, `due_date`, `description`, `ap`, `cp`, `csc`, `qb_user`, `qb_userdebug`) VALUES
(1, 'Galaxy S26 ID Launch', 'SM-S948B', 'New Launch', 'Planning', '2026-02-15', 'Initial software build for the upcoming Galaxy S26 series.', 'S948BXXU1AXX1', 'S948BXXU1AXX1', 'S948BOLM1AXX1', '1023456', '1023457'),
(2, 'Galaxy A56 Sept Patch', 'SM-A566E', 'Security Release', 'Released', '2025-09-20', 'Monthly security maintenance release for Galaxy A56.', 'A566EXXU3BWH3', 'A566EXXU3BWH2', 'A566EOLM3BWH3', '1011234', '1011235'),
(3, 'Galaxy Z Fold7 Maintenance', 'SM-F966B', 'Maintenance Release', 'In Development', '2025-10-05', 'Quarterly maintenance update for the Z Fold7.', 'F966BXXU1BWI1', 'F966BXXU1BWI1', 'F966BOLM1BWI1', '1033456', '1033457'),
(4, 'Galaxy M36 OneUI 7.1', 'SM-M365F', 'Maintenance Release', 'GBA Testing', '2025-09-18', 'OneUI 7.1 feature drop and stability improvements.', 'M365FDDU2BWK1', 'M365FDDU2BWK1', 'M365FOLM2BWK1', '1045678', '1045679');

-- --------------------------------------------------------

--
-- Table structure for table `gba_tasks`
--

CREATE TABLE `gba_tasks` (
  `id` int(11) NOT NULL,
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
-- Dumping data for table `gba_tasks`
--

INSERT INTO `gba_tasks` (`id`, `model_name`, `ap`, `cp`, `csc`, `qb_user`, `qb_eng`, `pic_email`, `test_plan_type`, `progress_status`, `request_date`, `submission_date`, `deadline`, `sign_off_date`, `base_submission_id`, `submission_id`, `reviewer_email`, `notes`, `test_items_checklist`) VALUES
(1, 'SM-S948B', 'S948BXXU1AXX1', 'S948BXXU1AXX1', 'S948BOLM1AXX1', '1023456', '1023457', 'pic1@example.com', 'Regular Variant', 'Test Ongoing', '2025-09-01', NULL, '2025-09-10', NULL, 'PREV123', 'SUB456', 'reviewer@example.com', '<p>Initial test round for the S26 series.</p>', '{\"CTS_SKU\":true,\"GTS-variant\":true,\"ATM\":false,\"CTS-Verifier\":false}'),
(2, 'SM-A566E', 'A566EXXU3BWH3', 'A566EXXU3BWH2', 'A566EOLM3BWH3', '1011234', '1011235', 'pic2@example.com', 'SMR', 'Approved', '2025-08-20', '2025-08-24', '2025-08-28', '2025-08-26', 'PREV456', 'SUB789', 'reviewer@example.com', '<p>Security MR testing passed all checks.</p>', '{\"CTS\":true,\"GTS\":true,\"STS\":true,\"SCAT\":true}');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gba_tasks`
--
ALTER TABLE `gba_tasks`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `gba_tasks`
--
ALTER TABLE `gba_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

