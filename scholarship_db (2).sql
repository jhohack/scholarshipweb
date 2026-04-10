-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 02, 2026 at 10:23 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `scholarship_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `content`, `is_active`, `created_at`) VALUES
(1, 'New Engineering Scholarships Added!', 'We have just added 5 new scholarship opportunities for students pursuing a degree in engineering and technology. Check them out now!', 1, '2025-11-20 04:15:21'),
(2, 'For SA scholarship students', 'Hello Everyone!', 1, '2025-12-31 22:00:19');

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `scholarship_name` varchar(255) DEFAULT NULL,
  `application_requirements` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `application_type` enum('new','renewal') DEFAULT 'new',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `applicant_type` enum('New','Renewal') DEFAULT 'New',
  `year_program` varchar(255) DEFAULT NULL,
  `units_enrolled` int(11) DEFAULT NULL,
  `gwa` decimal(5,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applications`
--

INSERT INTO `applications` (`id`, `student_id`, `scholarship_id`, `scholarship_name`, `application_requirements`, `status`, `application_type`, `submitted_at`, `updated_at`, `applicant_type`, `year_program`, `units_enrolled`, `gwa`, `remarks`) VALUES
(31, 8, 11, NULL, NULL, 'Dropped', 'new', '2026-01-02 06:47:45', '2026-01-02 06:55:06', 'New', '1st Year - BSIT', 1, 1.00, 'arwadawwdwadwaawdadadawd'),
(32, 8, 7, NULL, NULL, 'Dropped', 'new', '2026-01-02 06:56:09', '2026-01-02 07:02:27', 'New', '2nd Year - BSED-MATH', 1, 1.00, 'fklandfojknamehodfoiandfconaef'),
(33, 8, 10, NULL, NULL, 'Dropped', 'new', '2026-01-02 07:06:28', '2026-01-02 07:47:19', 'New', '3rd Year - AB-THEO', 1, 1.00, 'awfaswefgsrgdzhtfdhdzghsrgfsr'),
(34, 2, 11, NULL, NULL, 'Rejected', 'new', '2026-01-02 07:25:11', '2026-01-02 08:10:49', 'New', '2nd Year - BEED', 1, 1.00, '\nSystem: Auto-rejected due to approval in another scholarship.'),
(35, 2, 10, NULL, NULL, 'Approved', 'new', '2026-01-02 07:25:51', '2026-01-02 08:10:49', 'New', '1st Year - BSED-MATH', 1, 1.00, ''),
(36, 2, 7, NULL, NULL, 'Rejected', 'new', '2026-01-02 07:26:24', '2026-01-02 08:10:49', 'New', '2nd Year - AB-THEO', 1, 1.00, '\nSystem: Auto-rejected due to approval in another scholarship.'),
(37, 8, 7, NULL, NULL, 'Rejected', 'new', '2026-01-02 07:48:16', '2026-01-02 08:06:03', 'New', '1st Year - BSIT', 1, 1.00, ''),
(38, 8, 10, NULL, NULL, 'Dropped', 'new', '2026-01-02 07:49:08', '2026-01-02 15:17:25', 'New', '2nd Year - AB-THEO', 1, 1.00, 'edfsfgesegfsregrsdtgwsrgsdg'),
(39, 3, 10, NULL, NULL, 'Approved', 'new', '2026-01-02 08:30:31', '2026-01-02 11:55:22', 'New', '1st Year - BSIT', 1, 1.00, NULL),
(40, 8, 11, NULL, NULL, 'Dropped', 'new', '2026-01-02 16:50:03', '2026-01-02 17:04:35', 'New', '2nd Year - BSED-MATH', 1, 1.00, 'aefeafafafafdsfsertgrgsgsrgfsgfr'),
(41, 8, 7, NULL, NULL, 'Approved', 'new', '2026-01-02 17:05:22', '2026-01-02 17:05:46', 'New', '1st Year - AB-THEO', 1, 1.00, ''),
(42, 8, 7, NULL, NULL, 'Approved', 'new', '2026-01-02 17:53:43', '2026-01-02 17:54:13', 'Renewal', '2nd Year - AB-THEO', 3, 3.00, ''),
(43, 8, 7, NULL, NULL, 'Approved', 'new', '2026-01-02 18:04:55', '2026-01-02 18:05:23', 'Renewal', '2nd Year - AB-THEO', 34, 234.00, ''),
(44, 8, 7, NULL, NULL, 'Approved', 'new', '2026-01-02 18:12:16', '2026-01-02 18:12:31', 'Renewal', '3rd Year - BSED- ENGLISH', 435, 345.00, ''),
(45, 8, 7, NULL, NULL, 'Approved', 'new', '2026-01-02 18:19:21', '2026-01-02 18:19:30', 'Renewal', '1st Year - AB-THEO', 34, 34.00, ''),
(46, 8, 7, NULL, NULL, 'Approved', 'new', '2026-01-02 19:09:25', '2026-01-02 19:09:37', 'Renewal', '1st Year - AB-THEO', 132, 123.00, ''),
(60, 9, 10, NULL, NULL, 'Approved', 'new', '2026-01-02 21:18:08', '2026-01-02 21:23:15', 'New', '2nd Year - BSIT', 1, 1.00, '');

-- --------------------------------------------------------

--
-- Table structure for table `application_exams`
--

CREATE TABLE `application_exams` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `score_part_a` int(11) DEFAULT 0,
  `answers_part_b` text DEFAULT NULL,
  `score_part_b` int(11) DEFAULT 0,
  `grades_part_b` text DEFAULT NULL,
  `total_score` int(11) DEFAULT 0,
  `is_graded` tinyint(1) DEFAULT 0,
  `admin_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_responses`
--

CREATE TABLE `application_responses` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `form_field_id` int(11) NOT NULL,
  `response_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_responses`
--

INSERT INTO `application_responses` (`id`, `application_id`, `form_field_id`, `response_value`) VALUES
(1, 60, 2, '1'),
(2, 60, 3, '0213'),
(3, 60, 4, '1'),
(4, 60, 5, '1'),
(5, 60, 6, '1'),
(6, 60, 7, '1'),
(7, 60, 8, '1'),
(8, 60, 9, '2'),
(9, 60, 10, '1'),
(10, 60, 11, '1'),
(11, 60, 12, '2'),
(12, 60, 13, '1'),
(13, 60, 14, 'No'),
(14, 60, 15, 'No'),
(15, 60, 16, '1'),
(16, 60, 17, '1'),
(17, 60, 18, '1'),
(18, 60, 19, '1'),
(19, 60, 20, '23q3'),
(20, 60, 21, '1 - 1'),
(21, 60, 22, '1 - 1');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `application_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `user_id`, `application_id`, `file_name`, `file_path`, `uploaded_at`) VALUES
(106, 8, 31, '8_69576a1158aec2.80934967_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_69576a1158aec2.80934967_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 06:47:45'),
(107, 8, 31, '8_69576a11592f75.88278076_Finnish-FLOW-FAQ.pdf', 'uploads/8_69576a11592f75.88278076_Finnish-FLOW-FAQ.pdf', '2026-01-02 06:47:45'),
(108, 8, 31, '8_69576a115cf4b9.68496160_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_69576a115cf4b9.68496160_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 06:47:45'),
(109, 8, 31, '8_69576a115d8ae4.32642713_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_69576a115d8ae4.32642713_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 06:47:45'),
(110, 8, 31, '8_69576a115e06d5.44545055_Finnish-FLOW-NOTES.pdf', 'uploads/8_69576a115e06d5.44545055_Finnish-FLOW-NOTES.pdf', '2026-01-02 06:47:45'),
(111, 8, 31, '8_69576a115e89a7.97839438_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_69576a115e89a7.97839438_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 06:47:45'),
(112, 8, 32, '8_69576c097db249.00243117_Finnish-FLOW-NOTES.pdf', 'uploads/8_69576c097db249.00243117_Finnish-FLOW-NOTES.pdf', '2026-01-02 06:56:09'),
(113, 8, 32, '8_69576c097ef3e1.89840023_Finnish-FLOW-FAQ.pdf', 'uploads/8_69576c097ef3e1.89840023_Finnish-FLOW-FAQ.pdf', '2026-01-02 06:56:09'),
(114, 8, 32, '8_69576c097f6143.26286842_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_69576c097f6143.26286842_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 06:56:09'),
(115, 8, 32, '8_69576c097fc4e0.51275102_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_69576c097fc4e0.51275102_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 06:56:09'),
(116, 8, 32, '8_69576c098065a5.46025671_Finnish-FLOW-NOTES.pdf', 'uploads/8_69576c098065a5.46025671_Finnish-FLOW-NOTES.pdf', '2026-01-02 06:56:09'),
(117, 8, 32, '8_69576c09814705.69078650_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_69576c09814705.69078650_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 06:56:09'),
(118, 8, 33, '8_69576e7454bde5.13925285_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_69576e7454bde5.13925285_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:06:28'),
(119, 8, 33, '8_69576e7455b724.32013807_Finnish-FLOW-FAQ.pdf', 'uploads/8_69576e7455b724.32013807_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:06:28'),
(120, 8, 33, '8_69576e74567692.39696168_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_69576e74567692.39696168_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:06:28'),
(121, 8, 33, '8_69576e7456d1e6.76840220_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_69576e7456d1e6.76840220_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:06:28'),
(122, 8, 33, '8_69576e74578c97.73540116_Finnish-FLOW-NOTES.pdf', 'uploads/8_69576e74578c97.73540116_Finnish-FLOW-NOTES.pdf', '2026-01-02 07:06:28'),
(123, 8, 33, '8_69576e7457e3a5.84959634_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_69576e7457e3a5.84959634_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 07:06:28'),
(124, 2, 34, '2_695772d7b563d5.88982847_Finnish-FLOW-FAQ.pdf', 'uploads/2_695772d7b563d5.88982847_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:25:11'),
(125, 2, 34, '2_695772d7b67100.30550750_Finnish-FLOW-FAQ.pdf', 'uploads/2_695772d7b67100.30550750_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:25:11'),
(126, 2, 34, '2_695772d7b6d7c7.51178339_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/2_695772d7b6d7c7.51178339_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:25:11'),
(127, 2, 34, '2_695772d7b725f8.25960807_Finnish-FLOW-Messenger-followup.pdf', 'uploads/2_695772d7b725f8.25960807_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:25:11'),
(128, 2, 34, '2_695772d7b77917.45726254_Finnish-FLOW-NOTES.pdf', 'uploads/2_695772d7b77917.45726254_Finnish-FLOW-NOTES.pdf', '2026-01-02 07:25:11'),
(129, 2, 34, '2_695772d7b7d164.68805575_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/2_695772d7b7d164.68805575_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 07:25:11'),
(130, 2, 35, '2_695772ff28fcf5.84222295_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/2_695772ff28fcf5.84222295_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:25:51'),
(131, 2, 35, '2_695772ff294574.48704028_Finnish-FLOW-FAQ.pdf', 'uploads/2_695772ff294574.48704028_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:25:51'),
(132, 2, 35, '2_695772ff29afd4.97197815_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/2_695772ff29afd4.97197815_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:25:51'),
(133, 2, 35, '2_695772ff29f3e0.58513166_Finnish-FLOW-Messenger-followup.pdf', 'uploads/2_695772ff29f3e0.58513166_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:25:51'),
(134, 2, 35, '2_695772ff2b1832.19670225_Finnish-FLOW-NOTES.pdf', 'uploads/2_695772ff2b1832.19670225_Finnish-FLOW-NOTES.pdf', '2026-01-02 07:25:51'),
(135, 2, 35, '2_695772ff2ba266.69308125_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/2_695772ff2ba266.69308125_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 07:25:51'),
(136, 2, 36, '2_6957732058a772.15094171_Finnish-FLOW-Messenger-followup.pdf', 'uploads/2_6957732058a772.15094171_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:26:24'),
(137, 2, 36, '2_69577320596fa2.60036782_Finnish-FLOW-FAQ.pdf', 'uploads/2_69577320596fa2.60036782_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:26:24'),
(138, 2, 36, '2_6957732059c3b0.24220221_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/2_6957732059c3b0.24220221_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:26:24'),
(139, 2, 36, '2_695773205a08e5.01323733_Finnish-FLOW-Messenger-followup.pdf', 'uploads/2_695773205a08e5.01323733_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:26:24'),
(140, 2, 36, '2_695773205a5138.09912638_Finnish-FLOW-NOTES.pdf', 'uploads/2_695773205a5138.09912638_Finnish-FLOW-NOTES.pdf', '2026-01-02 07:26:24'),
(141, 2, 36, '2_695773205a96a3.04855340_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/2_695773205a96a3.04855340_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 07:26:24'),
(142, 8, 37, '8_69577840b09b36.04093989_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_69577840b09b36.04093989_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:48:16'),
(143, 8, 37, '8_69577840b1efb6.71207211_Finnish-FLOW-FAQ.pdf', 'uploads/8_69577840b1efb6.71207211_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:48:16'),
(144, 8, 37, '8_69577840b244b4.44641964_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_69577840b244b4.44641964_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:48:16'),
(145, 8, 37, '8_69577840b29e97.75647583_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_69577840b29e97.75647583_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:48:16'),
(146, 8, 37, '8_69577840b2e0e1.52783386_Finnish-FLOW-NOTES.pdf', 'uploads/8_69577840b2e0e1.52783386_Finnish-FLOW-NOTES.pdf', '2026-01-02 07:48:16'),
(147, 8, 37, '8_69577840b31f94.00432732_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_69577840b31f94.00432732_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 07:48:16'),
(148, 8, 38, '8_695778746bd662.58502349_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_695778746bd662.58502349_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:49:08'),
(149, 8, 38, '8_695778746c7a65.15270291_Finnish-FLOW-FAQ.pdf', 'uploads/8_695778746c7a65.15270291_Finnish-FLOW-FAQ.pdf', '2026-01-02 07:49:08'),
(150, 8, 38, '8_695778746ccab0.67873132_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_695778746ccab0.67873132_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 07:49:08'),
(151, 8, 38, '8_695778746d1c68.97900433_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_695778746d1c68.97900433_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 07:49:08'),
(152, 8, 38, '8_695778746d97f7.21795747_Finnish-FLOW-NOTES.pdf', 'uploads/8_695778746d97f7.21795747_Finnish-FLOW-NOTES.pdf', '2026-01-02 07:49:08'),
(153, 8, 38, '8_695778746e7374.32105127_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_695778746e7374.32105127_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 07:49:08'),
(154, 3, 39, '3_6957822736e605.22096965_Finnish-FLOW-Messenger-followup.pdf', 'uploads/3_6957822736e605.22096965_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 08:30:31'),
(155, 3, 39, '3_6957822737f4b0.06878288_Finnish-FLOW-FAQ.pdf', 'uploads/3_6957822737f4b0.06878288_Finnish-FLOW-FAQ.pdf', '2026-01-02 08:30:31'),
(156, 3, 39, '3_69578227389b21.38415978_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/3_69578227389b21.38415978_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 08:30:31'),
(157, 3, 39, '3_69578227392e09.57253251_Finnish-FLOW-Messenger-followup.pdf', 'uploads/3_69578227392e09.57253251_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 08:30:31'),
(158, 3, 39, '3_695782273972d2.68877787_Finnish-FLOW-NOTES.pdf', 'uploads/3_695782273972d2.68877787_Finnish-FLOW-NOTES.pdf', '2026-01-02 08:30:31'),
(159, 3, 39, '3_6957822739a699.02086929_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/3_6957822739a699.02086929_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 08:30:31'),
(160, 8, 40, '8_6957f73b3463c1.82290288_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_6957f73b3463c1.82290288_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 16:50:03'),
(161, 8, 40, '8_6957f73b364fa7.35203146_Finnish-FLOW-FAQ.pdf', 'uploads/8_6957f73b364fa7.35203146_Finnish-FLOW-FAQ.pdf', '2026-01-02 16:50:03'),
(162, 8, 40, '8_6957f73b36da54.77042364_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_6957f73b36da54.77042364_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 16:50:03'),
(163, 8, 40, '8_6957f73b374592.63426753_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_6957f73b374592.63426753_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 16:50:03'),
(164, 8, 40, '8_6957f73b37ac53.29110132_Finnish-FLOW-NOTES.pdf', 'uploads/8_6957f73b37ac53.29110132_Finnish-FLOW-NOTES.pdf', '2026-01-02 16:50:03'),
(165, 8, 40, '8_6957f73b37fba9.62243363_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_6957f73b37fba9.62243363_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 16:50:03'),
(166, 8, 41, '8_6957fad1ef3666.99863821_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_6957fad1ef3666.99863821_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 17:05:22'),
(167, 8, 41, '8_6957fad1f08882.23962914_Finnish-FLOW-FAQ.pdf', 'uploads/8_6957fad1f08882.23962914_Finnish-FLOW-FAQ.pdf', '2026-01-02 17:05:22'),
(168, 8, 41, '8_6957fad1f11062.46405372_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_6957fad1f11062.46405372_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 17:05:22'),
(169, 8, 41, '8_6957fad1f17f13.66197346_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_6957fad1f17f13.66197346_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 17:05:22'),
(170, 8, 41, '8_6957fad1f232d4.75130117_Finnish-FLOW-NOTES.pdf', 'uploads/8_6957fad1f232d4.75130117_Finnish-FLOW-NOTES.pdf', '2026-01-02 17:05:22'),
(171, 8, 41, '8_6957fad1f2af36.06022853_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_6957fad1f2af36.06022853_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 17:05:22'),
(172, 8, 42, '8_69580627269c52.21789092_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_69580627269c52.21789092_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 17:53:43'),
(173, 8, 43, '8_695808c7447046.88818267_Finnish-FLOW-Messenger-followup.pdf', 'uploads/8_695808c7447046.88818267_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 18:04:55'),
(174, 8, 44, '8_69580a80c9c5f0.49599941_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_69580a80c9c5f0.49599941_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 18:12:16'),
(175, 8, 45, '8_69580c29ae80d0.08557755_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/8_69580c29ae80d0.08557755_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 18:19:21'),
(176, 8, 46, '8_695817e5b0f6b3.82274751_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/8_695817e5b0f6b3.82274751_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 19:09:25'),
(177, 10, 60, '9_69583610f04bb6.75196296_Finnish-FLOW-TELEGRAM-FOLLOWUPS.pdf', 'uploads/9_69583610f04bb6.75196296_Finnish-FLOW-TELEGRAM-FOLLOWUPS.pdf', '2026-01-02 21:18:09'),
(178, 10, 60, '9_69583610f100d2.66952510_Finnish-FLOW-FAQ.pdf', 'uploads/9_69583610f100d2.66952510_Finnish-FLOW-FAQ.pdf', '2026-01-02 21:18:09'),
(179, 10, 60, '9_69583610f15305.16547958_Finnish-FLOW-Messenger-FLOW.pdf', 'uploads/9_69583610f15305.16547958_Finnish-FLOW-Messenger-FLOW.pdf', '2026-01-02 21:18:09'),
(180, 10, 60, '9_69583610f1a245.59674189_Finnish-FLOW-Messenger-followup.pdf', 'uploads/9_69583610f1a245.59674189_Finnish-FLOW-Messenger-followup.pdf', '2026-01-02 21:18:09'),
(181, 10, 60, '9_69583610f20005.62833356_Finnish-FLOW-NOTES.pdf', 'uploads/9_69583610f20005.62833356_Finnish-FLOW-NOTES.pdf', '2026-01-02 21:18:09'),
(182, 10, 60, '9_69583610f255e3.76906878_Finnish-FLOW-TELEGRAM-FLOW.pdf', 'uploads/9_69583610f255e3.76906878_Finnish-FLOW-TELEGRAM-FLOW.pdf', '2026-01-02 21:18:09');

-- --------------------------------------------------------

--
-- Table structure for table `exam_answers`
--

CREATE TABLE `exam_answers` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `student_answer` text DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `exam_answers`
--

INSERT INTO `exam_answers` (`id`, `submission_id`, `question_id`, `student_answer`, `is_correct`) VALUES
(1, 1, 5, 'C', 1),
(2, 1, 6, 'C', 0),
(3, 1, 7, 'A', 0),
(4, 1, 8, 'D', 0),
(5, 2, 5, 'C', 1),
(6, 2, 6, 'B', 1),
(7, 2, 7, 'C', 1),
(8, 2, 8, 'C', 1),
(9, 3, 2, 'C', 1),
(10, 3, 3, 'D', 0),
(11, 3, 4, 'D', 0),
(12, 4, 2, 'C', 1),
(13, 4, 3, 'C', 1),
(14, 4, 4, 'D', 1),
(15, 5, 1, 'B', 0),
(16, 6, 5, 'C', 1),
(17, 6, 6, 'A', 0),
(18, 6, 7, 'B', 0),
(19, 6, 8, 'A', 0),
(20, 7, 5, 'C', 1),
(21, 7, 6, 'C', 0),
(22, 7, 7, 'A', 0),
(23, 7, 8, 'A', 0),
(24, 8, 1, 'C', 1),
(25, 9, 2, 'C', 1),
(26, 9, 3, 'C', 0),
(27, 9, 4, 'B', 0),
(28, 10, 5, 'D', 0),
(29, 10, 6, 'D', 0),
(30, 10, 7, 'D', 0),
(31, 10, 8, 'D', 0),
(32, 11, 2, 'B', 0),
(33, 11, 3, 'C', 0),
(34, 11, 4, 'A', 0),
(35, 12, 1, 'B', 0),
(36, 13, 1, 'B', 0),
(37, 14, 2, 'A', 0),
(38, 14, 3, 'C', 0),
(39, 14, 4, 'C', 1),
(40, 15, 2, 'A', 0),
(41, 15, 3, 'D', 0),
(42, 15, 4, 'C', 1),
(43, 16, 5, 'D', 0),
(44, 16, 6, 'C', 0),
(45, 16, 7, 'D', 0),
(46, 16, 8, 'D', 0),
(47, 17, 1, 'A', 0),
(48, 18, 2, 'C', 1),
(49, 18, 3, 'B', 1),
(50, 18, 4, 'C', 1);

-- --------------------------------------------------------

--
-- Table structure for table `exam_questions`
--

CREATE TABLE `exam_questions` (
  `id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` varchar(50) NOT NULL,
  `options` text DEFAULT NULL,
  `correct_answer` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `exam_questions`
--

INSERT INTO `exam_questions` (`id`, `scholarship_id`, `question_text`, `question_type`, `options`, `correct_answer`, `created_at`) VALUES
(1, 7, 'hi/', 'multiple_choice', 'A.h\r\nB. C\r\nC>f', 'C', '2025-12-28 08:48:16'),
(2, 10, '1. If a student submits incomplete scholarship requirements, the Student Assistant should follow established procedures to notify the student and provide guidance on completing the application.', 'multiple_choice', 'A. Accept the application to help the student\r\nB. Reject the student immediately\r\nC. Explain the missing requirements and follow office policy\r\nD. Complete the requirements for the student', 'C', '2025-12-28 10:00:58'),
(3, 10, '2. What is the significance of maintaining confidentiality within the Scholarship Office?', 'multiple_choice', 'A. To avoid extra work\r\nB. To protect students’ personal and academic records\r\nC. To limit student inquiries\r\nD. To speed up the process', 'B', '2025-12-28 10:14:13'),
(4, 10, '3. If a friend requests that their institutional scholarship application be given priority, the most appropriate action is to adhere to established procedures and maintain impartiality.', 'multiple_choice', 'A. Help because they are your friend\r\nB. Ignore the request\r\nC. Explain that all applicants must follow the same process\r\nD. Submit their application first', 'c', '2025-12-28 11:06:08'),
(5, 11, '1. If a student submits incomplete scholarship requirements, the Student Assistant should follow established procedures to notify the student and provide guidance on completing the application.', 'multiple_choice', 'A. Accept the application to help the student\r\nB. Reject the student immediately\r\nC. Explain the missing requirements and follow office policy\r\nD. Complete the requirements for the student', 'C', '2025-12-28 15:59:09'),
(6, 11, '2. What is the significance of maintaining confidentiality within the Scholarship Office?', 'multiple_choice', 'A. To avoid extra work\r\nB. To protect students’ personal and academic records\r\nC. To limit student inquiries\r\nD. To speed up the process', 'B', '2025-12-28 15:59:26'),
(7, 11, '3. If a friend requests that their institutional scholarship application be given priority, the most appropriate action is to adhere to established procedures and maintain impartiality.', 'multiple_choice', 'A. Help because they are your friend\r\nB. Ignore the request\r\nC. Explain that all applicants must follow the same process\r\nD. Submit their application first', 'C', '2025-12-28 15:59:46'),
(8, 11, '4. When assigned multiple tasks with identical deadlines, which task should be prioritized initially?', 'multiple_choice', 'A. Panic and wait for instructions\r\nB. Do the easiest task only\r\nC. Prioritize tasks and ask guidance if needed\r\nD. Leave some tasks unfinished', 'C', '2025-12-28 16:00:05');

-- --------------------------------------------------------

--
-- Table structure for table `exam_submissions`
--

CREATE TABLE `exam_submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `score` int(11) DEFAULT 0,
  `total_items` int(11) DEFAULT 0,
  `status` enum('in_progress','submitted','graded') DEFAULT 'in_progress',
  `start_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `end_time` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `exam_submissions`
--

INSERT INTO `exam_submissions` (`id`, `student_id`, `scholarship_id`, `score`, `total_items`, `status`, `start_time`, `end_time`) VALUES
(1, 8, 11, 1, 4, 'graded', '2025-12-28 09:39:54', '2025-12-28 09:40:10'),
(2, 8, 11, 4, 4, 'graded', '2025-12-28 09:45:32', '2025-12-28 09:45:50'),
(3, 8, 10, 1, 3, 'graded', '2025-12-29 10:12:23', '2025-12-29 10:12:29'),
(4, 8, 10, 3, 3, 'graded', '2025-12-30 05:51:27', '2025-12-30 05:51:38'),
(5, 4, 7, 0, 1, 'graded', '2025-12-31 09:06:12', '2025-12-31 09:06:16'),
(6, 4, 11, 1, 4, 'graded', '2025-12-31 09:07:13', '2025-12-31 09:07:21'),
(7, 8, 11, 1, 4, 'graded', '2026-01-01 23:47:45', '2026-01-01 23:47:52'),
(8, 8, 7, 1, 1, 'graded', '2026-01-01 23:56:09', '2026-01-01 23:56:12'),
(9, 8, 10, 1, 3, 'graded', '2026-01-02 00:06:28', '2026-01-02 00:06:34'),
(10, 2, 11, 0, 4, 'graded', '2026-01-02 00:25:11', '2026-01-02 00:25:18'),
(11, 2, 10, 0, 3, 'graded', '2026-01-02 00:25:51', '2026-01-02 00:25:58'),
(12, 2, 7, 0, 1, 'graded', '2026-01-02 00:26:24', '2026-01-02 00:26:27'),
(13, 8, 7, 0, 1, 'graded', '2026-01-02 00:48:16', '2026-01-02 00:48:19'),
(14, 8, 10, 1, 3, 'graded', '2026-01-02 00:49:08', '2026-01-02 00:49:14'),
(15, 3, 10, 1, 3, 'graded', '2026-01-02 01:30:31', '2026-01-02 01:30:37'),
(16, 8, 11, 0, 4, 'graded', '2026-01-02 09:50:03', '2026-01-02 09:50:09'),
(17, 8, 7, 0, 1, 'graded', '2026-01-02 10:05:22', '2026-01-02 10:05:25'),
(18, 9, 10, 3, 3, 'graded', '2026-01-02 14:18:09', '2026-01-02 14:18:15');

-- --------------------------------------------------------

--
-- Table structure for table `forms`
--

CREATE TABLE `forms` (
  `id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `forms`
--

INSERT INTO `forms` (`id`, `scholarship_id`, `title`, `description`, `created_at`, `updated_at`) VALUES
(4, 7, 'a Application Form', NULL, '2025-12-28 08:36:57', '2025-12-28 08:36:57'),
(18, 10, 'Student Assistant Scholarship Application Form', NULL, '2026-01-02 21:18:08', '2026-01-02 21:18:08');

-- --------------------------------------------------------

--
-- Table structure for table `form_fields`
--

CREATE TABLE `form_fields` (
  `id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `field_label` varchar(255) NOT NULL,
  `field_type` enum('text','textarea','select','file') NOT NULL,
  `field_options` text DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `field_order` int(11) NOT NULL DEFAULT 0,
  `options` text DEFAULT NULL,
  `field_name` varchar(255) DEFAULT NULL,
  `form_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `form_fields`
--

INSERT INTO `form_fields` (`id`, `scholarship_id`, `field_label`, `field_type`, `field_options`, `is_required`, `field_order`, `options`, `field_name`, `form_id`) VALUES
(2, 10, 'Birthplace', 'text', NULL, 1, 0, NULL, 'birthplace', 18),
(3, 10, 'Age', 'text', NULL, 1, 0, NULL, 'age', 18),
(4, 10, 'Permanent Address', 'text', NULL, 1, 0, NULL, 'permanent_address', 18),
(5, 10, 'Current Address', 'text', NULL, 1, 0, NULL, 'current_address', 18),
(6, 10, 'Tribe', 'text', NULL, 1, 0, NULL, 'tribe', 18),
(7, 10, 'Mother Name', 'text', NULL, 1, 0, NULL, 'mother_name', 18),
(8, 10, 'Mother Occupation', 'text', NULL, 1, 0, NULL, 'mother_occupation', 18),
(9, 10, 'Mother Contact', 'text', NULL, 1, 0, NULL, 'mother_contact', 18),
(10, 10, 'Father Name', 'text', NULL, 1, 0, NULL, 'father_name', 18),
(11, 10, 'Father Occupation', 'text', NULL, 1, 0, NULL, 'father_occupation', 18),
(12, 10, 'Father Contact', 'text', NULL, 1, 0, NULL, 'father_contact', 18),
(13, 10, 'Parents Income', 'text', NULL, 1, 0, NULL, 'parents_income', 18),
(14, 10, 'Is Working Student', 'text', NULL, 1, 0, NULL, 'is_working_student', 18),
(15, 10, 'Is Pwd', 'text', NULL, 1, 0, NULL, 'is_pwd', 18),
(16, 10, 'Jhs School', 'text', NULL, 1, 0, NULL, 'jhs_school', 18),
(17, 10, 'Shs School', 'text', NULL, 1, 0, NULL, 'shs_school', 18),
(18, 10, 'Current School', 'text', NULL, 1, 0, NULL, 'current_school', 18),
(19, 10, 'Current Year Level', 'text', NULL, 1, 0, NULL, 'current_year_level', 18),
(20, 10, 'Essay Motivation', 'textarea', NULL, 1, 0, NULL, 'essay_motivation', 18),
(21, 10, 'Jhs Year', 'text', NULL, 1, 0, NULL, 'jhs_year', 18),
(22, 10, 'Shs Year', 'text', NULL, 1, 0, NULL, 'shs_year', 18);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scholarships`
--

CREATE TABLE `scholarships` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `deadline` date NOT NULL,
  `requirements` text DEFAULT NULL,
  `application_requirements` text DEFAULT NULL,
  `benefits` text DEFAULT NULL,
  `available_slots` int(11) DEFAULT 10,
  `category` varchar(100) DEFAULT 'general',
  `accepting_new_applicants` tinyint(1) NOT NULL DEFAULT 1,
  `accepting_renewal_applicants` tinyint(1) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) DEFAULT 500.00,
  `status` enum('active','inactive','archived') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `requires_exam` tinyint(1) NOT NULL DEFAULT 0,
  `passing_grade` int(11) DEFAULT 75,
  `passing_score` int(11) DEFAULT 75,
  `exam_duration` int(11) DEFAULT 60,
  `end_of_term` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholarships`
--

INSERT INTO `scholarships` (`id`, `name`, `description`, `deadline`, `requirements`, `application_requirements`, `benefits`, `available_slots`, `category`, `accepting_new_applicants`, `accepting_renewal_applicants`, `amount`, `status`, `created_at`, `requires_exam`, `passing_grade`, `passing_score`, `exam_duration`, `end_of_term`) VALUES
(7, 'a', 'a', '2026-01-16', 's', 'a', 'a', 1, 'Pastors Kids', 1, 1, 1.00, 'active', '2025-12-28 08:27:28', 0, 75, 75, 60, '2026-01-16'),
(10, 'Student Assistant Scholarship', 'The Student Assistant is expected to assist the department or office in all activities\r\ninherent to the official tasks. The student assistant shall accept clerical, messengerial, and\r\nministerial task pertinent to the needs of the department and other task which the\r\nDepartmental Head may assign.', '2026-01-07', '1. Financial Need: A student considered financially challenged when neither his or her\r\nparents nor his guardian have sufficient resources to finance his/her higher education,\r\nthrough a duly certified statement of monthly income.\r\n2. Academic Requirement: To be considered intellectually capable, the applicant must\r\npossess good academic standing (no grade below 80%) and with general weighted\r\naverage of at least 85%\r\n3. General Ability to perform Work Requirement:\r\n- Good Moral Character and integrity as certified by the Guidance Counselor.\r\n- Without derogatory records from the local police or local barangay\r\n- Physically fit for work as certified by duly licensed physician.\r\n- Screened by the OSAD for positive work attitude and ethics.', 'Conditions/Requirements:\r\n1. Student Assistants have to render 20 hours duty per week equivalent to a minimum of\r\n18 units or a maximum of 21 units across all the programs.\r\n2. Should Student Assistants enroll for more than 21 units, they should first write a letter\r\nto OSAD subject approval.\r\n3. The service hours of Student Assistants are determined by the attendance registered\r\non the Biometrics System and Department Attendance Logbook.\r\n4. Recommendation letter either from the Program head/Guidance Counselor/OSAD.\r\n5. Student Assistants have to render only 2 years of service.', '- All Student Assistants (SA’s) may enjoy 100% full discounts on tuition fees with an\r\nequivalent to 21 units and miscellaneous fees. Supplemental fees are not included in this\r\nbenefit and thus will be shouldered by the student assistant personally. If SA enrolled\r\nbeyond 21 units, he/she has to pay the excess units.\r\n-All Student Assistants may also receive an allowance of 2,000.00 per month.', 1, 'Student assistant', 1, 1, 123.00, 'active', '2025-12-28 09:59:58', 0, 75, 75, 60, NULL),
(11, 'b', 'b', '2026-01-06', 'b', 'b', 'b', 1, 'Student assistant', 1, 1, 2.00, 'active', '2025-12-28 15:58:41', 1, 75, 3, 60, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_submissions_10`
--

CREATE TABLE `scholarship_submissions_10` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `year_level` text DEFAULT NULL,
  `program` text DEFAULT NULL,
  `units_enrolled` text DEFAULT NULL,
  `gwa` text DEFAULT NULL,
  `contact_number` text DEFAULT NULL,
  `birthdate` text DEFAULT NULL,
  `birthplace` text DEFAULT NULL,
  `age` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `current_address` text DEFAULT NULL,
  `tribe` text DEFAULT NULL,
  `mother_name` text DEFAULT NULL,
  `mother_occupation` text DEFAULT NULL,
  `mother_contact` text DEFAULT NULL,
  `father_name` text DEFAULT NULL,
  `father_occupation` text DEFAULT NULL,
  `father_contact` text DEFAULT NULL,
  `parents_income` text DEFAULT NULL,
  `is_working_student` text DEFAULT NULL,
  `is_pwd` text DEFAULT NULL,
  `jhs_school` text DEFAULT NULL,
  `shs_school` text DEFAULT NULL,
  `current_school` text DEFAULT NULL,
  `current_year_level` text DEFAULT NULL,
  `essay_motivation` text DEFAULT NULL,
  `jhs_year` text DEFAULT NULL,
  `shs_year` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `scholarship_submissions_10`
--

INSERT INTO `scholarship_submissions_10` (`id`, `application_id`, `student_id`, `submitted_at`, `year_level`, `program`, `units_enrolled`, `gwa`, `contact_number`, `birthdate`, `birthplace`, `age`, `permanent_address`, `current_address`, `tribe`, `mother_name`, `mother_occupation`, `mother_contact`, `father_name`, `father_occupation`, `father_contact`, `parents_income`, `is_working_student`, `is_pwd`, `jhs_school`, `shs_school`, `current_school`, `current_year_level`, `essay_motivation`, `jhs_year`, `shs_year`) VALUES
(1, 60, 9, '2026-01-02 22:18:09', '2nd Year', 'BSIT', '1', '1', '09937414256', '2026-01-15', '1', '0213', '1', '1', '1', '1', '1', '2', '1', '1', '2', '1', 'No', 'No', '1', '1', '1', '1', '23q3', '1 - 1', '1 - 1');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `school_id_number` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `student_type` enum('new','renewal') DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `student_name`, `school_id_number`, `email`, `phone`, `date_of_birth`, `address`, `student_type`, `created_at`, `updated_at`) VALUES
(1, 2, 'John Michael Doe', '2022-001-A', 'john.doe@example.com', '555-111-2222', '2004-05-15', '123 Main St, Anytown, USA', 'new', '2025-12-10 09:01:00', '2025-12-10 09:01:00'),
(2, 3, 'Jane Anne Smith', '2021-002-B', 'jane.smith@example.com', '555-333-4444', '2003-08-22', '456 Oak Ave, Sometown, USA', 'new', '2025-12-11 10:01:00', '2026-01-02 07:26:24'),
(3, 4, 'Peter Jones', '2023-003-C', 'peter.jones@example.com', '555-555-6666', '2005-01-30', '789 Pine Ln, Yourtown, USA', 'new', '2025-12-12 11:01:00', '2026-01-02 08:30:31'),
(4, 5, 'Emily Rose White', '2022-004-D', 'emily.white@example.com', '555-777-8888', '2004-11-12', '101 Maple Dr, Newtown, USA', 'new', '2025-12-13 12:01:00', '2025-12-31 16:07:13'),
(6, 1, 'Admin User', 'ADMIN001', 'jhorose@dvci-edu.com', '123-456-7890', '1990-01-01', NULL, 'new', '2025-12-17 14:50:16', '2025-12-17 16:47:34'),
(8, 8, 'awl dawd lkesmnfolsa', '20240596054', 'jhorosef@gmail.com', '09937414256', '1999-06-27', NULL, 'new', '2025-12-27 05:54:32', '2026-01-02 19:09:25'),
(9, 10, 'sdf d dsfs', '20240596056', 'firmezajhorose04@gmail.com', '09937414256', '2026-01-15', NULL, 'new', '2026-01-02 20:16:13', '2026-01-02 21:18:08');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `school_id` varchar(100) DEFAULT NULL,
  `role` enum('student','admin') NOT NULL DEFAULT 'student',
  `status` enum('active','inactive','archived') NOT NULL DEFAULT 'active',
  `email_verified` tinyint(1) DEFAULT 0,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `student_type` varchar(50) DEFAULT 'New Applicant',
  `profile_picture_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `middle_name`, `last_name`, `email`, `password`, `contact_number`, `birthdate`, `school_id`, `role`, `status`, `email_verified`, `email_verified_at`, `created_at`, `updated_at`, `student_type`, `profile_picture_path`) VALUES
(1, 'Admin', NULL, 'User', 'jhorose@dvci-edu.com', 'adminpassword', '123-456-7890', '1990-01-01', 'ADMIN001', 'admin', 'active', 1, '2025-12-13 13:00:00', '2025-12-13 13:00:00', '2025-12-17 13:54:10', 'New Applicant', NULL),
(2, 'John', 'Michael', 'Doe', 'john.doe@example.com', 'password123', '555-111-2222', '2004-05-15', '2022-001-A', 'student', 'active', 1, '2025-12-10 09:00:00', '2025-12-10 09:00:00', '2025-12-10 09:00:00', 'New Applicant', NULL),
(3, 'Jane', 'Anne', 'Smith', 'jane.smith@example.com', 'password123', '555-333-4444', '2003-08-22', '2021-002-B', 'student', 'active', 1, '2025-12-11 10:00:00', '2025-12-11 10:00:00', '2025-12-11 10:00:00', 'New Applicant', NULL),
(4, 'Peter', NULL, 'Jones', 'peter.jones@example.com', 'password123', '555-555-6666', '2005-01-30', '2023-003-C', 'student', 'active', 1, '2025-12-12 11:00:00', '2025-12-12 11:00:00', '2025-12-12 11:00:00', 'New Applicant', NULL),
(5, 'Emily', 'Rose', 'White', 'emily.white@example.com', 'password123', '555-777-8888', '2004-11-12', '2022-004-D', 'student', 'active', 1, '2025-12-13 12:00:00', '2025-12-13 12:00:00', '2025-12-13 12:00:00', 'New Applicant', NULL),
(8, 'awl', 'dawd', 'lkesmnfolsa', 'jhorosef@gmail.com', '12345678', '09937414256', '1999-06-27', '20240596054', 'student', 'active', 1, NULL, '2025-12-27 05:54:32', '2026-01-02 19:50:15', 'New Applicant', 'uploads/avatars/user_8_1767383415_0aa7131d-c7ca-43cc-afd9-269eed0b385e.jfif'),
(9, 'System', NULL, 'Admin', 'admin@dvc.edu.ph', 'admin123', NULL, NULL, NULL, 'admin', 'archived', 1, NULL, '2025-12-28 08:03:56', '2026-01-02 13:57:33', 'New Applicant', NULL),
(10, 'sdf', 'd', 'dsfs', 'firmezajhorose04@gmail.com', '12345678', '09937414256', '2026-01-15', '20240596056', 'student', 'active', 1, NULL, '2026-01-02 20:16:13', '2026-01-02 20:43:13', 'New Applicant', 'uploads/avatars/new_user_6958278d50c25_0be13ddc-9e76-4756-ba97-29cc6fcf37f5.jfif');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `scholarship_id` (`scholarship_id`);

--
-- Indexes for table `application_exams`
--
ALTER TABLE `application_exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`);

--
-- Indexes for table `application_responses`
--
ALTER TABLE `application_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `form_field_id` (`form_field_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `exam_answers`
--
ALTER TABLE `exam_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scholarship_id` (`scholarship_id`);

--
-- Indexes for table `exam_submissions`
--
ALTER TABLE `exam_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scholarship_id` (`scholarship_id`);

--
-- Indexes for table `forms`
--
ALTER TABLE `forms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scholarship_id` (`scholarship_id`);

--
-- Indexes for table `form_fields`
--
ALTER TABLE `form_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scholarship_id` (`scholarship_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarships`
--
ALTER TABLE `scholarships`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarship_submissions_10`
--
ALTER TABLE `scholarship_submissions_10`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `school_id` (`school_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `application_exams`
--
ALTER TABLE `application_exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `application_responses`
--
ALTER TABLE `application_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=183;

--
-- AUTO_INCREMENT for table `exam_answers`
--
ALTER TABLE `exam_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `exam_questions`
--
ALTER TABLE `exam_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `exam_submissions`
--
ALTER TABLE `exam_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `forms`
--
ALTER TABLE `forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `form_fields`
--
ALTER TABLE `form_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scholarships`
--
ALTER TABLE `scholarships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `scholarship_submissions_10`
--
ALTER TABLE `scholarship_submissions_10`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_exams`
--
ALTER TABLE `application_exams`
  ADD CONSTRAINT `application_exams_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_responses`
--
ALTER TABLE `application_responses`
  ADD CONSTRAINT `application_responses_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_responses_ibfk_2` FOREIGN KEY (`form_field_id`) REFERENCES `form_fields` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `exam_answers`
--
ALTER TABLE `exam_answers`
  ADD CONSTRAINT `exam_answers_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `exam_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `exam_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD CONSTRAINT `exam_questions_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_submissions`
--
ALTER TABLE `exam_submissions`
  ADD CONSTRAINT `exam_submissions_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `forms`
--
ALTER TABLE `forms`
  ADD CONSTRAINT `forms_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `form_fields`
--
ALTER TABLE `form_fields`
  ADD CONSTRAINT `form_fields_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
