-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 29, 2026 at 06:14 AM
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
-- Database: `fixie_drms`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `action_type`, `description`, `ip_address`, `timestamp`) VALUES
(1, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 02:51:56'),
(2, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-16 02:52:06'),
(3, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 02:52:37'),
(4, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 02:52:43'),
(5, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-16 02:52:53'),
(6, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 02:52:56'),
(7, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:00:41'),
(8, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:00:43'),
(9, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-16 03:00:50'),
(10, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-16 03:01:01'),
(11, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 84', '::1', '2026-04-16 03:01:08'),
(12, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:01:29'),
(13, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-16 03:01:31'),
(14, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:13:29'),
(15, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts', '::1', '2026-04-16 03:13:42'),
(16, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:14:08'),
(17, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:14:14'),
(18, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts', '::1', '2026-04-16 03:14:22'),
(19, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:14:24'),
(20, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts', '::1', '2026-04-16 03:14:26'),
(21, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-16 03:14:31'),
(22, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:14:32'),
(23, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-16 03:14:43'),
(24, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:14:49'),
(25, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:14:52'),
(26, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts', '::1', '2026-04-16 03:14:54'),
(27, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:14:56'),
(28, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts', '::1', '2026-04-16 03:15:33'),
(29, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:20:18'),
(30, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:20:20'),
(31, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-16 03:20:21'),
(32, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts', '::1', '2026-04-16 03:20:33'),
(33, 9, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: Mayor\'s Permit [Business Permits]', '::1', '2026-04-16 03:22:08'),
(34, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:22:11'),
(35, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-16 03:22:12'),
(36, 9, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: Permit [Business Permits]', '::1', '2026-04-16 03:23:29'),
(37, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:23:32'),
(38, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n3', '::1', '2026-04-16 03:23:33'),
(39, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:25:48'),
(40, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n3', '::1', '2026-04-16 03:25:50'),
(41, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:25:57'),
(42, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n3', '::1', '2026-04-16 03:25:57'),
(43, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:25:59'),
(44, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:26:02'),
(45, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n3', '::1', '2026-04-16 03:26:02'),
(46, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:28:05'),
(47, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:28:14'),
(48, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:28:25'),
(49, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-16 03:28:35'),
(50, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:29:44'),
(51, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:35:32'),
(52, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:35:41'),
(53, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-16 03:38:53'),
(54, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-16 03:38:56'),
(55, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n3', '::1', '2026-04-16 03:39:00'),
(56, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-16 03:39:02'),
(57, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 12:28:20'),
(58, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:28:21'),
(59, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:28:34'),
(60, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:28:52'),
(61, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-19 12:29:03'),
(62, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:29:04'),
(63, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-19 12:29:05'),
(64, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:29:52'),
(65, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:29:59'),
(66, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:30:09'),
(67, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:30:10'),
(68, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 101', '::1', '2026-04-19 12:30:23'),
(69, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:30:25'),
(70, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 12:30:28'),
(71, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 12:30:36'),
(72, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:30:36'),
(73, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 101', '::1', '2026-04-19 12:30:44'),
(74, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:30:47'),
(75, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 101', '::1', '2026-04-19 12:30:56'),
(76, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:30:57'),
(77, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 101', '::1', '2026-04-19 12:31:00'),
(78, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 101', '::1', '2026-04-19 12:31:05'),
(79, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:31:08'),
(80, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 12:31:11'),
(81, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 12:31:16'),
(82, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:31:16'),
(83, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:31:18'),
(84, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:36:16'),
(85, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:36:20'),
(86, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:36:21'),
(87, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:36:33'),
(88, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:40:11'),
(89, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:40:18'),
(90, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:40:39'),
(91, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:40:55'),
(92, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:40:57'),
(93, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:41:09'),
(94, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'all\'', '::1', '2026-04-19 12:41:14'),
(95, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Rejected\'', '::1', '2026-04-19 12:41:16'),
(96, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Pending\'', '::1', '2026-04-19 12:41:18'),
(97, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Approved\'', '::1', '2026-04-19 12:41:20'),
(98, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Pending\'', '::1', '2026-04-19 12:41:22'),
(99, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'all\'', '::1', '2026-04-19 12:41:23'),
(100, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:42:17'),
(101, 3, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 101', '::1', '2026-04-19 12:42:19'),
(102, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:42:27'),
(103, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:44:15'),
(104, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:44:20'),
(105, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:44:35'),
(106, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:50:01'),
(107, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:50:03'),
(108, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:50:32'),
(109, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:50:34'),
(110, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:50:37'),
(111, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:53:11'),
(112, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:53:14'),
(113, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:53:17'),
(114, 3, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 173', '::1', '2026-04-19 12:53:22'),
(115, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:53:27'),
(116, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:56:22'),
(117, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:56:31'),
(118, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:56:35'),
(119, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Pending\'', '::1', '2026-04-19 12:56:40'),
(120, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Approved\'', '::1', '2026-04-19 12:56:45'),
(121, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Rejected\'', '::1', '2026-04-19 12:56:47'),
(122, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Approved\'', '::1', '2026-04-19 12:56:50'),
(123, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'all\'', '::1', '2026-04-19 12:56:54'),
(124, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 12:57:00'),
(125, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 12:57:05'),
(126, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:57:06'),
(127, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:57:13'),
(128, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:57:14'),
(129, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 12:57:56'),
(130, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 12:58:01'),
(131, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:58:01'),
(132, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:58:04'),
(133, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:58:05'),
(134, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 12:58:06'),
(135, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 12:58:11'),
(136, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:58:11'),
(137, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 12:58:21'),
(138, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 12:58:24'),
(139, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:02:47'),
(140, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-19 13:02:49'),
(141, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:02:50'),
(142, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:02:55'),
(143, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:02:57'),
(144, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:02:59'),
(145, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:03:02'),
(146, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:03:06'),
(147, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:03:10'),
(148, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:03:10'),
(149, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:03:15'),
(150, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:03:33'),
(151, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:03:42'),
(152, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:03:42'),
(153, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:05:35'),
(154, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:05:36'),
(155, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Pending\'', '::1', '2026-04-19 13:05:39'),
(156, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-19 13:05:42'),
(157, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:05:54'),
(158, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:06:00'),
(159, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:06:02'),
(160, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:06:06'),
(161, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:06:06'),
(162, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-19 13:06:11'),
(163, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-19 13:06:12'),
(164, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-19 13:06:14'),
(165, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:06:16'),
(166, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-19 13:06:19'),
(167, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-19 13:06:23'),
(168, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:06:25'),
(169, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:06:29'),
(170, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:06:29'),
(171, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:06:31'),
(172, 3, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-19 13:06:33'),
(173, 3, 'CREATE_PO', 'Created new PO: PO-2026-0001 mapped to PR ID: 179', '::1', '2026-04-19 13:07:27'),
(174, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:07:34'),
(175, 3, 'PRINT_DOC', 'Printed document: PO #PO-2026-0001', '::1', '2026-04-19 13:08:17'),
(176, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:08:36'),
(177, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:08:40'),
(178, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:08:40'),
(179, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:08:43'),
(180, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-19 13:08:47'),
(181, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:08:48'),
(182, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-19 13:08:50'),
(183, 9, 'APPROVE_PO', 'Advanced PO 117 to GM-Approved', '::1', '2026-04-19 13:09:33'),
(184, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:09:39'),
(185, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:09:43'),
(186, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:09:43'),
(187, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:10:22'),
(188, 8, 'APPROVE_PO', 'Advanced PO 117 to Finance-Approved', '::1', '2026-04-19 13:10:33'),
(189, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:10:34'),
(190, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:10:42'),
(191, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:10:42'),
(192, 6, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:10:46'),
(193, 6, 'PRINT_DOC', 'Printed document: PO #PO-2026-0001', '::1', '2026-04-19 13:10:55'),
(194, 6, 'PRINT_DOC', 'Printed document: PO #PO-2026-0001', '::1', '2026-04-19 13:11:02'),
(195, 6, 'APPROVE_PO', 'Advanced PO 117 to President-Approved', '::1', '2026-04-19 13:11:33'),
(196, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:11:35'),
(197, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:11:41'),
(198, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:11:41'),
(199, 8, 'APPROVE_PO', 'Advanced PO 117 to Funded', '::1', '2026-04-19 13:11:50'),
(200, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:11:55'),
(201, 12, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:12:06'),
(202, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:12:07'),
(203, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:12:22'),
(204, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:15:09'),
(205, 12, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:15:11'),
(206, 12, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:15:33'),
(207, 12, 'APPROVE_PO', 'Advanced PO 117 to Delivered', '::1', '2026-04-19 13:15:43'),
(208, 12, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:15:45'),
(209, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:15:59'),
(210, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:15:59'),
(211, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:16:04'),
(212, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:18:52'),
(213, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:18:54'),
(214, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:18:55'),
(215, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:18:57'),
(216, 12, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:19:09'),
(217, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:19:09'),
(218, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:19:11'),
(219, 12, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:19:17'),
(220, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:19:21'),
(221, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:19:21'),
(222, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:19:23'),
(223, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:19:55'),
(224, 12, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:20:01'),
(225, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:20:01'),
(226, 12, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:20:05'),
(227, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:20:09'),
(228, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:20:09'),
(229, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:20:12'),
(230, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:34:48'),
(231, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:34:53'),
(232, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:34:53'),
(233, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:34:56'),
(234, 8, 'ADD_PAYMENT', 'Added payment of P50000 to PO 117', '::1', '2026-04-19 13:38:42'),
(235, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:38:56'),
(236, 8, 'ADD_PAYMENT', 'Added payment of P20000 to PO 117', '::1', '2026-04-19 13:39:06'),
(237, 8, 'ADD_PAYMENT', 'Added payment of P30000 to PO 117', '::1', '2026-04-19 13:39:10'),
(238, 8, 'PRINT_DOC', 'Printed document: PO #PO-2026-0001', '::1', '2026-04-19 13:39:46'),
(239, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:40:01'),
(240, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:40:07'),
(241, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:40:11'),
(242, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:40:11'),
(243, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:40:13'),
(244, 3, 'CREATE_PO', 'Created new PO: PO-2026-0002 mapped to PR ID: ', '::1', '2026-04-19 13:40:43'),
(245, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:40:45'),
(246, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:40:48'),
(247, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:40:48'),
(248, 9, 'APPROVE_PO', 'Advanced PO 118 to GM-Approved', '::1', '2026-04-19 13:41:10'),
(249, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:41:12'),
(250, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:41:16'),
(251, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:41:16'),
(252, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:41:22'),
(253, 8, 'APPROVE_PO', 'Advanced PO 118 to Finance-Approved', '::1', '2026-04-19 13:41:38'),
(254, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:41:40'),
(255, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:41:48'),
(256, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:41:48'),
(257, 6, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:42:04'),
(258, 6, 'APPROVE_PO', 'Advanced PO 118 to President-Approved', '::1', '2026-04-19 13:42:10'),
(259, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:42:11'),
(260, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:42:20'),
(261, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:42:20'),
(262, 8, 'APPROVE_PO', 'Advanced PO 118 to Funded', '::1', '2026-04-19 13:42:28'),
(263, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:42:29'),
(264, 12, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:42:42'),
(265, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:42:42'),
(266, 12, 'APPROVE_PO', 'Advanced PO 118 to Delivered', '::1', '2026-04-19 13:42:46'),
(267, 12, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 13:42:48'),
(268, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 13:43:04'),
(269, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 13:43:04'),
(270, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 13:43:06'),
(271, 8, 'ADD_PAYMENT', 'Added payment of P50000 to PO 118', '::1', '2026-04-19 13:45:19'),
(272, 8, 'ADD_PAYMENT', 'Added payment of P20000 to PO 118', '::1', '2026-04-19 13:47:02'),
(273, 8, 'ADD_PAYMENT', 'Added payment of P50000 to PO 118', '::1', '2026-04-19 14:00:17'),
(274, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:00:37'),
(275, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:00:43'),
(276, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 14:04:45'),
(277, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:04:47'),
(278, 8, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-19 14:05:34'),
(279, 8, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-19 14:05:35'),
(280, 8, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-19 14:30:34'),
(281, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:30:35'),
(282, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-19 14:30:38'),
(283, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-19 14:30:42'),
(284, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:30:42'),
(285, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-19 14:30:43'),
(286, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:31:00'),
(287, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-19 14:31:00'),
(288, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:31:02'),
(289, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:31:04'),
(290, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-19 14:31:04'),
(291, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:31:06'),
(292, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-19 14:31:08'),
(293, 9, 'ARCHIVE_FILE', 'Archived Document ID: 134', '::1', '2026-04-19 14:31:20'),
(294, 9, 'ARCHIVE_FILE', 'Archived Document ID: 133', '::1', '2026-04-19 14:31:25'),
(295, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-19 14:31:26'),
(296, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-22 13:45:27'),
(297, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:45:28'),
(298, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-22 13:45:33'),
(299, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-22 13:46:32'),
(300, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-22 13:46:35'),
(301, 19, 'LOGIN', 'User logged in successfully', '::1', '2026-04-22 13:46:44'),
(302, 19, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:46:44'),
(303, 19, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-22 13:46:50'),
(304, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-22 13:46:56'),
(305, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:46:56'),
(306, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-22 13:46:58'),
(307, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-22 13:47:01'),
(308, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:47:23'),
(309, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-22 13:47:34'),
(310, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-22 13:47:39'),
(311, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:47:39'),
(312, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-22 13:47:43'),
(313, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-22 13:47:43'),
(314, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-22 13:47:44'),
(315, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-22 13:47:44'),
(316, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-22 13:47:44'),
(317, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-22 13:47:45'),
(318, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-22 13:47:45'),
(319, 9, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: NEW [Contracts & Agreements]', '::1', '2026-04-22 13:49:14'),
(320, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:49:16'),
(321, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n3', '::1', '2026-04-22 13:49:17'),
(322, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:56:04'),
(323, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:56:37'),
(324, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-22 13:56:43'),
(325, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:56:47'),
(326, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 13:57:12'),
(327, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-22 23:53:39'),
(328, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 23:53:39'),
(329, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-22 23:53:42'),
(330, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-22 23:53:51'),
(331, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 23:53:51'),
(332, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-22 23:53:55'),
(333, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-22 23:53:57'),
(334, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 175', '::1', '2026-04-22 23:54:02'),
(335, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-22 23:54:05'),
(336, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 179', '::1', '2026-04-22 23:54:06'),
(337, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-22 23:54:09'),
(338, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-22 23:54:11'),
(339, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 00:04:27'),
(340, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 00:05:14'),
(341, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 00:05:22'),
(342, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:05:29'),
(343, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 00:05:30'),
(344, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 00:05:33'),
(345, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:05:38'),
(346, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:05:42'),
(347, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:05:42'),
(348, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 00:05:46'),
(349, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 180', '::1', '2026-04-23 00:05:48'),
(350, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 180', '::1', '2026-04-23 00:05:53'),
(351, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:05:56'),
(352, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:06:01'),
(353, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:06:01'),
(354, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 00:06:17'),
(355, 3, 'CREATE_PO', 'Created new PO: PO-2026-0003 mapped to PR ID: 180', '::1', '2026-04-23 00:06:38'),
(356, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:06:41'),
(357, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:06:45'),
(358, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:06:45'),
(359, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 00:06:48'),
(360, 9, 'APPROVE_PO', 'Advanced PO 119 to GM-Approved', '::1', '2026-04-23 00:06:54'),
(361, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:06:59'),
(362, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:07:04'),
(363, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:07:04'),
(364, 8, 'APPROVE_PO', 'Advanced PO 119 to Finance-Approved', '::1', '2026-04-23 00:07:12'),
(365, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:07:16'),
(366, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:07:22'),
(367, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:07:22'),
(368, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:08:15'),
(369, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:08:19'),
(370, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:08:19'),
(371, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 00:08:21'),
(372, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:19:17'),
(373, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:19:17'),
(374, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:19:24'),
(375, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:19:24'),
(376, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:45:09'),
(377, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 00:45:19'),
(378, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 00:45:19'),
(379, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 00:46:30'),
(380, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 00:56:56'),
(381, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 05:35:54'),
(382, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 05:35:54'),
(383, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 05:36:23'),
(384, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 05:36:36'),
(385, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 05:56:03'),
(386, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 05:56:03'),
(387, 9, 'ARCHIVE_FILE', 'Archived Document ID: 1', '::1', '2026-04-23 05:57:31'),
(388, 9, 'PRINT_DOC', 'Printed document: PO #PO-202511-5000', '::1', '2026-04-23 05:57:43'),
(389, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 05:58:00'),
(390, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 05:59:24'),
(391, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:00:09'),
(392, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:00:19'),
(393, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:00:19'),
(394, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 06:00:22'),
(395, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 06:02:55'),
(396, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 181', '::1', '2026-04-23 06:03:00'),
(397, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 06:03:06'),
(398, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:03:11'),
(399, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:03:22'),
(400, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:03:53'),
(401, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:03:53'),
(402, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:04:36'),
(403, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:04:49'),
(404, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:05:59'),
(405, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:06:07'),
(406, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:06:15'),
(407, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:06:24'),
(408, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:06:32'),
(409, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:06:50'),
(410, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:07:19'),
(411, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:07:46'),
(412, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:08:00'),
(413, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 06:08:32'),
(414, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 181', '::1', '2026-04-23 06:08:37'),
(415, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 181', '::1', '2026-04-23 06:08:50'),
(416, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:08:55'),
(417, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:09:10'),
(418, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:09:10'),
(419, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 06:09:12'),
(420, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 06:09:15'),
(421, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:09:25'),
(422, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:09:49'),
(423, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:09:50'),
(424, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 06:09:51'),
(425, 3, 'CREATE_PO', 'Created new PO: PO-2026-0004 mapped to PR ID: 181', '::1', '2026-04-23 06:11:04'),
(426, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:11:08'),
(427, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:11:12'),
(428, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:11:12'),
(429, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:11:51'),
(430, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:11:51'),
(431, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:11:54'),
(432, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:11:59'),
(433, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:11:59'),
(434, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:12:13'),
(435, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:12:13'),
(436, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:12:34'),
(437, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:12:34'),
(438, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:12:49'),
(439, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:12:49'),
(440, 12, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:13:02'),
(441, 12, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:13:02'),
(442, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:13:12'),
(443, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:13:12'),
(444, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 06:13:18'),
(445, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:14:07'),
(446, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:15:08'),
(447, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 06:15:15'),
(448, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 06:15:27'),
(449, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:15:57'),
(450, 2, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n2', '::1', '2026-04-23 06:16:14'),
(451, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:16:18'),
(452, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:16:28'),
(453, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:16:33'),
(454, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:16:33'),
(455, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:16:53'),
(456, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n2', '::1', '2026-04-23 06:16:54'),
(457, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-23 06:17:44'),
(458, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n2', '::1', '2026-04-23 06:17:48'),
(459, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 06:18:31'),
(460, 9, 'APPROVE_PO', 'Advanced PO 120 to GM-Approved', '::1', '2026-04-23 06:18:44'),
(461, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:19:25'),
(462, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:19:42'),
(463, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:19:42'),
(464, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:19:46'),
(465, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:19:50'),
(466, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:19:50'),
(467, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:21:48'),
(468, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 06:21:54'),
(469, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:21:54'),
(470, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 06:21:55'),
(471, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 06:21:59'),
(472, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 06:22:43'),
(473, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:12:19'),
(474, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:12:19'),
(475, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 07:12:22'),
(476, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 07:12:50'),
(477, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:12:54'),
(478, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:13:00'),
(479, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:13:00'),
(480, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 07:13:01'),
(481, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 182', '::1', '2026-04-23 07:13:23'),
(482, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 182', '::1', '2026-04-23 07:13:26'),
(483, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:13:28'),
(484, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:13:33'),
(485, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:13:33'),
(486, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 07:13:35'),
(487, 3, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 182', '::1', '2026-04-23 07:13:37'),
(488, 3, 'CREATE_PO', 'Created new PO: PO-2026-0005 mapped to PR ID: 182', '::1', '2026-04-23 07:13:59'),
(489, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:14:03'),
(490, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:14:08'),
(491, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:14:08'),
(492, 9, 'APPROVE_PO', 'Advanced PO 121 to GM-Approved', '::1', '2026-04-23 07:14:15'),
(493, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:14:18'),
(494, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:14:27'),
(495, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:14:27'),
(496, 8, 'APPROVE_PO', 'Advanced PO 121 to Finance-Approved', '::1', '2026-04-23 07:14:32'),
(497, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:14:33'),
(498, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:14:39'),
(499, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:14:39'),
(500, 6, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 07:14:41'),
(501, 6, 'APPROVE_PO', 'Advanced PO 121 to President-Approved', '::1', '2026-04-23 07:14:46'),
(502, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:14:47'),
(503, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:14:53'),
(504, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:14:54'),
(505, 8, 'APPROVE_PO', 'Advanced PO 121 to Funded', '::1', '2026-04-23 07:15:00'),
(506, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:15:03'),
(507, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:15:39'),
(508, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:15:39'),
(509, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 07:15:40'),
(510, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-23 07:16:08'),
(511, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:16:09'),
(512, 20, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:16:16'),
(513, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:16:16'),
(514, 20, 'APPROVE_PO', 'Advanced PO 121 to Delivered', '::1', '2026-04-23 07:16:23'),
(515, 20, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:16:26'),
(516, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:16:32'),
(517, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:16:32'),
(518, 8, 'ADD_PAYMENT', 'Added payment of P10000 to PO 121', '::1', '2026-04-23 07:16:49'),
(519, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:17:53'),
(520, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:18:00'),
(521, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:18:00'),
(522, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 07:18:01'),
(523, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-23 07:18:13'),
(524, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 07:18:16'),
(525, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:22:06'),
(526, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:22:11'),
(527, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:22:11'),
(528, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:38:06'),
(529, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-23 07:38:17'),
(530, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-23 07:45:20'),
(531, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-23 07:45:25'),
(532, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:45:25'),
(533, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-23 07:45:31'),
(534, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-23 07:45:37'),
(535, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 05:15:47'),
(536, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 05:15:48'),
(537, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 05:15:51'),
(538, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-26 05:15:55'),
(539, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 05:15:56'),
(540, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-26 05:15:56'),
(541, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 05:15:57'),
(542, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-26 05:16:00'),
(543, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 05:30:31'),
(544, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-26 05:30:33'),
(545, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 05:30:35'),
(546, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 05:32:15'),
(547, 9, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: haha [Internal Memos]', '::1', '2026-04-26 05:33:34'),
(548, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 06:26:07'),
(549, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 06:26:11'),
(550, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:26:11'),
(551, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 06:27:17'),
(552, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 06:27:22'),
(553, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:27:22'),
(554, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-26 06:27:29'),
(555, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:27:42'),
(556, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 06:27:56'),
(557, 20, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 06:28:15'),
(558, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:28:16'),
(559, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:30:49'),
(560, 20, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 06:34:07'),
(561, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 06:34:11'),
(562, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:34:11'),
(563, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:34:14'),
(564, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:44:27'),
(565, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 06:44:33'),
(566, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-26 06:44:51'),
(567, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 06:44:52');
INSERT INTO `audit_logs` (`log_id`, `user_id`, `action_type`, `description`, `ip_address`, `timestamp`) VALUES
(568, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-26 06:44:57'),
(569, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:46:22'),
(570, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 06:46:37'),
(571, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 06:46:47'),
(572, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:46:47'),
(573, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:47:06'),
(574, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:48:48'),
(575, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:49:10'),
(576, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:53:18'),
(577, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:54:13'),
(578, 3, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: pooo [Purchase orders]', '::1', '2026-04-26 06:55:01'),
(579, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 06:55:44'),
(580, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 06:55:50'),
(581, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 06:55:50'),
(582, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-04-26 06:55:54'),
(583, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-26 06:55:54'),
(584, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 07:00:25'),
(585, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 07:00:29'),
(586, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 07:00:29'),
(587, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 07:20:53'),
(588, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 07:20:57'),
(589, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 07:20:58'),
(590, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 07:34:39'),
(591, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 07:34:44'),
(592, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 07:34:44'),
(593, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 07:50:38'),
(594, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 07:50:53'),
(595, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:02:51'),
(596, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:05:19'),
(597, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:10:16'),
(598, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:19:01'),
(599, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:19:14'),
(600, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:19:19'),
(601, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:19:19'),
(602, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:19:31'),
(603, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:19:37'),
(604, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:19:37'),
(605, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:19:43'),
(606, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:19:48'),
(607, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:19:48'),
(608, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:20:03'),
(609, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:20:08'),
(610, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:20:08'),
(611, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:20:37'),
(612, 20, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:20:51'),
(613, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:20:51'),
(614, 20, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:20:57'),
(615, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:21:07'),
(616, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:21:07'),
(617, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:22:39'),
(618, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:22:45'),
(619, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:22:45'),
(620, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:29:19'),
(621, 2, 'PAGE_VIEW', 'Viewed inner tab: System Operations', '::1', '2026-04-26 11:30:37'),
(622, 2, 'PAGE_VIEW', 'Viewed inner tab: General Workspace', '::1', '2026-04-26 11:30:45'),
(623, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:30:53'),
(624, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:30:57'),
(625, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:30:57'),
(626, 3, 'PAGE_VIEW', 'Viewed inner tab: Department Workspace', '::1', '2026-04-26 11:31:02'),
(627, 3, 'PAGE_VIEW', 'Viewed inner tab: My Analytics', '::1', '2026-04-26 11:31:04'),
(628, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:31:13'),
(629, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:31:25'),
(630, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:31:25'),
(631, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-26 11:31:36'),
(632, 9, 'PAGE_VIEW', 'Viewed inner tab: Executive Workspace', '::1', '2026-04-26 11:31:38'),
(633, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:31:40'),
(634, 20, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:31:52'),
(635, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:31:52'),
(636, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:32:29'),
(637, 20, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:32:33'),
(638, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:32:40'),
(639, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:32:40'),
(640, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-26 11:32:44'),
(641, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-26 11:32:49'),
(642, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-26 11:32:50'),
(643, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-26 11:32:53'),
(644, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-26 11:37:01'),
(645, 9, 'PAGE_VIEW', 'Viewed inner tab: Department Performance', '::1', '2026-04-26 11:37:12'),
(646, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-26 11:37:14'),
(647, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 03:06:36'),
(648, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 03:06:37'),
(649, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 03:07:32'),
(650, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-28 05:06:39'),
(651, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 05:06:47'),
(652, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:06:47'),
(653, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:06:56'),
(654, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:07:27'),
(655, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:07:43'),
(656, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 05:08:08'),
(657, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:08:08'),
(658, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-28 05:30:34'),
(659, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 05:30:39'),
(660, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:30:39'),
(661, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 05:30:44'),
(662, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-04-28 05:30:48'),
(663, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 09:12:13'),
(664, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:12:13'),
(665, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:12:22'),
(666, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:15:47'),
(667, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:15:55'),
(668, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:16:04'),
(669, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:16:06'),
(670, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-28 09:16:18'),
(671, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:16:19'),
(672, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:18:08'),
(673, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 09:54:59'),
(674, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-28 10:02:03'),
(675, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 10:02:22'),
(676, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:02:22'),
(677, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:02:29'),
(678, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:02:43'),
(679, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-28 10:02:44'),
(680, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 10:02:54'),
(681, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:02:54'),
(682, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-28 10:02:58'),
(683, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-28 10:03:00'),
(684, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-28 10:03:31'),
(685, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 10:03:37'),
(686, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:03:37'),
(687, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-28 10:07:47'),
(688, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-28 10:07:54'),
(689, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:07:54'),
(690, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-28 10:08:07'),
(691, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-30 06:18:54'),
(692, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:18:55'),
(693, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-30 06:29:04'),
(694, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-30 06:29:07'),
(695, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:29:07'),
(696, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-30 06:29:10'),
(697, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:29:28'),
(698, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-04-30 06:29:29'),
(699, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Rejected\'', '::1', '2026-04-30 06:29:31'),
(700, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Approved\'', '::1', '2026-04-30 06:29:32'),
(701, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Pending\'', '::1', '2026-04-30 06:29:34'),
(702, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'all\'', '::1', '2026-04-30 06:29:35'),
(703, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory | Applied status filter: \'Approved\'', '::1', '2026-04-30 06:29:41'),
(704, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:29:43'),
(705, 9, 'PAGE_VIEW', 'Viewed inner tab: Retention Alerts\n1', '::1', '2026-04-30 06:29:45'),
(706, 9, 'PAGE_VIEW', 'Viewed inner tab: Financial & Analytics Overview', '::1', '2026-04-30 06:29:45'),
(707, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:43:37'),
(708, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:49:22'),
(709, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 06:59:23'),
(710, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-30 07:00:29'),
(711, 20, 'LOGIN', 'User logged in successfully', '::1', '2026-04-30 07:00:45'),
(712, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:00:45'),
(713, 20, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: fee [Service Fee]', '::1', '2026-04-30 07:01:17'),
(714, 20, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-30 07:01:26'),
(715, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-04-30 07:01:30'),
(716, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:01:30'),
(717, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:01:44'),
(718, 9, 'RESTORE_FILE', 'Restored Document ID: 135', '::1', '2026-04-30 07:03:23'),
(719, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:10:02'),
(720, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:11:54'),
(721, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-30 07:19:27'),
(722, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-04-30 07:19:31'),
(723, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:19:31'),
(724, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-04-30 07:19:35'),
(725, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-04-30 07:19:39'),
(726, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-04-30 07:19:39'),
(727, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:41:19'),
(728, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:41:20'),
(729, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-02 11:42:22'),
(730, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-02 11:42:33'),
(731, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:42:44'),
(732, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:45:21'),
(733, 3, 'CREATE_PO', 'Created new PO: PO-2026-0006 mapped to PR ID: ', '::1', '2026-05-02 11:46:33'),
(734, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:46:45'),
(735, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:46:50'),
(736, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:46:50'),
(737, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-02 11:46:54'),
(738, 9, 'APPROVE_PO', 'Advanced PO 122 to GM-Approved', '::1', '2026-05-02 11:47:04'),
(739, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:47:12'),
(740, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:47:17'),
(741, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:47:17'),
(742, 8, 'APPROVE_PO', 'Advanced PO 122 to Finance-Approved', '::1', '2026-05-02 11:47:25'),
(743, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:47:28'),
(744, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:47:34'),
(745, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:47:34'),
(746, 6, 'APPROVE_PO', 'Advanced PO 122 to President-Approved', '::1', '2026-05-02 11:47:40'),
(747, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:47:43'),
(748, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:47:48'),
(749, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:47:48'),
(750, 8, 'APPROVE_PO', 'Advanced PO 122 to Funded', '::1', '2026-05-02 11:47:53'),
(751, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:47:58'),
(752, 20, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:48:06'),
(753, 20, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:48:06'),
(754, 20, 'APPROVE_PO', 'Advanced PO 122 to Delivered', '::1', '2026-05-02 11:48:16'),
(755, 20, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:48:18'),
(756, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:48:25'),
(757, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:48:25'),
(758, 8, 'ADD_PAYMENT', 'Added payment of P5000 to PO 122', '::1', '2026-05-02 11:48:40'),
(759, 8, 'ADD_PAYMENT', 'Added payment of P5000 to PO 122', '::1', '2026-05-02 11:48:51'),
(760, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:48:57'),
(761, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-02 11:49:01'),
(762, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-02 11:49:05'),
(763, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:49:06'),
(764, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-02 11:49:49'),
(765, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 13:24:01'),
(766, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 13:24:01'),
(767, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 13:47:38'),
(768, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 13:47:59'),
(769, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 13:56:38'),
(770, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-17 13:56:57'),
(771, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 13:57:04'),
(772, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:05:21'),
(773, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:05:41'),
(774, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:06:07'),
(775, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:06:13'),
(776, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:06:13'),
(777, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:06:38'),
(778, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:06:44'),
(779, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:06:47'),
(780, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:06:51'),
(781, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:06:51'),
(782, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:16:05'),
(783, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:16:07'),
(784, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:17:19'),
(785, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:17:19'),
(786, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:17:21'),
(787, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:19:31'),
(788, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:19:32'),
(789, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:19:38'),
(790, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:19:44'),
(791, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:19:44'),
(792, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:19:51'),
(793, 6, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:20:00'),
(794, 6, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:20:00'),
(795, 6, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:20:07'),
(796, 8, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:20:14'),
(797, 8, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:20:14'),
(798, 8, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:20:18'),
(799, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:20:31'),
(800, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:20:31'),
(801, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-05-17 14:20:34'),
(802, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:20:38'),
(803, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:20:44'),
(804, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:20:44'),
(805, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:20:52'),
(806, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:21:55'),
(807, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:21:55'),
(808, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:24:27'),
(809, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:24:33'),
(810, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:24:41'),
(811, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:24:41'),
(812, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:27:08'),
(813, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:27:23'),
(814, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:27:23'),
(815, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:30:38'),
(816, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-17 14:31:39'),
(817, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:31:40'),
(818, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-17 14:31:44'),
(819, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:31:55'),
(820, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:35:56'),
(821, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:36:07'),
(822, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:36:41'),
(823, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:40:12'),
(824, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:40:12'),
(825, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:41:05'),
(826, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-17 14:41:06'),
(827, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-17 14:42:10'),
(828, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-17 14:42:10'),
(829, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:06:31'),
(830, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:24:19'),
(831, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-19 13:25:02'),
(832, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:25:17'),
(833, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-19 13:25:37'),
(834, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-19 13:25:47'),
(835, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:25:47'),
(836, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-19 13:25:51'),
(837, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:26:07'),
(838, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-19 13:28:45'),
(839, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:28:45'),
(840, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:28:58'),
(841, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:30:19'),
(842, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:31:26'),
(843, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:31:53'),
(844, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:32:16'),
(845, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:34:00'),
(846, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-19 13:34:05'),
(847, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-19 13:34:11'),
(848, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:34:11'),
(849, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-19 13:34:42'),
(850, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-19 13:34:48'),
(851, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:34:49'),
(852, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-19 13:34:55'),
(853, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-19 13:35:00'),
(854, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:35:00'),
(855, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:38:52'),
(856, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:41:41'),
(857, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 13:41:57'),
(858, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-19 13:41:59'),
(859, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-19 13:42:00'),
(860, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 13:42:01'),
(861, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-19 13:42:01'),
(862, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 13:42:11'),
(863, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-19 13:42:11'),
(864, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 13:42:12'),
(865, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-19 13:42:13'),
(866, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 13:42:14'),
(867, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-19 13:42:14'),
(868, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-19 13:42:19'),
(869, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 13:42:22'),
(870, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-19 13:42:22'),
(871, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-19 13:42:28'),
(872, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:42:33'),
(873, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:44:39'),
(874, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:45:03'),
(875, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:45:09'),
(876, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:45:24'),
(877, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:46:08'),
(878, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:55:30'),
(879, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:56:26'),
(880, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 13:56:31'),
(881, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 14:03:17'),
(882, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 14:16:04'),
(883, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-19 14:16:16'),
(884, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-19 14:16:18'),
(885, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-19 14:16:20'),
(886, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 14:16:22'),
(887, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 14:16:35'),
(888, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-19 14:26:58'),
(889, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-20 11:46:01'),
(890, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 11:46:01'),
(891, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:00:53'),
(892, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-20 12:00:56'),
(893, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-20 12:00:59'),
(894, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-20 12:01:06'),
(895, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:01:06'),
(896, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:01:09'),
(897, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-20 12:01:12'),
(898, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-20 12:01:21'),
(899, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-20 12:01:27'),
(900, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:01:27'),
(901, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-20 12:01:36'),
(902, 3, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 182', '::1', '2026-05-20 12:02:06'),
(903, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-20 12:02:09'),
(904, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:09:18'),
(905, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-20 12:09:22'),
(906, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-20 12:09:28'),
(907, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:09:28'),
(908, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:09:32'),
(909, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:09:50'),
(910, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-20 12:10:35'),
(911, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:10:39'),
(912, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:10:44'),
(913, 16, 'CREATE_QUOTATION', 'Encoded new Quotation #QTN-2025-0001 for client ccc. Waiting for Client PO.', '::1', '2026-05-20 12:10:59'),
(914, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:10:59'),
(915, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:11:04'),
(916, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-20 12:11:11'),
(917, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:11:12'),
(918, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:11:21'),
(919, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:12:15'),
(920, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:14:38'),
(921, 16, 'RECEIVE_CLIENT_PO', 'Received and encoded Client PO #PO-CCC-0001 for Quotation ID: 1.', '::1', '2026-05-20 12:14:54'),
(922, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:14:54'),
(923, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:14:57'),
(924, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:15:44'),
(925, 16, 'CREATE_QUOTATION', 'Encoded new Quotation #dff342 for client minhs. Waiting for Client PO.', '::1', '2026-05-20 12:16:58'),
(926, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:16:58'),
(927, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:27:09'),
(928, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:27:11'),
(929, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:27:23'),
(930, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-20 12:27:26'),
(931, 16, 'CREATE_QUOTATION', 'Created detailed Quotation #QTN-2026-0001 for client cccc. Waiting for Client PO.', '::1', '2026-05-20 12:27:55'),
(932, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:27:55'),
(933, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:27:58'),
(934, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:27:59'),
(935, 16, 'RECEIVE_CLIENT_PO', 'Received and encoded Client PO #PO-CCC-0002 for Quotation ID: 3.', '::1', '2026-05-20 12:28:10'),
(936, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:28:10'),
(937, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-20 12:28:11'),
(938, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:28:50'),
(939, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-20 12:28:51'),
(940, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-20 12:29:00'),
(941, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:32:52'),
(942, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:33:16'),
(943, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-20 12:34:27'),
(944, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 10:36:42'),
(945, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 10:36:49'),
(946, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:37:38'),
(947, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 10:37:45'),
(948, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:37:52'),
(949, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 10:37:54'),
(950, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:37:56'),
(951, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 10:37:59'),
(952, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:38:02'),
(953, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:38:09'),
(954, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:38:14'),
(955, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:38:15'),
(956, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:38:16'),
(957, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:38:18'),
(958, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 10:38:25'),
(959, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:38:28'),
(960, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 10:38:35'),
(961, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:39:11'),
(962, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 10:39:11'),
(963, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:39:13'),
(964, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:40:48'),
(965, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:45:01'),
(966, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:45:14'),
(967, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:45:15'),
(968, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:45:20'),
(969, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:45:20'),
(970, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:45:27'),
(971, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:45:29'),
(972, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:45:39'),
(973, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:45:41'),
(974, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:45:44'),
(975, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:45:47'),
(976, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:45:51'),
(977, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 10:45:52'),
(978, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 10:45:54'),
(979, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:46:04'),
(980, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 10:46:47'),
(981, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:47:47'),
(982, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 10:58:37'),
(983, 16, 'RECEIVE_CLIENT_PO', 'Received client approval (Email Confirmation) with Auto-Generated Ref: CPO-2026-0001 for Quotation ID: 2.', '::1', '2026-05-21 11:09:55'),
(984, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:09:55'),
(985, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:17:49'),
(986, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:21:24'),
(987, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 11:21:59'),
(988, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:22:00'),
(989, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 11:22:34'),
(990, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:22:36'),
(991, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 11:22:37'),
(992, 16, 'CREATE_QUOTATION', 'Created detailed Quotation #QTN-2026-0002 for client vsdgsgs. Waiting for Client PO.', '::1', '2026-05-21 11:22:45'),
(993, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:22:45'),
(994, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:23:09'),
(995, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:23:56'),
(996, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:24:18'),
(997, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:24:19'),
(998, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:24:25'),
(999, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:24:36'),
(1000, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:24:42'),
(1001, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:24:44'),
(1002, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:24:45'),
(1003, 16, 'RECEIVE_CLIENT_PO', 'Received client approval (Chat/Viber Agreement) with Auto-Generated Ref: CPO-2026-0002 for Quotation ID: 4.', '::1', '2026-05-21 11:25:00'),
(1004, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:25:00'),
(1005, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:25:02'),
(1006, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:25:57'),
(1007, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:26:21'),
(1008, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:26:53'),
(1009, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:27:19'),
(1010, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:30:08'),
(1011, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:30:43'),
(1012, 16, 'PAGE_VIEW', 'Navigated to Create Quotation Module', '::1', '2026-05-21 11:30:44'),
(1013, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:31:11'),
(1014, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:31:12'),
(1015, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:32:42'),
(1016, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:32:53'),
(1017, 16, 'PAGE_VIEW', 'Opened Create Purchase Request Form', '::1', '2026-05-21 11:33:48'),
(1018, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:34:05'),
(1019, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:34:15'),
(1020, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:34:17'),
(1021, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:34:56'),
(1022, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:34:59'),
(1023, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:35:00'),
(1024, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:35:04'),
(1025, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:35:05'),
(1026, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:35:09'),
(1027, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:37:14'),
(1028, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:37:35'),
(1029, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 11:38:00'),
(1030, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:38:01'),
(1031, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:38:01'),
(1032, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-21 11:38:09'),
(1033, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 11:38:20'),
(1034, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 11:38:20'),
(1035, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:38:21'),
(1036, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:38:23'),
(1037, 9, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:38:38'),
(1038, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-21 11:38:40'),
(1039, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 11:38:45'),
(1040, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 11:38:45'),
(1041, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:38:47'),
(1042, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:38:49'),
(1043, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:38:53'),
(1044, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-21 11:38:58'),
(1045, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 11:39:04'),
(1046, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 11:39:04'),
(1047, 3, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:39:06'),
(1048, 3, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:39:08'),
(1049, 3, 'CREATE_PO', 'Created new PO: PO-2026-0007 mapped to PR ID: 183', '::1', '2026-05-21 11:40:13'),
(1050, 3, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-21 11:40:36'),
(1051, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 11:40:41'),
(1052, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 11:40:41'),
(1053, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:40:43'),
(1054, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:41:41'),
(1055, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:43:29'),
(1056, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:43:30'),
(1057, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:43:31'),
(1058, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:46:26'),
(1059, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:46:47'),
(1060, 16, 'VIEW_RECORD', 'Viewed details of Purchase Request ID: 183', '::1', '2026-05-21 11:46:52'),
(1061, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-21 11:47:14'),
(1062, 16, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 11:47:19'),
(1063, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-21 11:47:20'),
(1064, 3, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 11:47:27'),
(1065, 3, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 11:47:27'),
(1066, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 12:10:21'),
(1067, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-21 12:10:21'),
(1068, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:10:21'),
(1069, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:19:26'),
(1070, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 12:20:15'),
(1071, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 12:20:18'),
(1072, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:20:20'),
(1073, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:20:24'),
(1074, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:27:09'),
(1075, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:27:30'),
(1076, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:35:44'),
(1077, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 12:35:45'),
(1078, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:35:47'),
(1079, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 12:35:50'),
(1080, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:35:51'),
(1081, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:39:35'),
(1082, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:44:00'),
(1083, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 12:44:20'),
(1084, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:44:24'),
(1085, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 12:47:26'),
(1086, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:36:37'),
(1087, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:37:01'),
(1088, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:40:06'),
(1089, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:43:59'),
(1090, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:48:54'),
(1091, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-21 13:49:30'),
(1092, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:49:34'),
(1093, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:49:36'),
(1094, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-21 13:49:38'),
(1095, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 11:58:34'),
(1096, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 11:59:00'),
(1097, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:12:51'),
(1098, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:18:15'),
(1099, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-22 12:18:40'),
(1100, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-22 12:18:44'),
(1101, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-22 12:18:45'),
(1102, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:21:54'),
(1103, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:29:06'),
(1104, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-22 12:29:52'),
(1105, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-22 12:30:49'),
(1106, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:30:49'),
(1107, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:39:20'),
(1108, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 12:45:12'),
(1109, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:15:07'),
(1110, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-22 13:15:44'),
(1111, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:15:48'),
(1112, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:16:18'),
(1113, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:26:08'),
(1114, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-22 13:26:28'),
(1115, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:26:31'),
(1116, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:26:45'),
(1117, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:26:53'),
(1118, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:26:56'),
(1119, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:26:59'),
(1120, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:27:32'),
(1121, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-22 13:33:29'),
(1122, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 11:30:24'),
(1123, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 11:59:01'),
(1124, 9, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: wrhhwr [Job Orders]', '::1', '2026-05-23 11:59:52'),
(1125, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:00:40'),
(1126, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-23 12:00:40'),
(1127, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-23 12:00:58'),
(1128, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-23 12:01:03'),
(1129, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:01:04'),
(1130, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-05-23 12:01:06'),
(1131, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-05-23 12:01:12'),
(1132, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:01:30'),
(1133, 2, 'UPLOAD_RECORD', 'Indexed and uploaded Official Record: fyuetud [Meeting Minutes]', '::1', '2026-05-23 12:01:59'),
(1134, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:02:01');
INSERT INTO `audit_logs` (`log_id`, `user_id`, `action_type`, `description`, `ip_address`, `timestamp`) VALUES
(1135, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-23 12:02:07'),
(1136, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-23 12:02:12'),
(1137, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:02:12'),
(1138, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS\n2', '::1', '2026-05-23 12:02:14'),
(1139, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:10:58'),
(1140, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:11:15'),
(1141, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:30:32'),
(1142, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:33:18'),
(1143, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:35:17'),
(1144, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS\n2', '::1', '2026-05-23 12:35:18'),
(1145, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:35:50'),
(1146, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:38:34'),
(1147, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-23 12:38:35'),
(1148, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-23 12:38:38'),
(1149, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:49:42'),
(1150, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 12:49:49'),
(1151, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:04:12'),
(1152, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:04:27'),
(1153, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:04:31'),
(1154, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-23 13:05:04'),
(1155, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:05:09'),
(1156, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:05:22'),
(1157, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:11:28'),
(1158, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-23 13:12:02'),
(1159, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 09:02:38'),
(1160, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 09:15:41'),
(1161, 9, 'UPDATE_VERSION', 'Uploaded v2.0 for Doc ID: 138', '::1', '2026-05-24 10:40:39'),
(1162, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 10:57:50'),
(1163, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS\n1', '::1', '2026-05-24 10:57:52'),
(1164, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 11:50:00'),
(1165, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS\n1', '::1', '2026-05-24 12:00:59'),
(1166, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-24 12:01:00'),
(1167, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:11:52'),
(1168, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-24 12:17:04'),
(1169, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:17:06'),
(1170, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:29:54'),
(1171, 9, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:30:01'),
(1172, 9, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:30:01'),
(1173, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-24 12:30:04'),
(1174, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-24 12:30:08'),
(1175, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:30:08'),
(1176, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-05-24 12:30:26'),
(1177, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:30:32'),
(1178, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:31:17'),
(1179, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-05-24 12:31:20'),
(1180, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 12:31:29'),
(1181, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:31:40'),
(1182, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:35:48'),
(1183, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:35:48'),
(1184, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:35:55'),
(1185, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:35:55'),
(1186, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:35:57'),
(1187, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:35:57'),
(1188, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:38:54'),
(1189, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:38:54'),
(1190, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:38:59'),
(1191, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:38:59'),
(1192, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:39:13'),
(1193, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:39:13'),
(1194, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:43:29'),
(1195, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:43:29'),
(1196, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:46:01'),
(1197, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:47:58'),
(1198, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:47:58'),
(1199, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:48:48'),
(1200, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:48:48'),
(1201, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:48:55'),
(1202, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:50:50'),
(1203, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:51:56'),
(1204, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:52:22'),
(1205, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:53:04'),
(1206, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:53:18'),
(1207, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-24 12:53:44'),
(1208, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-24 12:53:49'),
(1209, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-24 12:53:49'),
(1210, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-24 12:53:58'),
(1211, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-24 12:58:41'),
(1212, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-24 13:03:12'),
(1213, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-24 13:03:37'),
(1214, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:03:46'),
(1215, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:06:18'),
(1216, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:17:08'),
(1217, 2, 'PAGE_VIEW', 'Accessed User Management Control Panel', '::1', '2026-05-24 13:17:09'),
(1218, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:17:12'),
(1219, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:17:14'),
(1220, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:17:16'),
(1221, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-24 13:17:18'),
(1222, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 10:54:44'),
(1223, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 10:56:08'),
(1224, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-25 10:56:30'),
(1225, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-25 10:56:30'),
(1226, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-25 10:56:36'),
(1227, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 11:05:34'),
(1228, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 11:28:44'),
(1229, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '2026-05-25 11:29:05'),
(1230, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-25 11:29:37'),
(1231, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-25 11:31:53'),
(1232, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-25 11:31:58'),
(1233, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-25 11:31:59'),
(1234, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '2026-05-25 11:32:20'),
(1235, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 11:32:43'),
(1236, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777181614_INPUT.png', '::1', '2026-05-25 12:06:30'),
(1237, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-25 12:07:02'),
(1238, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-25 12:07:02'),
(1239, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-25 12:07:06'),
(1240, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-25 12:07:06'),
(1241, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-25 12:11:59'),
(1242, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-25 12:11:59'),
(1243, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-25 12:36:08'),
(1244, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-25 12:36:13'),
(1245, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 12:36:13'),
(1246, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-25 12:58:46'),
(1247, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-25 12:58:52'),
(1248, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-25 12:58:52'),
(1249, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-25 12:59:01'),
(1250, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-25 12:59:01'),
(1251, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '2026-05-25 12:59:09'),
(1252, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '2026-05-25 12:59:09'),
(1253, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-26 11:00:04'),
(1254, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-26 11:00:24'),
(1255, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-26 11:00:33'),
(1256, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-26 11:00:34'),
(1257, 16, 'PAGE_VIEW', 'Browsed Quotations Tracker Directory', '::1', '2026-05-26 11:24:01'),
(1258, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-26 11:26:17'),
(1259, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-26 11:26:25'),
(1260, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-26 11:26:26'),
(1261, 2, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-26 11:27:41'),
(1262, 16, 'LOGIN', 'User logged in successfully', '::1', '2026-05-26 11:27:49'),
(1263, 16, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-26 11:27:49'),
(1264, 16, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-26 11:29:51'),
(1265, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-26 11:29:56'),
(1266, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-26 11:29:56'),
(1267, 9, 'LOGOUT', 'User securely logged out of the system', '::1', '2026-05-26 11:34:54'),
(1268, 2, 'LOGIN', 'User logged in successfully', '::1', '2026-05-26 11:34:59'),
(1269, 2, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-26 11:34:59'),
(1270, 9, 'LOGIN', 'User logged in successfully', '::1', '2026-05-29 04:08:23'),
(1271, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-29 04:08:26'),
(1272, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-29 04:08:43'),
(1273, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-29 04:08:48'),
(1274, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-29 04:08:49'),
(1275, 9, 'PAGE_VIEW', 'Viewed inner tab: DEPARTMENT PERFORMANCE', '::1', '2026-05-29 04:08:49'),
(1276, 9, 'PAGE_VIEW', 'Viewed inner tab: RETENTION ALERTS', '::1', '2026-05-29 04:08:49'),
(1277, 9, 'PAGE_VIEW', 'Viewed inner tab: FINANCIAL & ANALYTICS OVERVIEW', '::1', '2026-05-29 04:08:51'),
(1278, 9, 'PAGE_VIEW', 'Browsed Purchase Requests Directory', '::1', '2026-05-29 04:09:27'),
(1279, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-29 04:09:30'),
(1280, 9, 'PAGE_VIEW', 'Navigated to Main Dashboard', '::1', '2026-05-29 04:14:10');

-- --------------------------------------------------------

--
-- Table structure for table `company_folders`
--

CREATE TABLE `company_folders` (
  `id` int(11) NOT NULL,
  `folder_name` varchar(100) NOT NULL,
  `parent_folder` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_folders`
--

INSERT INTO `company_folders` (`id`, `folder_name`, `parent_folder`) VALUES
(1, 'Blank Forms and Templates', NULL),
(2, 'Company Policies and Guidelines', NULL),
(3, 'Directories and Organizational Charts', NULL),
(4, 'General Memos and Announcements', NULL),
(5, 'General Manuals and FAQs', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `doc_id` int(11) NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `doc_type` varchar(50) DEFAULT 'Generic',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'Uncategorized',
  `policy_id` int(11) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `file_hash` char(64) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Active','Archived') DEFAULT 'Active',
  `disposition_status` enum('Pending','Ready for Disposition','Destroyed','Permanently Archived') DEFAULT 'Pending',
  `dss_recommendation` varchar(255) DEFAULT NULL,
  `current_version` varchar(10) DEFAULT '1.0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`doc_id`, `po_id`, `doc_type`, `file_name`, `file_path`, `category`, `policy_id`, `tags`, `expiry_date`, `file_hash`, `uploaded_by`, `uploaded_at`, `status`, `disposition_status`, `dss_recommendation`, `current_version`) VALUES
(1, 1, 'Official Record', 'SLA_DepEd_2025.pdf', 'uploads/SLA_DepEd_2025.pdf', 'Purchase Orders', NULL, 'SLA, Nov2025', '2026-11-01', 'hash1', 3, '2025-11-03 02:00:00', 'Archived', 'Pending', NULL, '1.0'),
(2, 2, 'Official Record', 'SLA_MakatiMed_2025.pdf', 'uploads/SLA_MakatiMed.pdf', 'Purchase Orders', NULL, 'SLA, Healthcare', '2026-11-05', 'hash2', 3, '2025-11-05 03:00:00', 'Active', 'Pending', NULL, '1.0'),
(3, 3, 'Official Record', 'SLA_BDO_2025.pdf', 'uploads/SLA_BDO.pdf', 'Purchase Orders', NULL, 'SLA, Bank', '2026-11-08', 'hash6', 3, '2025-11-08 05:00:00', 'Active', 'Pending', NULL, '1.0'),
(4, 4, 'Official Record', 'SLA_Ayala_2025.pdf', 'uploads/SLA_Ayala.pdf', 'Purchase Orders', NULL, 'SLA, RealEstate', '2026-11-10', 'hash7', 3, '2025-11-10 07:00:00', 'Active', 'Pending', NULL, '1.0'),
(5, 5, 'Official Record', 'SLA_Globe_2025.pdf', 'uploads/SLA_Globe.pdf', 'Purchase Orders', NULL, 'SLA, Telco', '2026-11-12', 'hash8', 3, '2025-11-12 08:30:00', 'Active', 'Pending', NULL, '1.0'),
(6, 6, 'Official Record', 'SLA_Jollibee_2025.pdf', 'uploads/SLA_Jollibee.pdf', 'Purchase Orders', NULL, 'SLA, Food', '2026-11-14', 'hash9', 3, '2025-11-14 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(7, 7, 'Official Record', 'SLA_SMC_2025.pdf', 'uploads/SLA_SMC.pdf', 'Purchase Orders', NULL, 'SLA, Conglomerate', '2026-11-17', 'hash10', 3, '2025-11-17 03:20:00', 'Active', 'Pending', NULL, '1.0'),
(8, 8, 'Official Record', 'SLA_SMPrime_2025.pdf', 'uploads/SLA_SMPrime.pdf', 'Purchase Orders', NULL, 'SLA, RealEstate', '2026-11-19', 'hash12', 3, '2025-11-19 06:40:00', 'Active', 'Pending', NULL, '1.0'),
(9, 9, 'Official Record', 'SLA_PLDT_2025.pdf', 'uploads/SLA_PLDT.pdf', 'Purchase Orders', NULL, 'SLA, Telco', '2026-11-21', 'hash13', 3, '2025-11-21 09:10:00', 'Active', 'Pending', NULL, '1.0'),
(10, 10, 'Official Record', 'SLA_ManilaWater_2025.pdf', 'uploads/SLA_ManilaWater.pdf', 'Purchase Orders', NULL, 'SLA, Utility', '2026-11-24', 'hash14', 3, '2025-11-24 01:45:00', 'Active', 'Pending', NULL, '1.0'),
(11, 11, 'Official Record', 'SLA_LGU_QC_2025.pdf', 'uploads/SLA_LGU_QC.pdf', 'Purchase Orders', NULL, 'SLA, Government', '2026-11-26', 'hash15', 3, '2025-11-26 04:00:00', 'Active', 'Pending', NULL, '1.0'),
(12, 12, 'Official Record', 'SLA_PAL_2025.pdf', 'uploads/SLA_PAL.pdf', 'Purchase Orders', NULL, 'SLA, Airline', '2026-11-28', 'hash16', 3, '2025-11-28 07:30:00', 'Active', 'Pending', NULL, '1.0'),
(13, 14, 'Official Record', 'SLA_CebuPac_2025.pdf', 'uploads/SLA_CebuPac.pdf', 'Purchase Orders', NULL, 'SLA, Airline', '2026-12-01', 'hash17', 3, '2025-12-01 03:00:00', 'Active', 'Pending', NULL, '1.0'),
(14, 15, 'Official Record', 'SLA_Metrobank_2025.pdf', 'uploads/SLA_Metrobank.pdf', 'Purchase Orders', NULL, 'SLA, Bank', '2026-12-03', 'hash18', 3, '2025-12-03 06:20:00', 'Active', 'Pending', NULL, '1.0'),
(15, 16, 'Official Record', 'SLA_Robinsons_2025.pdf', 'uploads/SLA_Robinsons.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-05', 'hash21', 3, '2025-12-05 08:45:00', 'Active', 'Pending', NULL, '1.0'),
(16, 17, 'Official Record', 'SLA_Megaworld_2025.pdf', 'uploads/SLA_Megaworld.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-08', 'hash22', 3, '2025-12-08 02:30:00', 'Active', 'Pending', NULL, '1.0'),
(17, 18, 'Official Record', 'SLA_Puregold_2025.pdf', 'uploads/SLA_Puregold.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-10', 'hash23', 3, '2025-12-10 04:15:00', 'Active', 'Pending', NULL, '1.0'),
(18, 19, 'Official Record', 'SLA_GMA_2025.pdf', 'uploads/SLA_GMA.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-12', 'hash25', 3, '2025-12-12 07:00:00', 'Active', 'Pending', NULL, '1.0'),
(19, 20, 'Official Record', 'SLA_ABSCBN_2025.pdf', 'uploads/SLA_ABSCBN.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-15', 'hash26', 3, '2025-12-15 09:30:00', 'Active', 'Pending', NULL, '1.0'),
(20, 21, 'Official Record', 'SLA_Converge_2025.pdf', 'uploads/SLA_Converge.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-17', 'hash27', 3, '2025-12-17 01:20:00', 'Active', 'Pending', NULL, '1.0'),
(21, 22, 'Official Record', 'SLA_DITO_2025.pdf', 'uploads/SLA_DITO.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-19', 'hash29', 3, '2025-12-19 03:40:00', 'Active', 'Pending', NULL, '1.0'),
(22, 23, 'Official Record', 'SLA_NationalBookstore_2025.pdf', 'uploads/SLA_NBS.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-20', 'hash30', 3, '2025-12-20 06:10:00', 'Active', 'Pending', NULL, '1.0'),
(23, 24, 'Official Record', 'SLA_MercuryDrug_2025.pdf', 'uploads/SLA_Mercury.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-22', 'hash31', 3, '2025-12-22 08:50:00', 'Active', 'Pending', NULL, '1.0'),
(24, 25, 'Official Record', 'SLA_LBC_2025.pdf', 'uploads/SLA_LBC.pdf', 'Purchase Orders', NULL, 'SLA', '2026-12-26', 'hash33', 3, '2025-12-26 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(25, 27, 'Official Record', 'SLA_Shopee_2026.pdf', 'uploads/SLA_Shopee.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-05', 'hash34', 3, '2026-01-05 03:00:00', 'Active', 'Pending', NULL, '1.0'),
(26, 28, 'Official Record', 'SLA_Lazada_2026.pdf', 'uploads/SLA_Lazada.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-07', 'hash36', 3, '2026-01-07 06:30:00', 'Active', 'Pending', NULL, '1.0'),
(27, 29, 'Official Record', 'SLA_Foodpanda_2026.pdf', 'uploads/SLA_Foodpanda.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-09', 'hash37', 3, '2026-01-09 08:45:00', 'Active', 'Pending', NULL, '1.0'),
(28, 30, 'Official Record', 'SLA_Grab_2026.pdf', 'uploads/SLA_Grab.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-12', 'hash38', 3, '2026-01-12 02:15:00', 'Active', 'Pending', NULL, '1.0'),
(29, 31, 'Official Record', 'SLA_Angkas_2026.pdf', 'uploads/SLA_Angkas.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-14', 'hash40', 3, '2026-01-14 04:40:00', 'Active', 'Pending', NULL, '1.0'),
(30, 32, 'Official Record', 'SLA_BPI_2026.pdf', 'uploads/SLA_BPI.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-16', 'hash41', 3, '2026-01-16 07:10:00', 'Active', 'Pending', NULL, '1.0'),
(31, 33, 'Official Record', 'SLA_SecurityBank_2026.pdf', 'uploads/SLA_SecurityBank.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-19', 'hash42', 3, '2026-01-19 09:30:00', 'Active', 'Pending', NULL, '1.0'),
(32, 34, 'Official Record', 'SLA_UnionBank_2026.pdf', 'uploads/SLA_UnionBank.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-21', 'hash44', 3, '2026-01-21 01:50:00', 'Active', 'Pending', NULL, '1.0'),
(33, 35, 'Official Record', 'SLA_RCBC_2026.pdf', 'uploads/SLA_RCBC.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-23', 'hash45', 3, '2026-01-23 03:20:00', 'Active', 'Pending', NULL, '1.0'),
(34, 36, 'Official Record', 'SLA_ChinaBank_2026.pdf', 'uploads/SLA_ChinaBank.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-26', 'hash47', 3, '2026-01-26 06:00:00', 'Active', 'Pending', NULL, '1.0'),
(35, 37, 'Official Record', 'SLA_PNB_2026.pdf', 'uploads/SLA_PNB.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-28', 'hash48', 3, '2026-01-28 08:15:00', 'Active', 'Pending', NULL, '1.0'),
(36, 38, 'Official Record', 'SLA_Landbank_2026.pdf', 'uploads/SLA_Landbank.pdf', 'Purchase Orders', NULL, 'SLA', '2027-01-30', 'hash49', 3, '2026-01-30 02:40:00', 'Active', 'Pending', NULL, '1.0'),
(37, 40, 'Official Record', 'SLA_DLSU_2026.pdf', 'uploads/SLA_DLSU.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-02', 'hash51', 3, '2026-02-02 07:20:00', 'Active', 'Pending', NULL, '1.0'),
(38, 41, 'Official Record', 'SLA_Ateneo_2026.pdf', 'uploads/SLA_Ateneo.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-04', 'hash52', 3, '2026-02-04 09:00:00', 'Active', 'Pending', NULL, '1.0'),
(39, 42, 'Official Record', 'SLA_UST_2026.pdf', 'uploads/SLA_UST.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-06', 'hash54', 3, '2026-02-06 01:30:00', 'Active', 'Pending', NULL, '1.0'),
(40, 43, 'Official Record', 'SLA_UP_Diliman_2026.pdf', 'uploads/SLA_UP.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-09', 'hash55', 3, '2026-02-09 03:45:00', 'Active', 'Pending', NULL, '1.0'),
(41, 44, 'Official Record', 'SLA_Mapua_2026.pdf', 'uploads/SLA_Mapua.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-11', 'hash56', 3, '2026-02-11 06:15:00', 'Active', 'Pending', NULL, '1.0'),
(42, 45, 'Official Record', 'SLA_FEU_2026.pdf', 'uploads/SLA_FEU.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-13', 'hash57', 3, '2026-02-13 08:30:00', 'Active', 'Pending', NULL, '1.0'),
(43, 46, 'Official Record', 'SLA_PUP_2026.pdf', 'uploads/SLA_PUP.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-16', 'hash59', 3, '2026-02-16 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(44, 47, 'Official Record', 'SLA_StLukes_2026.pdf', 'uploads/SLA_StLukes.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-18', 'hash60', 3, '2026-02-18 04:20:00', 'Active', 'Pending', NULL, '1.0'),
(45, 48, 'Official Record', 'SLA_TheMedicalCity_2026.pdf', 'uploads/SLA_TMC.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-20', 'hash61', 3, '2026-02-20 07:40:00', 'Active', 'Pending', NULL, '1.0'),
(46, 49, 'Official Record', 'SLA_AsianHospital_2026.pdf', 'uploads/SLA_AsianHosp.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-23', 'hash62', 3, '2026-02-23 09:10:00', 'Active', 'Pending', NULL, '1.0'),
(47, 50, 'Official Record', 'SLA_PhilHealth_2026.pdf', 'uploads/SLA_PhilHealth.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-25', 'hash63', 3, '2026-02-25 01:50:00', 'Active', 'Pending', NULL, '1.0'),
(48, 51, 'Official Record', 'SLA_SSS_2026.pdf', 'uploads/SLA_SSS.pdf', 'Purchase Orders', NULL, 'SLA', '2027-02-26', 'hash65', 3, '2026-02-26 03:30:00', 'Active', 'Pending', NULL, '1.0'),
(49, 54, 'Official Record', 'SLA_DOH_2026.pdf', 'uploads/SLA_DOH.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-02', 'hash66', 3, '2026-03-02 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(50, 55, 'Official Record', 'SLA_DOST_2026.pdf', 'uploads/SLA_DOST.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-03', 'hash67', 3, '2026-03-03 04:20:00', 'Active', 'Pending', NULL, '1.0'),
(51, 56, 'Official Record', 'SLA_DICT_2026.pdf', 'uploads/SLA_DICT.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-04', 'hash69', 3, '2026-03-04 07:10:00', 'Active', 'Pending', NULL, '1.0'),
(52, 57, 'Official Record', 'SLA_DPWH_2026.pdf', 'uploads/SLA_DPWH.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-05', 'hash70', 3, '2026-03-05 09:30:00', 'Active', 'Pending', NULL, '1.0'),
(53, 58, 'Official Record', 'SLA_BIR_2026.pdf', 'uploads/SLA_BIR.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-06', 'hash71', 3, '2026-03-06 01:45:00', 'Active', 'Pending', NULL, '1.0'),
(54, 59, 'Official Record', 'SLA_Customs_2026.pdf', 'uploads/SLA_Customs.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-06', 'hash73', 3, '2026-03-06 03:15:00', 'Active', 'Pending', NULL, '1.0'),
(55, 60, 'Official Record', 'SLA_LTO_2026.pdf', 'uploads/SLA_LTO.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-07', 'hash74', 3, '2026-03-07 06:00:00', 'Active', 'Pending', NULL, '1.0'),
(56, 61, 'Official Record', 'SLA_NBI_2026.pdf', 'uploads/SLA_NBI.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-07', 'hash75', 3, '2026-03-07 08:20:00', 'Active', 'Pending', NULL, '1.0'),
(57, 62, 'Official Record', 'SLA_PNP_2026.pdf', 'uploads/SLA_PNP.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-08', 'hash76', 3, '2026-03-08 02:10:00', 'Active', 'Pending', NULL, '1.0'),
(58, 63, 'Official Record', 'SLA_AFP_2026.pdf', 'uploads/SLA_AFP.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-09', 'hash77', 3, '2026-03-09 04:30:00', 'Active', 'Pending', NULL, '1.0'),
(59, 64, 'Official Record', 'SLA_MMDA_2026.pdf', 'uploads/SLA_MMDA.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-09', 'hash79', 3, '2026-03-09 07:00:00', 'Active', 'Pending', NULL, '1.0'),
(60, 65, 'Official Record', 'SLA_NEDA_2026.pdf', 'uploads/SLA_NEDA.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-10', 'hash80', 3, '2026-03-10 09:15:00', 'Active', 'Pending', NULL, '1.0'),
(61, 66, 'Official Record', 'SLA_DOLE_2026.pdf', 'uploads/SLA_DOLE.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-11', 'hash81', 3, '2026-03-11 01:30:00', 'Active', 'Pending', NULL, '1.0'),
(62, 67, 'Official Record', 'SLA_DTI_2026.pdf', 'uploads/SLA_DTI.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-11', 'hash82', 3, '2026-03-11 03:45:00', 'Active', 'Pending', NULL, '1.0'),
(63, 68, 'Official Record', 'SLA_DFA_2026.pdf', 'uploads/SLA_DFA.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-12', 'hash84', 3, '2026-03-12 06:20:00', 'Active', 'Pending', NULL, '1.0'),
(64, 69, 'Official Record', 'SLA_DOT_2026.pdf', 'uploads/SLA_DOT.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-13', 'hash85', 3, '2026-03-13 08:50:00', 'Active', 'Pending', NULL, '1.0'),
(65, 70, 'Official Record', 'SLA_DENR_2026.pdf', 'uploads/SLA_DENR.pdf', 'Purchase Orders', NULL, 'SLA', '2027-03-13', 'hash86', 3, '2026-03-13 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(101, NULL, 'General Manuals at FAQs', 'Mayors_Permit_Q4_2025.pdf', 'uploads/Mayors_Permit_2025.pdf', 'Company policies and procedures', NULL, 'Permit, LGU', '2026-01-31', 'hash3', 2, '2025-11-10 01:00:00', 'Archived', 'Pending', NULL, '1.0'),
(102, NULL, 'General Manuals at FAQs', 'Q3_Financial_Statement.xlsx', 'uploads/Q3_FS_2025.xlsx', 'Company policies and procedures', NULL, 'Finance, Q3', NULL, 'hash4', 8, '2025-11-15 06:00:00', 'Active', 'Pending', NULL, '1.0'),
(103, NULL, 'Blank Forms and Templates', 'Holiday_Advisory_Nov_2025.pdf', 'uploads/Memo_Holiday_Nov.pdf', 'Company policies and procedures', NULL, 'HR, Holiday', NULL, 'hash5', 2, '2025-11-20 02:30:00', 'Active', 'Pending', NULL, '1.0'),
(104, NULL, 'Company Policies and Guidelines', 'IT_Security_Policy_Update.pdf', 'uploads/Memo_IT_Security.pdf', 'Company policies and procedures', NULL, 'IT, Security', NULL, 'hash11', 2, '2025-11-22 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(105, NULL, 'General Manuals at FAQs', 'Nov_2025_Expense_Report.pdf', 'uploads/Nov_Expense_Report.pdf', 'Company policies and procedures', NULL, 'Expense, Nov', NULL, 'hash19', 8, '2025-11-30 08:00:00', 'Active', 'Pending', NULL, '1.0'),
(106, NULL, 'General Manuals at FAQs', 'Barangay_Clearance_2025.pdf', 'uploads/Brgy_Clearance_2025.pdf', 'Company policies and procedures', NULL, 'Permit, Brgy', '2026-11-30', 'hash20', 2, '2025-11-30 00:00:00', 'Active', 'Pending', NULL, '1.0'),
(107, NULL, 'Blank Forms and Templates', 'Christmas_Party_Memo_2025.pdf', 'uploads/Memo_Christmas.pdf', 'Company policies and procedures', NULL, 'HR, Event', NULL, 'hash24', 2, '2025-12-11 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(108, NULL, 'General Manuals at FAQs', 'Dec_2025_Expense_Report.pdf', 'uploads/Dec_Expense_Report.pdf', 'Company policies and procedures', NULL, 'Expense, Dec', NULL, 'hash28', 8, '2025-12-18 06:00:00', 'Active', 'Pending', NULL, '1.0'),
(109, NULL, 'General Manuals at FAQs', 'SEC_Registration_Cert.pdf', 'uploads/SEC_Cert.pdf', 'Company policies and procedures', NULL, 'SEC, Permit', '2026-12-31', 'hash32', 2, '2025-12-23 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(110, NULL, 'General Manuals at FAQs', 'Annual_Financial_Report_2025.xlsx', 'uploads/AFR_2025.xlsx', 'Company policies and procedures', NULL, 'Finance, Annual', NULL, 'hash35', 8, '2025-12-28 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(111, NULL, 'Blank Forms and Templates', 'Year_End_Inventory_Memo.pdf', 'uploads/Memo_Inventory.pdf', 'Company policies and procedures', NULL, 'Warehouse, Inventory', NULL, 'hash39', 2, '2025-12-29 03:00:00', 'Active', 'Pending', NULL, '1.0'),
(112, NULL, 'General Manuals at FAQs', 'Mayors_Permit_2026_Renewal.pdf', 'uploads/Mayors_Permit_2026.pdf', 'Company policies and procedures', NULL, 'Permit, LGU', '2027-01-31', 'hash43', 2, '2026-01-20 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(113, NULL, 'Company Policies and Guidelines', 'Return_To_Office_Policy_Jan.pdf', 'uploads/Memo_RTO.pdf', 'Company policies and procedures', NULL, 'HR', NULL, 'hash46', 2, '2026-01-24 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(114, NULL, 'General Manuals at FAQs', 'Jan_2026_Expense_Report.pdf', 'uploads/Jan_Expense_Report.pdf', 'Company policies and procedures', NULL, 'Expense, Jan', NULL, 'hash50', 8, '2026-01-31 07:00:00', 'Active', 'Pending', NULL, '1.0'),
(115, NULL, 'General Manuals at FAQs', 'BIR_Certificate_of_Registration.pdf', 'uploads/BIR_COR_2026.pdf', 'Company policies and procedures', NULL, 'Permit, BIR', '2027-12-31', 'hash53', 2, '2026-02-05 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(116, NULL, 'Blank Forms and Templates', 'Performance_Review_Schedule_2026.pdf', 'uploads/Memo_Perf_Review.pdf', 'Company policies and procedures', NULL, 'HR', NULL, 'hash58', 2, '2026-02-14 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(117, NULL, 'General Manuals at FAQs', 'Feb_2026_Expense_Report.pdf', 'uploads/Feb_Expense_Report.pdf', 'Company policies and procedures', NULL, 'Expense, Feb', NULL, 'hash64', 8, '2026-02-26 06:00:00', 'Active', 'Pending', NULL, '1.0'),
(118, NULL, 'General Manuals at FAQs', 'Fire_Safety_Inspection_Cert_2026.pdf', 'uploads/Fire_Safety_2026.pdf', 'Company policies and procedures', NULL, 'Permit, Fire', '2027-03-03', 'hash68', 2, '2026-03-04 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(119, NULL, 'Company Policies and Guidelines', 'Data_Privacy_Act_Compliance.pdf', 'uploads/Memo_DPA.pdf', 'Company policies and procedures', NULL, 'Compliance', NULL, 'hash72', 2, '2026-03-07 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(120, NULL, 'General Manuals at FAQs', 'Q1_Preliminary_Financials_2026.xlsx', 'uploads/Q1_Prelim_FS.xlsx', 'Company policies and procedures', NULL, 'Finance, Q1', NULL, 'hash78', 8, '2026-03-10 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(121, NULL, 'General Manuals at FAQs', 'PhilGEPS_Platinum_Cert_2026.pdf', 'uploads/PhilGEPS_2026.pdf', 'Company policies and procedures', NULL, 'Permit, PhilGEPS', '2027-03-12', 'hash83', 2, '2026-03-12 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(122, NULL, 'Blank Forms and Templates', 'Holy_Week_Schedule_2026.pdf', 'uploads/Memo_HolyWeek.pdf', 'Company policies and procedures', NULL, 'HR, Holiday', NULL, 'hash87', 2, '2026-03-14 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(123, NULL, 'General Manuals at FAQs', 'Mar_2026_Expense_Report.pdf', 'uploads/Mar_Expense_Report.pdf', 'Company policies and procedures', NULL, 'Expense, Mar', NULL, 'hash91', 8, '2026-03-31 08:00:00', 'Active', 'Pending', NULL, '1.0'),
(124, NULL, 'General Manuals at FAQs', 'SQUASH_Business_License_2026.pdf', 'uploads/Business_License_2026.pdf', 'Company policies and procedures', NULL, 'Permit, License', '2027-04-01', 'hash103', 2, '2026-04-01 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(125, NULL, 'Blank Forms and Templates', 'Quarterly_All_Hands_Meeting.pdf', 'uploads/Memo_AllHands.pdf', 'Company policies and procedures', NULL, 'HR, Meeting', NULL, 'hash107', 2, '2026-04-04 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(126, NULL, 'General Manuals at FAQs', 'Q1_Final_Financial_Report_2026.xlsx', 'uploads/Q1_Final_FS.xlsx', 'Company policies and procedures', NULL, 'Finance, Q1 Final', NULL, 'hash111', 8, '2026-04-10 06:00:00', 'Active', 'Pending', NULL, '1.0'),
(127, NULL, 'General Manuals at FAQs', 'SQUASH_Sanitary_Permit_2026.pdf', 'uploads/Sanitary_Permit_2026.pdf', 'Company policies and procedures', NULL, 'Permit, Sanitary', '2027-04-15', 'hash116', 2, '2026-04-12 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(128, NULL, 'General Memos and Announcements', 'Leave_Application_Form.pdf', 'uploads/Leave_Application_Form.pdf', 'Company policies and procedures', NULL, 'HR, Form', NULL, 'hash128', 2, '2026-04-13 00:00:00', 'Active', 'Pending', NULL, '1.0'),
(129, NULL, 'General Memos and Announcements', 'Reimbursement_Form.pdf', 'uploads/Reimbursement_Form.pdf', 'Company policies and procedures', NULL, 'Finance, Form', NULL, 'hash129', 2, '2026-04-14 01:00:00', 'Active', 'Pending', NULL, '1.0'),
(130, NULL, 'General Memos and Announcements', 'Equipment_Borrow_Form.pdf', 'uploads/Equipment_Borrow_Form.pdf', 'Company policies and procedures', NULL, 'IT, Form', NULL, 'hash130', 2, '2026-04-15 02:00:00', 'Active', 'Pending', NULL, '1.0'),
(131, NULL, 'General Manuals at FAQs', 'Mayor\'s Permit', '../uploads/1776309728_Company-Profile-Fixie-Computer-Ventures.pdf', 'Company policies and procedures', NULL, '2026', '2026-05-01', 'ddc0be5e604b89bcd9fdc021119257ce6ae785f11a5e2a2f38a958955bdc6980', 9, '2026-04-16 03:22:08', 'Archived', 'Pending', NULL, '1.0'),
(132, NULL, 'General Manuals at FAQs', 'Permit', '../uploads/1776309809_PO Tracker User Manual.pdf', 'Company policies and procedures', NULL, 'permit, 2026', '2026-04-16', '5065bd7ec2e15dda5a8872a168f2f3b286f55495ab37a86e46525a4a22ee0c10', 9, '2026-04-16 03:23:29', 'Archived', 'Pending', NULL, '1.0'),
(133, 117, 'Quotation', 'CREATE PO DFD.png', 'uploads/1776604047_quote_1ed71ad6.png', 'Purchase Orders', NULL, NULL, NULL, '54010dac89a951f15557c84cbbc252edf7df963e47917e47ff1561f61164ee22', 3, '2026-04-19 13:07:27', 'Archived', 'Pending', NULL, '1.0'),
(134, 118, 'Quotation', 'lvl0 Proposed DFD (1).png', 'uploads/1776606043_quote_4449e2cb.png', 'Purchase Orders', NULL, NULL, NULL, '16e005624ea701978a46c1411605ca83d811075f2994ffaf6fdcab18ce2cbe6d', 3, '2026-04-19 13:40:43', 'Archived', 'Pending', NULL, '1.0'),
(135, NULL, 'General Manuals at FAQs', 'NEW', '../uploads/1776865754_Admin Flowchart.png', 'Company policies and procedures', NULL, '2026', '2026-04-22', '7f211fdbd3d01b76ffb45ec1c957c085eb4d29efa4d5b9fa571d6efccd470f63', 9, '2026-04-22 13:49:14', 'Archived', 'Pending', NULL, '1.0'),
(136, 121, 'Quotation', 'PR CREATION DFD (1).png', 'uploads/1776928439_quote_ceb3ce3b.png', 'Purchase Orders', NULL, NULL, NULL, '2e7d6b7b5220583de5fddfa490afde2c7266ca5c9d935f35818461708d53c99a', 3, '2026-04-23 07:13:59', 'Active', 'Pending', NULL, '1.0'),
(137, NULL, 'General Manuals at FAQs', 'haha', '../uploads/1777181614_INPUT.png', 'Company policies and procedures', NULL, '2026', NULL, 'd1e52da9d8a6db05a82008865d457521c5380a5e22e076c7266f803a668069cf', 9, '2026-04-26 05:33:34', 'Active', 'Pending', NULL, '1.0'),
(138, NULL, 'General Manuals at FAQs', 'Screenshot 3.png', 'uploads/1779619239_d1c8e529_Screenshot 3.png', 'Purchase orders', NULL, '2026', NULL, '0f81bfb2047892ef6caa6be1bad1665dab1ca51c1c430a02173631220bb00f94', 9, '2026-05-24 10:40:39', 'Active', 'Pending', NULL, '2.0'),
(139, NULL, 'Generic', 'fee', '../uploads/1777532477_Blank board.png', 'Service Fee', NULL, '2026', NULL, '34e2b51585da18494e67ca688598097b6ee190d947adc720baf2f153056ef24f', 20, '2026-04-30 07:01:17', 'Active', 'Pending', NULL, '1.0'),
(140, 122, 'Quotation', 'Blank board.png', 'uploads/1777722393_quote_b83baac2.png', 'Uncategorized', NULL, NULL, NULL, '34e2b51585da18494e67ca688598097b6ee190d947adc720baf2f153056ef24f', 3, '2026-05-02 11:46:33', 'Active', 'Pending', NULL, '1.0'),
(141, NULL, 'Generic', 'wrhhwr', '../uploads/1779537592_IMG_20250203_133245.jpg', 'Job Orders', NULL, '2026', NULL, '02673d8bdeb87463d1770b0a3e56078c19cf2f724f59754b692dfa76ca8222e7', 9, '2026-05-23 11:59:52', 'Active', 'Pending', NULL, '1.0'),
(142, NULL, 'Generic', 'fyuetud', '../uploads/1779537719_Sharico Research.pdf', 'Meeting Minutes', NULL, 'fjsfj', '2026-05-23', '64a77a7d7958171af3cf50c7256dc4f52c18b2c2959add198ea8141e397a97bb', 2, '2026-05-23 12:01:59', 'Archived', 'Pending', NULL, '1.0');

-- --------------------------------------------------------

--
-- Table structure for table `document_audit_trail`
--

CREATE TABLE `document_audit_trail` (
  `trail_id` int(11) NOT NULL,
  `audit_log_id` int(11) DEFAULT NULL,
  `doc_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `source_page` varchar(191) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_audit_trail`
--

INSERT INTO `document_audit_trail` (`trail_id`, `audit_log_id`, `doc_id`, `user_id`, `action_type`, `description`, `ip_address`, `source_page`, `created_at`) VALUES
(1, 1171, 138, 9, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:30:01'),
(2, 1172, 136, 9, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:30:01'),
(3, 1181, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:31:40'),
(4, 1182, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:35:48'),
(5, 1183, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:35:48'),
(6, 1184, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:35:55'),
(7, 1185, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:35:55'),
(8, 1186, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:35:57'),
(9, 1187, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:35:57'),
(10, 1188, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:38:54'),
(11, 1189, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:38:54'),
(12, 1190, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:38:59'),
(13, 1191, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:38:59'),
(14, 1192, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:39:13'),
(15, 1193, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:39:13'),
(16, 1194, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:43:29'),
(17, 1195, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:43:29'),
(18, 1196, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:46:01'),
(19, 1197, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:47:58'),
(20, 1198, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:47:58'),
(21, 1199, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:48:48'),
(22, 1200, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:48:48'),
(23, 1201, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:48:55'),
(24, 1202, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:50:50'),
(25, 1203, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:51:56'),
(26, 1204, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:52:22'),
(27, 1205, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:53:04'),
(28, 1206, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:53:18'),
(29, 1207, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-24 12:53:44'),
(30, 1208, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-24 12:53:49'),
(31, 1209, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-24 12:53:49'),
(32, 1210, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-24 12:53:58'),
(33, 1211, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-24 12:58:41'),
(34, 1212, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-24 13:03:12'),
(35, 1213, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-24 13:03:37'),
(36, 1224, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-25 10:56:30'),
(37, 1225, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-25 10:56:30'),
(38, 1226, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-25 10:56:36'),
(39, 1229, 139, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777532477_Blank board.png', '::1', '/fixie_drms/download.php?file=1777532477_Blank+board.png&doc_id=139', '2026-05-25 11:29:05'),
(40, 1230, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-25 11:29:37'),
(41, 1231, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-25 11:31:53'),
(42, 1232, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-25 11:31:58'),
(43, 1233, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-25 11:31:59'),
(44, 1234, 141, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779537592_IMG_20250203_133245.jpg', '::1', '/fixie_drms/download.php?file=1779537592_IMG_20250203_133245.jpg&doc_id=141', '2026-05-25 11:32:20'),
(45, 1236, 137, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1777181614_INPUT.png', '::1', '/fixie_drms/download.php?file=1777181614_INPUT.png&doc_id=137', '2026-05-25 12:06:30'),
(46, 1237, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-25 12:07:02'),
(47, 1238, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-25 12:07:02'),
(48, 1239, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-25 12:07:06'),
(49, 1240, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-25 12:07:06'),
(50, 1241, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-25 12:11:59'),
(51, 1242, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-25 12:11:59'),
(52, 1249, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-25 12:59:01'),
(53, 1250, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-25 12:59:01'),
(54, 1251, 138, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1779619239_d1c8e529_Screenshot 3.png', '::1', '/fixie_drms/download.php?file=1779619239_d1c8e529_Screenshot+3.png&doc_id=138', '2026-05-25 12:59:09'),
(55, 1252, 136, 2, 'DOWNLOAD_DOC', 'Downloaded document: 1776928439_quote_ceb3ce3b.png', '::1', '/fixie_drms/download.php?file=1776928439_quote_ceb3ce3b.png&doc_id=136', '2026-05-25 12:59:09');

-- --------------------------------------------------------

--
-- Table structure for table `document_categories`
--

CREATE TABLE `document_categories` (
  `id` int(11) NOT NULL,
  `parent_category` varchar(100) NOT NULL,
  `sub_category` varchar(100) NOT NULL,
  `assigned_to_role` varchar(255) DEFAULT NULL,
  `policy_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_categories`
--

INSERT INTO `document_categories` (`id`, `parent_category`, `sub_category`, `assigned_to_role`, `policy_id`) VALUES
(1, 'Administrative & Legal Records', 'Business Permits', NULL, 1),
(2, 'Administrative & Legal Records', 'Company Policies and Procedures', NULL, 1),
(3, 'Administrative & Legal Records', 'Contracts and Legal Agreements', NULL, 1),
(4, 'Administrative & Legal Records', 'Official Correspondence with External Parties', NULL, 1),
(5, 'Administrative & Legal Records', 'Internal Memorandums', NULL, 1),
(6, 'Administrative & Legal Records', 'Official Correspondence with Branch Personnel', NULL, 1),
(7, 'Administrative & Legal Records', 'Meeting Minutes', NULL, 1),
(8, 'Administrative & Legal Records', 'Authorization Letters and Approval Documents', NULL, 1),
(9, 'Finance & Sales Records', 'Official Receipts', 'Finance', 1),
(10, 'Finance & Sales Records', 'Invoices', 'Finance', 1),
(11, 'Finance & Sales Records', 'Payment Confirmations', 'Finance', 1),
(12, 'Finance & Sales Records', 'Purchase requests', 'Sales Staff', 1),
(13, 'Finance & Sales Records', 'Signed Purchase requests', 'Sales Staff', 1),
(14, 'Finance & Sales Records', 'Sales transaction records', 'Sales Staff', 1),
(15, 'Finance & Sales Records', 'Pricing agreements', 'Sales Staff', 1),
(16, 'Procurement & Logistics', 'Customer Quotations', 'Procurement', 1),
(17, 'Procurement & Logistics', 'Purchase Orders', 'Procurement', 1),
(18, 'Procurement & Logistics', 'Signed Purchase Orders', 'Procurement', 1),
(19, 'Procurement & Logistics', 'Delivery Receipts', 'Supply Chain', 1),
(20, 'Procurement & Logistics', 'Supplier Transaction Records', 'Supply Chain', 1),
(21, 'Technical & Service Records', 'Service Tickets', 'Technical', 1),
(22, 'Technical & Service Records', 'Diagnostic Reports', 'Technical', 1),
(23, 'Technical & Service Records', 'Job Orders', 'Technical', 1),
(24, 'HR & Employee Files', 'Leave Forms', 'Administrative,Staff', 1),
(25, 'HR & Employee Files', 'Employee Correspondence and Notices', 'Administrative,Staff', 1),
(26, 'Procurement & Logistics', 'Service Fee', 'Supply Chain', 1),
(30, 'Procurement & Logistics', 'haha', 'Procurement', 1);

-- --------------------------------------------------------

--
-- Table structure for table `document_versions`
--

CREATE TABLE `document_versions` (
  `version_id` int(11) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `version_number` varchar(10) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_versions`
--

INSERT INTO `document_versions` (`version_id`, `doc_id`, `version_number`, `file_name`, `file_path`, `uploaded_by`, `uploaded_at`, `remarks`) VALUES
(1, 138, '1.0', 'pooo', '../uploads/1777186501_Screenshot 2026-04-16 093036.png', 3, '2026-04-26 06:55:01', 'sample');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notif_id` int(11) NOT NULL,
  `target_role` varchar(50) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notif_id`, `target_role`, `message`, `is_read`, `created_at`) VALUES
(1, 'GM', 'New Purchase Request Needs Approval: PR-2026-0101', 1, '2026-04-19 12:29:52'),
(2, 'President', 'New Purchase Request Needs Approval: PR-2026-0101', 0, '2026-04-19 12:29:52'),
(3, 'GM', 'Retention Alert: Document \'Mayor\'s Permit\' requires attention (Expiring/Expired).', 1, '2026-04-19 12:30:36'),
(4, 'Procurement', 'PR PR-2026-0101 is Approved. Ready for PO Conversion.', 0, '2026-04-19 12:31:05'),
(5, 'Sales Staff', 'Your PR PR-2026-0101 has been Approved by Management.', 1, '2026-04-19 12:31:05'),
(6, 'GM', 'New Purchase Request Needs Approval: PR-2026-0001', 1, '2026-04-19 13:05:54'),
(7, 'President', 'New Purchase Request Needs Approval: PR-2026-0001', 0, '2026-04-19 13:05:54'),
(8, 'Procurement', 'PR PR-2026-0001 is Approved. Ready for PO Conversion.', 0, '2026-04-19 13:06:23'),
(9, 'Sales Staff', 'Your PR PR-2026-0001 has been Approved by Management.', 0, '2026-04-19 13:06:23'),
(10, 'GM', 'New Purchase Order Requires Approval: PO-2026-0001', 0, '2026-04-19 13:07:27'),
(11, 'Finance', 'New PO Requires Validation: PO #117', 1, '2026-04-19 13:09:33'),
(12, 'President', 'New PO Requires Sign-off: PO #117', 1, '2026-04-19 13:10:33'),
(13, 'President', 'Retention Alert: Document \'Mayor\'s Permit\' requires attention (Expiring/Expired).', 0, '2026-04-19 13:10:42'),
(14, 'Finance', 'PO Approved by President. Ready for Funding: PO #117', 1, '2026-04-19 13:11:33'),
(15, 'Supply Chain', 'PO Funded. Ready for Delivery: PO #117', 1, '2026-04-19 13:11:50'),
(16, 'Finance', 'PO Delivered. Awaiting Collection: PO #117', 1, '2026-04-19 13:15:43'),
(17, 'GM', 'New Purchase Order Requires Approval: PO-2026-0002', 0, '2026-04-19 13:40:43'),
(18, 'Finance', 'New PO Requires Validation: PO #118', 1, '2026-04-19 13:41:10'),
(19, 'President', 'New PO Requires Sign-off: PO #118', 1, '2026-04-19 13:41:38'),
(20, 'Finance', 'PO Approved by President. Ready for Funding: PO #118', 1, '2026-04-19 13:42:10'),
(21, 'Supply Chain', 'PO Funded. Ready for Delivery: PO #118', 1, '2026-04-19 13:42:28'),
(22, 'Finance', 'PO Delivered. Awaiting Collection: PO #118', 1, '2026-04-19 13:42:46'),
(23, 'GM', 'New Purchase Request Needs Approval: PR-2026-0002', 0, '2026-04-23 00:05:13'),
(24, 'President', 'New Purchase Request Needs Approval: PR-2026-0002', 0, '2026-04-23 00:05:13'),
(25, 'GM', 'Retention Alert: Document \'NEW\' requires attention (Expiring/Expired).', 0, '2026-04-23 00:05:42'),
(26, 'Procurement', 'PR PR-2026-0002 is Approved. Ready for PO Conversion.', 0, '2026-04-23 00:05:53'),
(27, 'Sales Staff', 'Your PR PR-2026-0002 has been Approved by Management.', 0, '2026-04-23 00:05:53'),
(28, 'GM', 'New Purchase Order Requires Approval: PO-2026-0003', 0, '2026-04-23 00:06:38'),
(29, 'Finance', 'New PO Requires Validation: PO #119', 1, '2026-04-23 00:06:54'),
(30, 'President', 'New PO Requires Sign-off: PO #119', 0, '2026-04-23 00:07:12'),
(31, 'President', 'Retention Alert: Document \'NEW\' requires attention (Expiring/Expired).', 0, '2026-04-23 00:07:22'),
(32, 'GM', 'New Purchase Request Needs Approval: PR-2026-0003', 0, '2026-04-23 06:02:55'),
(33, 'President', 'New Purchase Request Needs Approval: PR-2026-0003', 0, '2026-04-23 06:02:55'),
(34, 'Procurement', 'PR PR-2026-0003 is Approved. Ready for PO Conversion.', 0, '2026-04-23 06:08:50'),
(35, 'Sales Staff', 'Your PR PR-2026-0003 has been Approved by Management.', 0, '2026-04-23 06:08:50'),
(36, 'GM', 'New Purchase Order Requires Approval: PO-2026-0004', 0, '2026-04-23 06:11:04'),
(37, 'Finance', 'New PO Requires Validation: PO #120', 0, '2026-04-23 06:18:44'),
(38, 'GM', 'New Purchase Request Needs Approval: PR-2026-0004', 0, '2026-04-23 07:12:50'),
(39, 'President', 'New Purchase Request Needs Approval: PR-2026-0004', 0, '2026-04-23 07:12:50'),
(40, 'Procurement', 'PR PR-2026-0004 is Approved. Ready for PO Conversion.', 0, '2026-04-23 07:13:26'),
(41, 'Sales Staff', 'Your PR PR-2026-0004 has been Approved by Management.', 0, '2026-04-23 07:13:26'),
(42, 'GM', 'New Purchase Order Requires Approval: PO-2026-0005', 0, '2026-04-23 07:13:59'),
(43, 'Finance', 'New PO Requires Validation: PO #121', 1, '2026-04-23 07:14:15'),
(44, 'President', 'New PO Requires Sign-off: PO #121', 1, '2026-04-23 07:14:32'),
(45, 'Finance', 'PO Approved by President. Ready for Funding: PO #121', 1, '2026-04-23 07:14:46'),
(46, 'Supply Chain', 'PO Funded. Ready for Delivery: PO #121', 1, '2026-04-23 07:15:00'),
(47, 'Finance', 'PO Delivered. Awaiting Collection: PO #121', 1, '2026-04-23 07:16:23'),
(48, 'GM', 'New Purchase Order Requires Approval: PO-2026-0006', 0, '2026-05-02 11:46:33'),
(49, 'Finance', 'New PO Requires Validation: PO #122', 1, '2026-05-02 11:47:04'),
(50, 'President', 'New PO Requires Sign-off: PO #122', 1, '2026-05-02 11:47:25'),
(51, 'Finance', 'PO Approved by President. Ready for Funding: PO #122', 1, '2026-05-02 11:47:40'),
(52, 'Supply Chain', 'PO Funded. Ready for Delivery: PO #122', 1, '2026-05-02 11:47:53'),
(53, 'Finance', 'PO Delivered. Awaiting Collection: PO #122', 1, '2026-05-02 11:48:16'),
(54, 'GM', 'New Purchase Request Needs Approval: PR-2026-0005', 0, '2026-05-21 11:24:19'),
(55, 'President', 'New Purchase Request Needs Approval: PR-2026-0005', 0, '2026-05-21 11:24:19'),
(56, 'Procurement', 'PR PR-2026-0005 is Approved. Ready for PO Conversion.', 0, '2026-05-21 11:38:38'),
(57, 'Sales Staff', 'Your PR PR-2026-0005 has been Approved by Management.', 0, '2026-05-21 11:38:38'),
(58, 'GM', 'New Purchase Order Requires Approval: PO-2026-0007', 0, '2026-05-21 11:40:13'),
(59, 'GM', 'Retention Alert: Document \'fyuetud\' requires attention (Expiring/Expired).', 0, '2026-05-23 12:02:12');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL,
  `payment_date` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `po_id`, `amount_paid`, `payment_date`, `notes`, `recorded_by`, `created_at`) VALUES
(1, 117, 50000.00, '2026-04-19 15:38:00', '', NULL, '2026-04-19 13:38:42'),
(2, 117, 20000.00, '2026-04-19 15:39:00', '', NULL, '2026-04-19 13:39:06'),
(3, 117, 30000.00, '2026-04-19 15:39:00', 'Full Payment', NULL, '2026-04-19 13:39:10'),
(4, 118, 50000.00, '2026-04-19 15:45:00', 'Partial Payment', NULL, '2026-04-19 13:45:19'),
(5, 118, 20000.00, '2026-04-19 15:46:00', 'Partial Payment', NULL, '2026-04-19 13:47:02'),
(6, 118, 50000.00, '2026-04-19 22:00:00', 'Partial Payment', NULL, '2026-04-19 14:00:17'),
(7, 121, 10000.00, '2026-04-23 15:16:00', 'Full Payment', NULL, '2026-04-23 07:16:49'),
(8, 122, 5000.00, '2026-05-02 19:48:00', 'Partial Payment', NULL, '2026-05-02 11:48:40'),
(9, 122, 5000.00, '2026-05-02 19:48:00', 'Full Payment', NULL, '2026-05-02 11:48:51');

-- --------------------------------------------------------

--
-- Table structure for table `po_history`
--

CREATE TABLE `po_history` (
  `history_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `status_from` varchar(50) DEFAULT NULL,
  `status_to` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `po_history`
--

INSERT INTO `po_history` (`history_id`, `po_id`, `status_from`, `status_to`, `remarks`, `changed_by`, `timestamp`) VALUES
(1, 1, 'New', 'Pending', NULL, 16, '2025-11-03 08:15:00'),
(2, 2, 'New', 'Pending', NULL, 16, '2025-11-05 10:30:00'),
(3, 3, 'New', 'Pending', NULL, 16, '2025-11-07 14:45:00'),
(4, 4, 'New', 'Pending', NULL, 16, '2025-11-10 09:20:00'),
(5, 5, 'New', 'Pending', NULL, 16, '2025-11-12 11:10:00'),
(6, 6, 'New', 'Pending', NULL, 16, '2025-11-14 13:50:00'),
(7, 7, 'New', 'Pending', NULL, 16, '2025-11-17 15:25:00'),
(8, 8, 'New', 'Pending', NULL, 16, '2025-11-19 10:40:00'),
(9, 9, 'New', 'Pending', NULL, 16, '2025-11-21 14:15:00'),
(10, 10, 'New', 'Pending', NULL, 16, '2025-11-23 08:50:00'),
(11, 11, 'New', 'Pending', NULL, 16, '2025-11-25 11:30:00'),
(12, 12, 'New', 'Pending', NULL, 16, '2025-11-27 15:10:00'),
(13, 13, 'New', 'Pending', NULL, 16, '2025-11-29 09:45:00'),
(14, 14, 'New', 'Pending', NULL, 16, '2025-11-30 10:20:00'),
(15, 15, 'New', 'Pending', NULL, 16, '2025-12-01 13:40:00'),
(16, 16, 'New', 'Pending', NULL, 16, '2025-12-02 16:05:00'),
(17, 17, 'New', 'Pending', NULL, 16, '2025-12-04 08:30:00'),
(18, 18, 'New', 'Pending', NULL, 16, '2025-12-06 11:15:00'),
(19, 19, 'New', 'Pending', NULL, 16, '2025-12-08 14:00:00'),
(20, 20, 'New', 'Pending', NULL, 16, '2025-12-10 16:30:00'),
(21, 21, 'New', 'Pending', NULL, 16, '2025-12-12 09:20:00'),
(22, 22, 'New', 'Pending', NULL, 16, '2025-12-14 10:45:00'),
(23, 23, 'New', 'Pending', NULL, 16, '2025-12-16 13:10:00'),
(24, 24, 'New', 'Pending', NULL, 16, '2025-12-18 15:50:00'),
(25, 25, 'New', 'Pending', NULL, 16, '2025-12-20 09:00:00'),
(26, 26, 'New', 'Pending', NULL, 16, '2025-12-22 11:20:00'),
(27, 27, 'New', 'Pending', NULL, 16, '2025-12-24 14:15:00'),
(28, 28, 'New', 'Pending', NULL, 16, '2025-12-26 08:30:00'),
(29, 29, 'New', 'Pending', NULL, 16, '2025-12-28 10:00:00'),
(30, 30, 'New', 'Pending', NULL, 16, '2025-12-30 13:40:00'),
(31, 31, 'New', 'Pending', NULL, 16, '2025-12-31 15:10:00'),
(32, 32, 'New', 'Pending', NULL, 16, '2026-01-01 09:30:00'),
(33, 33, 'New', 'Pending', NULL, 16, '2026-01-02 11:50:00'),
(34, 34, 'New', 'Pending', NULL, 16, '2026-01-04 08:15:00'),
(35, 35, 'New', 'Pending', NULL, 16, '2026-01-06 10:30:00'),
(36, 36, 'New', 'Pending', NULL, 16, '2026-01-08 14:45:00'),
(37, 37, 'New', 'Pending', NULL, 16, '2026-01-10 09:20:00'),
(38, 38, 'New', 'Pending', NULL, 16, '2026-01-12 11:10:00'),
(39, 39, 'New', 'Pending', NULL, 16, '2026-01-14 13:50:00'),
(40, 40, 'New', 'Pending', NULL, 16, '2026-01-16 15:25:00'),
(41, 41, 'New', 'Pending', NULL, 16, '2026-01-18 10:40:00'),
(42, 42, 'New', 'Pending', NULL, 16, '2026-01-20 14:15:00'),
(43, 43, 'New', 'Pending', NULL, 16, '2026-01-22 08:50:00'),
(44, 44, 'New', 'Pending', NULL, 16, '2026-01-24 11:30:00'),
(45, 45, 'New', 'Pending', NULL, 16, '2026-01-26 15:10:00'),
(46, 46, 'New', 'Pending', NULL, 16, '2026-01-28 09:45:00'),
(47, 47, 'New', 'Pending', NULL, 16, '2026-01-29 10:20:00'),
(48, 48, 'New', 'Pending', NULL, 16, '2026-01-30 13:40:00'),
(49, 49, 'New', 'Pending', NULL, 16, '2026-01-31 16:05:00'),
(50, 50, 'New', 'Pending', NULL, 16, '2026-02-02 08:30:00'),
(51, 51, 'New', 'Pending', NULL, 16, '2026-02-04 11:15:00'),
(52, 52, 'New', 'Pending', NULL, 16, '2026-02-06 14:00:00'),
(53, 53, 'New', 'Pending', NULL, 16, '2026-02-08 16:30:00'),
(54, 54, 'New', 'Pending', NULL, 16, '2026-02-10 09:20:00'),
(55, 55, 'New', 'Pending', NULL, 16, '2026-02-12 10:45:00'),
(56, 56, 'New', 'Pending', NULL, 16, '2026-02-14 13:10:00'),
(57, 57, 'New', 'Pending', NULL, 16, '2026-02-16 15:50:00'),
(58, 58, 'New', 'Pending', NULL, 16, '2026-02-18 09:00:00'),
(59, 59, 'New', 'Pending', NULL, 16, '2026-02-20 11:20:00'),
(60, 60, 'New', 'Pending', NULL, 16, '2026-02-22 14:15:00'),
(61, 61, 'New', 'Pending', NULL, 16, '2026-02-23 08:30:00'),
(62, 62, 'New', 'Pending', NULL, 16, '2026-02-24 10:00:00'),
(63, 63, 'New', 'Pending', NULL, 16, '2026-02-25 13:40:00'),
(64, 64, 'New', 'Pending', NULL, 16, '2026-02-26 15:10:00'),
(65, 65, 'New', 'Pending', NULL, 16, '2026-02-27 09:30:00'),
(66, 66, 'New', 'Pending', NULL, 16, '2026-02-28 11:50:00'),
(67, 67, 'New', 'Pending', NULL, 16, '2026-03-02 14:15:00'),
(68, 68, 'New', 'Pending', NULL, 16, '2026-03-04 08:15:00'),
(69, 69, 'New', 'Pending', NULL, 16, '2026-03-06 10:30:00'),
(70, 70, 'New', 'Pending', NULL, 16, '2026-03-08 14:45:00'),
(128, 1, 'Pending', 'GM-Approved', NULL, 9, '2025-11-04 08:15:00'),
(129, 2, 'Pending', 'GM-Approved', NULL, 9, '2025-11-06 10:30:00'),
(130, 3, 'Pending', 'GM-Approved', NULL, 9, '2025-11-08 14:45:00'),
(131, 4, 'Pending', 'GM-Approved', NULL, 9, '2025-11-11 09:20:00'),
(132, 5, 'Pending', 'GM-Approved', NULL, 9, '2025-11-13 11:10:00'),
(133, 6, 'Pending', 'GM-Approved', NULL, 9, '2025-11-15 13:50:00'),
(134, 7, 'Pending', 'GM-Approved', NULL, 9, '2025-11-18 15:25:00'),
(135, 8, 'Pending', 'GM-Approved', NULL, 9, '2025-11-20 10:40:00'),
(136, 9, 'Pending', 'GM-Approved', NULL, 9, '2025-11-22 14:15:00'),
(137, 10, 'Pending', 'GM-Approved', NULL, 9, '2025-11-24 08:50:00'),
(138, 11, 'Pending', 'GM-Approved', NULL, 9, '2025-11-26 11:30:00'),
(139, 12, 'Pending', 'GM-Approved', NULL, 9, '2025-11-28 15:10:00'),
(140, 13, 'Pending', 'GM-Approved', NULL, 9, '2025-11-30 09:45:00'),
(141, 14, 'Pending', 'GM-Approved', NULL, 9, '2025-12-01 10:20:00'),
(142, 15, 'Pending', 'GM-Approved', NULL, 9, '2025-12-02 13:40:00'),
(143, 16, 'Pending', 'GM-Approved', NULL, 9, '2025-12-03 16:05:00'),
(144, 17, 'Pending', 'GM-Approved', NULL, 9, '2025-12-05 08:30:00'),
(145, 18, 'Pending', 'GM-Approved', NULL, 9, '2025-12-07 11:15:00'),
(146, 19, 'Pending', 'GM-Approved', NULL, 9, '2025-12-09 14:00:00'),
(147, 20, 'Pending', 'GM-Approved', NULL, 9, '2025-12-11 16:30:00'),
(148, 21, 'Pending', 'GM-Approved', NULL, 9, '2025-12-13 09:20:00'),
(149, 22, 'Pending', 'GM-Approved', NULL, 9, '2025-12-15 10:45:00'),
(150, 23, 'Pending', 'GM-Approved', NULL, 9, '2025-12-17 13:10:00'),
(151, 24, 'Pending', 'GM-Approved', NULL, 9, '2025-12-19 15:50:00'),
(152, 25, 'Pending', 'GM-Approved', NULL, 9, '2025-12-21 09:00:00'),
(153, 26, 'Pending', 'GM-Approved', NULL, 9, '2025-12-23 11:20:00'),
(154, 27, 'Pending', 'GM-Approved', NULL, 9, '2025-12-25 14:15:00'),
(155, 28, 'Pending', 'GM-Approved', NULL, 9, '2025-12-27 08:30:00'),
(156, 29, 'Pending', 'GM-Approved', NULL, 9, '2025-12-29 10:00:00'),
(157, 30, 'Pending', 'GM-Approved', NULL, 9, '2025-12-31 13:40:00'),
(158, 31, 'Pending', 'GM-Approved', NULL, 9, '2026-01-01 15:10:00'),
(159, 32, 'Pending', 'GM-Approved', NULL, 9, '2026-01-02 09:30:00'),
(160, 33, 'Pending', 'GM-Approved', NULL, 9, '2026-01-03 11:50:00'),
(161, 34, 'Pending', 'GM-Approved', NULL, 9, '2026-01-05 08:15:00'),
(162, 35, 'Pending', 'GM-Approved', NULL, 9, '2026-01-07 10:30:00'),
(163, 36, 'Pending', 'GM-Approved', NULL, 9, '2026-01-09 14:45:00'),
(164, 37, 'Pending', 'GM-Approved', NULL, 9, '2026-01-11 09:20:00'),
(165, 38, 'Pending', 'GM-Approved', NULL, 9, '2026-01-13 11:10:00'),
(166, 39, 'Pending', 'GM-Approved', NULL, 9, '2026-01-15 13:50:00'),
(167, 40, 'Pending', 'GM-Approved', NULL, 9, '2026-01-17 15:25:00'),
(168, 41, 'Pending', 'GM-Approved', NULL, 9, '2026-01-19 10:40:00'),
(169, 42, 'Pending', 'GM-Approved', NULL, 9, '2026-01-21 14:15:00'),
(170, 43, 'Pending', 'GM-Approved', NULL, 9, '2026-01-23 08:50:00'),
(171, 44, 'Pending', 'GM-Approved', NULL, 9, '2026-01-25 11:30:00'),
(172, 45, 'Pending', 'GM-Approved', NULL, 9, '2026-01-27 15:10:00'),
(173, 46, 'Pending', 'GM-Approved', NULL, 9, '2026-01-29 09:45:00'),
(174, 47, 'Pending', 'GM-Approved', NULL, 9, '2026-01-30 10:20:00'),
(175, 48, 'Pending', 'GM-Approved', NULL, 9, '2026-01-31 13:40:00'),
(176, 49, 'Pending', 'GM-Approved', NULL, 9, '2026-02-01 16:05:00'),
(177, 50, 'Pending', 'GM-Approved', NULL, 9, '2026-02-03 08:30:00'),
(191, 1, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-06 08:15:00'),
(192, 2, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-08 10:30:00'),
(193, 3, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-10 14:45:00'),
(194, 4, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-13 09:20:00'),
(195, 5, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-15 11:10:00'),
(196, 6, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-17 13:50:00'),
(197, 7, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-20 15:25:00'),
(198, 8, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-22 10:40:00'),
(199, 9, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-24 14:15:00'),
(200, 10, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-26 08:50:00'),
(201, 11, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-28 11:30:00'),
(202, 12, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-11-30 15:10:00'),
(203, 13, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-02 09:45:00'),
(204, 14, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-03 10:20:00'),
(205, 15, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-04 13:40:00'),
(206, 16, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-05 16:05:00'),
(207, 17, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-07 08:30:00'),
(208, 18, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-09 11:15:00'),
(209, 19, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-11 14:00:00'),
(210, 20, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-13 16:30:00'),
(211, 21, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-15 09:20:00'),
(212, 22, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-17 10:45:00'),
(213, 23, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-19 13:10:00'),
(214, 24, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-21 15:50:00'),
(215, 25, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-23 09:00:00'),
(216, 26, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-25 11:20:00'),
(217, 27, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-27 14:15:00'),
(218, 28, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-29 08:30:00'),
(219, 29, 'GM-Approved', 'Finance-Approved', NULL, 3, '2025-12-31 10:00:00'),
(220, 30, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-02 13:40:00'),
(221, 31, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-03 15:10:00'),
(222, 32, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-04 09:30:00'),
(223, 33, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-05 11:50:00'),
(224, 34, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-07 08:15:00'),
(225, 35, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-09 10:30:00'),
(226, 36, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-11 14:45:00'),
(227, 37, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-13 09:20:00'),
(228, 38, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-15 11:10:00'),
(229, 39, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-17 13:50:00'),
(230, 40, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-19 15:25:00'),
(231, 41, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-21 10:40:00'),
(232, 42, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-23 14:15:00'),
(233, 43, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-25 08:50:00'),
(234, 44, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-27 11:30:00'),
(235, 45, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-29 15:10:00'),
(236, 46, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-01-31 09:45:00'),
(237, 47, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-02-01 10:20:00'),
(238, 48, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-02-02 13:40:00'),
(239, 49, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-02-03 16:05:00'),
(240, 50, 'GM-Approved', 'Finance-Approved', NULL, 3, '2026-02-05 08:30:00'),
(254, 1, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-08 08:15:00'),
(255, 2, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-10 10:30:00'),
(256, 3, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-12 14:45:00'),
(257, 4, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-15 09:20:00'),
(258, 5, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-17 11:10:00'),
(259, 6, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-19 13:50:00'),
(260, 7, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-22 15:25:00'),
(261, 8, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-24 10:40:00'),
(262, 9, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-26 14:15:00'),
(263, 10, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-28 08:50:00'),
(264, 11, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-11-30 11:30:00'),
(265, 12, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-02 15:10:00'),
(266, 13, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-04 09:45:00'),
(267, 14, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-05 10:20:00'),
(268, 15, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-06 13:40:00'),
(269, 16, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-07 16:05:00'),
(270, 17, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-09 08:30:00'),
(271, 18, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-11 11:15:00'),
(272, 19, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-13 14:00:00'),
(273, 20, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-15 16:30:00'),
(274, 21, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-17 09:20:00'),
(275, 22, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-19 10:45:00'),
(276, 23, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-21 13:10:00'),
(277, 24, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-23 15:50:00'),
(278, 25, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-25 09:00:00'),
(279, 26, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-27 11:20:00'),
(280, 27, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-29 14:15:00'),
(281, 28, 'Finance-Approved', 'President-Approved', NULL, 9, '2025-12-31 08:30:00'),
(282, 29, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-02 10:00:00'),
(283, 30, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-04 13:40:00'),
(284, 31, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-05 15:10:00'),
(285, 32, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-06 09:30:00'),
(286, 33, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-07 11:50:00'),
(287, 34, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-09 08:15:00'),
(288, 35, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-11 10:30:00'),
(289, 36, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-13 14:45:00'),
(290, 37, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-15 09:20:00'),
(291, 38, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-17 11:10:00'),
(292, 39, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-19 13:50:00'),
(293, 40, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-21 15:25:00'),
(294, 41, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-23 10:40:00'),
(295, 42, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-25 14:15:00'),
(296, 43, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-27 08:50:00'),
(297, 44, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-29 11:30:00'),
(298, 45, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-01-31 15:10:00'),
(299, 46, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-02-02 09:45:00'),
(300, 47, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-02-03 10:20:00'),
(301, 48, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-02-04 13:40:00'),
(302, 49, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-02-05 16:05:00'),
(303, 50, 'Finance-Approved', 'President-Approved', NULL, 9, '2026-02-07 08:30:00'),
(317, 1, 'President-Approved', 'Delivered', NULL, 3, '2025-11-13 00:00:00'),
(318, 2, 'President-Approved', 'Delivered', NULL, 3, '2025-11-15 00:00:00'),
(319, 3, 'President-Approved', 'Delivered', NULL, 3, '2025-11-17 00:00:00'),
(320, 4, 'President-Approved', 'Delivered', NULL, 3, '2025-11-20 00:00:00'),
(321, 5, 'President-Approved', 'Delivered', NULL, 3, '2025-11-22 00:00:00'),
(322, 6, 'President-Approved', 'Delivered', NULL, 3, '2025-11-24 00:00:00'),
(323, 7, 'President-Approved', 'Delivered', NULL, 3, '2025-11-27 00:00:00'),
(324, 8, 'President-Approved', 'Delivered', NULL, 3, '2025-11-29 00:00:00'),
(325, 9, 'President-Approved', 'Delivered', NULL, 3, '2025-12-01 00:00:00'),
(326, 10, 'President-Approved', 'Delivered', NULL, 3, '2025-12-03 00:00:00'),
(327, 11, 'President-Approved', 'Delivered', NULL, 3, '2025-12-05 00:00:00'),
(328, 12, 'President-Approved', 'Delivered', NULL, 3, '2025-12-07 00:00:00'),
(329, 13, 'President-Approved', 'Delivered', NULL, 3, '2025-12-09 00:00:00'),
(330, 14, 'President-Approved', 'Delivered', NULL, 3, '2025-12-10 00:00:00'),
(331, 15, 'President-Approved', 'Delivered', NULL, 3, '2025-12-11 00:00:00'),
(332, 16, 'President-Approved', 'Delivered', NULL, 3, '2025-12-12 00:00:00'),
(333, 17, 'President-Approved', 'Delivered', NULL, 3, '2025-12-14 00:00:00'),
(334, 18, 'President-Approved', 'Delivered', NULL, 3, '2025-12-16 00:00:00'),
(335, 19, 'President-Approved', 'Delivered', NULL, 3, '2025-12-18 00:00:00'),
(336, 20, 'President-Approved', 'Delivered', NULL, 3, '2025-12-20 00:00:00'),
(337, 21, 'President-Approved', 'Delivered', NULL, 3, '2025-12-22 00:00:00'),
(338, 22, 'President-Approved', 'Delivered', NULL, 3, '2025-12-24 00:00:00'),
(339, 23, 'President-Approved', 'Delivered', NULL, 3, '2025-12-26 00:00:00'),
(340, 24, 'President-Approved', 'Delivered', NULL, 3, '2025-12-28 00:00:00'),
(341, 25, 'President-Approved', 'Delivered', NULL, 3, '2025-12-30 00:00:00'),
(342, 26, 'President-Approved', 'Delivered', NULL, 3, '2026-01-01 00:00:00'),
(343, 27, 'President-Approved', 'Delivered', NULL, 3, '2026-01-03 00:00:00'),
(344, 28, 'President-Approved', 'Delivered', NULL, 3, '2026-01-05 00:00:00'),
(345, 29, 'President-Approved', 'Delivered', NULL, 3, '2026-01-07 00:00:00'),
(346, 30, 'President-Approved', 'Delivered', NULL, 3, '2026-01-09 00:00:00'),
(347, 31, 'President-Approved', 'Delivered', NULL, 3, '2026-01-10 00:00:00'),
(348, 32, 'President-Approved', 'Delivered', NULL, 3, '2026-01-11 00:00:00'),
(349, 33, 'President-Approved', 'Delivered', NULL, 3, '2026-01-12 00:00:00'),
(350, 34, 'President-Approved', 'Delivered', NULL, 3, '2026-01-14 00:00:00'),
(351, 35, 'President-Approved', 'Delivered', NULL, 3, '2026-01-16 00:00:00'),
(352, 36, 'President-Approved', 'Delivered', NULL, 3, '2026-01-18 00:00:00'),
(353, 37, 'President-Approved', 'Delivered', NULL, 3, '2026-01-20 00:00:00'),
(354, 38, 'President-Approved', 'Delivered', NULL, 3, '2026-01-22 00:00:00'),
(355, 39, 'President-Approved', 'Delivered', NULL, 3, '2026-01-24 00:00:00'),
(356, 40, 'President-Approved', 'Delivered', NULL, 3, '2026-01-26 00:00:00'),
(380, 1, 'Delivered', 'Partially-Collected', NULL, 3, '2025-11-18 00:00:00'),
(381, 2, 'Delivered', 'Partially-Collected', NULL, 3, '2025-11-20 00:00:00'),
(382, 3, 'Delivered', 'Partially-Collected', NULL, 3, '2025-11-22 00:00:00'),
(383, 4, 'Delivered', 'Partially-Collected', NULL, 3, '2025-11-25 00:00:00'),
(384, 5, 'Delivered', 'Partially-Collected', NULL, 3, '2025-11-27 00:00:00'),
(385, 6, 'Delivered', 'Partially-Collected', NULL, 3, '2025-11-29 00:00:00'),
(386, 7, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-02 00:00:00'),
(387, 8, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-04 00:00:00'),
(388, 9, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-06 00:00:00'),
(389, 10, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-08 00:00:00'),
(390, 11, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-10 00:00:00'),
(391, 12, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-12 00:00:00'),
(392, 13, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-14 00:00:00'),
(393, 14, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-15 00:00:00'),
(394, 15, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-16 00:00:00'),
(395, 16, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-17 00:00:00'),
(396, 17, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-19 00:00:00'),
(397, 18, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-21 00:00:00'),
(398, 19, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-23 00:00:00'),
(399, 20, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-25 00:00:00'),
(400, 21, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-27 00:00:00'),
(401, 22, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-29 00:00:00'),
(402, 23, 'Delivered', 'Partially-Collected', NULL, 3, '2025-12-31 00:00:00'),
(403, 24, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-02 00:00:00'),
(404, 25, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-04 00:00:00'),
(405, 26, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-06 00:00:00'),
(406, 27, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-08 00:00:00'),
(407, 28, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-10 00:00:00'),
(408, 29, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-12 00:00:00'),
(409, 30, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-14 00:00:00'),
(410, 31, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-15 00:00:00'),
(411, 32, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-16 00:00:00'),
(412, 33, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-17 00:00:00'),
(413, 34, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-19 00:00:00'),
(414, 35, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-21 00:00:00'),
(415, 36, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-23 00:00:00'),
(416, 37, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-25 00:00:00'),
(417, 38, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-27 00:00:00'),
(418, 39, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-29 00:00:00'),
(419, 40, 'Delivered', 'Partially-Collected', NULL, 3, '2026-01-31 00:00:00'),
(443, 1, 'Partially-Collected', 'Collected', NULL, 3, '2025-11-25 00:00:00'),
(444, 2, 'Partially-Collected', 'Collected', NULL, 3, '2025-11-27 00:00:00'),
(445, 3, 'Partially-Collected', 'Collected', NULL, 3, '2025-11-29 00:00:00'),
(446, 4, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-02 00:00:00'),
(447, 5, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-04 00:00:00'),
(448, 6, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-06 00:00:00'),
(449, 7, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-09 00:00:00'),
(450, 8, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-11 00:00:00'),
(451, 9, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-13 00:00:00'),
(452, 10, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-15 00:00:00'),
(453, 11, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-17 00:00:00'),
(454, 12, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-19 00:00:00'),
(455, 13, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-21 00:00:00'),
(456, 14, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-22 00:00:00'),
(457, 15, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-23 00:00:00'),
(458, 16, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-24 00:00:00'),
(459, 17, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-26 00:00:00'),
(460, 18, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-28 00:00:00'),
(461, 19, 'Partially-Collected', 'Collected', NULL, 3, '2025-12-30 00:00:00'),
(462, 20, 'Partially-Collected', 'Collected', NULL, 3, '2026-01-01 00:00:00'),
(463, 21, 'Partially-Collected', 'Collected', NULL, 3, '2026-01-03 00:00:00'),
(464, 22, 'Partially-Collected', 'Collected', NULL, 3, '2026-01-05 00:00:00'),
(465, 23, 'Partially-Collected', 'Collected', NULL, 3, '2026-01-07 00:00:00'),
(466, 24, 'Partially-Collected', 'Collected', NULL, 3, '2026-01-09 00:00:00'),
(467, 25, 'Partially-Collected', 'Collected', NULL, 3, '2026-01-11 00:00:00'),
(474, 61, 'Pending', 'Rejected', NULL, 9, '2026-02-25 08:30:00'),
(475, 62, 'Pending', 'Rejected', NULL, 9, '2026-02-26 10:00:00'),
(476, 63, 'Pending', 'Rejected', NULL, 9, '2026-02-27 13:40:00'),
(477, 64, 'Pending', 'Rejected', NULL, 9, '2026-02-28 15:10:00'),
(478, 65, 'Pending', 'Rejected', NULL, 9, '2026-03-01 09:30:00'),
(479, 66, 'Pending', 'Rejected', NULL, 9, '2026-03-02 11:50:00'),
(480, 67, 'Pending', 'Rejected', NULL, 9, '2026-03-04 14:15:00'),
(481, 68, 'Pending', 'Rejected', NULL, 9, '2026-03-06 08:15:00'),
(482, 69, 'Pending', 'Rejected', NULL, 9, '2026-03-08 10:30:00'),
(483, 70, 'Pending', 'Rejected', NULL, 9, '2026-03-10 14:45:00'),
(484, 117, 'New', 'Pending', NULL, 3, '2026-04-19 21:07:27'),
(485, 117, 'Pending', 'GM-Approved', NULL, 9, '2026-04-19 21:09:33'),
(486, 117, 'GM-Approved', 'Finance-Approved', NULL, 8, '2026-04-19 21:10:33'),
(487, 117, 'Finance-Approved', 'President-Approved', NULL, 6, '2026-04-19 21:11:33'),
(488, 117, 'President-Approved', 'Funded', NULL, 8, '2026-04-19 21:11:50'),
(489, 117, 'Funded', 'Delivered', NULL, 12, '2026-04-19 21:15:43'),
(490, 118, 'New', 'Pending', NULL, 3, '2026-04-19 21:40:43'),
(491, 118, 'Pending', 'GM-Approved', NULL, 9, '2026-04-19 21:41:10'),
(492, 118, 'GM-Approved', 'Finance-Approved', NULL, 8, '2026-04-19 21:41:38'),
(493, 118, 'Finance-Approved', 'President-Approved', NULL, 6, '2026-04-19 21:42:10'),
(494, 118, 'President-Approved', 'Funded', NULL, 8, '2026-04-19 21:42:28'),
(495, 118, 'Funded', 'Delivered', NULL, 12, '2026-04-19 21:42:46'),
(496, 119, 'New', 'Pending', NULL, 3, '2026-04-23 08:06:38'),
(497, 119, 'Pending', 'GM-Approved', NULL, 9, '2026-04-23 08:06:54'),
(498, 119, 'GM-Approved', 'Finance-Approved', NULL, 8, '2026-04-23 08:07:12'),
(499, 120, 'New', 'Pending', NULL, 3, '2026-04-23 14:11:04'),
(500, 120, 'Pending', 'GM-Approved', NULL, 9, '2026-04-23 14:18:44'),
(501, 121, 'New', 'Pending', NULL, 3, '2026-04-23 15:13:59'),
(502, 121, 'Pending', 'GM-Approved', NULL, 9, '2026-04-23 15:14:15'),
(503, 121, 'GM-Approved', 'Finance-Approved', NULL, 8, '2026-04-23 15:14:32'),
(504, 121, 'Finance-Approved', 'President-Approved', NULL, 6, '2026-04-23 15:14:46'),
(505, 121, 'President-Approved', 'Funded', NULL, 8, '2026-04-23 15:15:00'),
(506, 121, 'Funded', 'Delivered', NULL, 20, '2026-04-23 15:16:23'),
(507, 122, 'New', 'Pending', NULL, 3, '2026-05-02 19:46:33'),
(508, 122, 'Pending', 'GM-Approved', NULL, 9, '2026-05-02 19:47:04'),
(509, 122, 'GM-Approved', 'Finance-Approved', NULL, 8, '2026-05-02 19:47:25'),
(510, 122, 'Finance-Approved', 'President-Approved', NULL, 6, '2026-05-02 19:47:40'),
(511, 122, 'President-Approved', 'Funded', NULL, 8, '2026-05-02 19:47:53'),
(512, 122, 'Funded', 'Delivered', NULL, 20, '2026-05-02 19:48:16'),
(513, 123, 'New', 'Pending', NULL, 3, '2026-05-21 19:40:13');

-- --------------------------------------------------------

--
-- Table structure for table `po_items`
--

CREATE TABLE `po_items` (
  `item_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `specifications` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `po_items`
--

INSERT INTO `po_items` (`item_id`, `po_id`, `category`, `brand`, `item_name`, `specifications`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 1, 'Laptops', 'Lenovo', 'ThinkPad T14 Gen 3 Corporate', 'Intel Core i7-1260P, 16GB DDR4, 512GB SSD', 5, 52000.00, 260000.00),
(2, 2, 'CCTV', 'Hikvision', '8-Channel 4K NVR CCTV Package', '8-Ch NVR, 4x 5MP Bullet, 4x Dome', 4, 18750.00, 75000.00),
(3, 3, 'Peripherals', 'Logitech', 'MX Master 3S Wireless Combo', 'Quiet tactile typing, MagSpeed scrolling', 5, 8500.00, 42500.00),
(4, 4, 'Office Supplies', 'HP', 'HP 652 Original Ink Cartridges', 'Black & Tri-color combo packs (Bulk)', 10, 1500.00, 15000.00),
(5, 5, 'Networking', 'Ubiquiti', 'UniFi 6 Long-Range Access Point', 'WiFi 6, 4x4 MU-MIMO, PoE+ powered', 12, 12000.00, 144000.00),
(6, 6, 'Printers', 'Epson', 'EcoTank L3250 Wi-Fi AIO Printer', 'Print, Scan, Copy. Ultra-high yield', 6, 9500.00, 57000.00),
(7, 7, 'Servers', 'Dell', 'PowerEdge R740 Rack Server', 'Intel Xeon Silver 4210R, 64GB RAM', 1, 325000.00, 325000.00),
(8, 8, 'Networking', 'Cisco', 'Catalyst 9200L 48-port PoE+', 'Network Switch Enterprise License', 1, 125000.00, 125000.00),
(9, 9, 'Software', 'Microsoft', 'Windows Server 2022 Standard', '16 Core License Pack', 2, 34000.00, 68000.00),
(10, 10, 'Peripherals', 'APC', 'Smart-UPS 1500VA LCD 230V', 'Battery Backup & Surge Protector', 1, 28500.00, 28500.00),
(11, 11, 'Laptops', 'HP', 'EliteBook 840 G9 Notebook', 'Intel Core i5-1235U, 16GB, 512GB SSD', 2, 66000.00, 132000.00),
(12, 12, 'Printers', 'Brother', 'MFC-L8900CDW Laser Printer', 'Business Color Laser All-in-One', 2, 47000.00, 94000.00),
(13, 13, 'Desktops', 'Dell', 'OptiPlex 7090 Micro Form Factor', 'Intel Core i7, 16GB RAM, 512GB SSD', 1, 48500.00, 48500.00),
(14, 14, 'CCTV', 'Dahua', '4MP Lite IR Vari-focal Dome', 'Network Camera, H.265+', 2, 6000.00, 12000.00),
(15, 15, 'Software', 'Adobe', 'Creative Cloud Teams (Annual)', 'Full App Suite Subscription', 3, 60000.00, 180000.00),
(16, 16, 'Networking', 'MikroTik', 'Cloud Core Router CCR2004', '12x 10G SFP+ and 2x 25G SFP28', 1, 66500.00, 66500.00),
(17, 17, 'Laptops', 'Apple', 'MacBook Pro 14-inch M2 Pro', '10-core CPU, 16-core GPU, 16GB RAM', 2, 130000.00, 260000.00),
(18, 18, 'Desktops', 'Lenovo', 'ThinkCentre M70q Tiny', 'Intel Core i5, 8GB RAM, 256GB SSD', 4, 27500.00, 110000.00),
(19, 19, 'Peripherals', 'ViewSonic', 'VG2455 24\" IPS Monitor', '1080p, USB-C, Ergonomic Stand', 5, 11100.00, 55500.00),
(20, 20, 'Peripherals', 'Jabra', 'Evolve2 65 UC Wireless Headset', 'Noise-canceling, USB-A adapter', 2, 9500.00, 19000.00),
(21, 21, 'Servers', 'HP', 'ProLiant DL380 Gen10 Server', 'Intel Xeon Gold, 128GB RAM', 1, 216000.00, 216000.00),
(22, 22, 'Software', 'Autodesk', 'AutoCAD 2024 Commercial', '1-Year Subscription', 1, 85500.00, 85500.00),
(23, 23, 'Laptops', 'Dell', 'XPS 15 9520 Workstation', 'Intel Core i9, 32GB RAM, 1TB SSD', 3, 130000.00, 390000.00),
(24, 24, 'CCTV', 'Uniview', '32-Channel 4K NVR System', 'With 16x 4MP Turret Cameras, 8TB HDD', 1, 135000.00, 135000.00),
(25, 25, 'Networking', 'Fortinet', 'FortiGate 60F Firewall', 'Hardware + 1yr Unified Threat Protection', 1, 62500.00, 62500.00),
(26, 26, 'Printers', 'HP', 'LaserJet Pro M404dw', 'Wireless Monochrome Printer', 2, 12000.00, 24000.00),
(27, 27, 'Laptops', 'Lenovo', 'ThinkPad E14 Gen 4', 'Intel Core i5, 8GB RAM, 512GB SSD', 6, 48000.00, 288000.00),
(28, 28, 'Peripherals', 'Epson', 'EB-X51 XGA 3LCD Projector', '3800 Lumens, HDMI, Portable', 3, 35000.00, 105000.00),
(29, 29, 'Servers', 'Dell', 'PowerEdge T440 Tower Server', 'Intel Xeon Silver, 32GB RAM', 2, 262500.00, 525000.00),
(30, 30, 'Networking', 'Aruba', 'Instant On 1930 24G 4SFP/SFP+', 'Smart-Managed Switch', 4, 40000.00, 160000.00),
(31, 31, 'Office Supplies', 'Brother', 'TN-3448 Toner Cartridge', 'High Yield Black Toner', 5, 6000.00, 30000.00),
(32, 32, 'CCTV', 'Hikvision', 'AcuSense 8MP Bullet Camera', 'DarkFighter, Strobe Light & Audio Alarm', 4, 19000.00, 76000.00),
(33, 33, 'Laptops', 'HP', 'ProBook 450 G9 Notebook', 'Intel Core i7, 16GB, 512GB', 6, 60000.00, 360000.00),
(34, 34, 'Peripherals', 'Synology', 'DiskStation DS923+ NAS', '4-Bay Network Attached Storage', 2, 72500.00, 145000.00),
(35, 35, 'Networking', 'Ubiquiti', 'UniFi Dream Machine Pro', 'Enterprise Security Gateway & Network Appliance', 2, 35750.00, 71500.00),
(36, 36, 'Peripherals', 'APC', 'Back-UPS Pro 1500VA', 'BR1500G UPS System', 2, 13500.00, 27000.00),
(37, 37, 'Desktops', 'Apple', 'Mac Studio M2 Max', '12-core CPU, 30-core GPU, 32GB RAM', 2, 120000.00, 240000.00),
(38, 38, 'Printers', 'Canon', 'imageCLASS LBP236dw', 'Monochrome Laser Printer', 5, 19000.00, 95000.00),
(39, 39, 'Software', 'Microsoft', 'Office LTSC Standard 2021', 'Volume License', 3, 15833.33, 47500.00),
(40, 40, 'Peripherals', 'Logitech', 'Brio 4K Webcam', 'Ultra HD Web Camera for Video Conferencing', 2, 9000.00, 18000.00),
(41, 41, 'CCTV', 'Dahua', '8-Channel TiOC 2.0 Package', 'Full Color, Active Deterrence', 2, 48000.00, 96000.00),
(42, 42, 'Office Supplies', 'Epson', '003 Ink Bottles Full Set', 'CMYK Ink Refill', 15, 2400.00, 36000.00),
(43, 43, 'Laptops', 'Lenovo', 'ThinkPad L13 Yoga Gen 3', '2-in-1, Intel Core i5, 16GB RAM', 4, 78000.00, 312000.00),
(44, 44, 'Networking', 'Cisco', 'Meraki MR46 Wi-Fi 6 AP', 'Cloud Managed Indoor Access Point', 2, 62500.00, 125000.00),
(45, 45, 'Peripherals', 'Dell', 'UltraSharp 27 4K USB-C Hub Monitor', 'U2723QE IPS Black Technology', 2, 30750.00, 61500.00),
(46, 46, 'Peripherals', 'Poly', 'Voyager Focus 2 UC', 'Bluetooth Headset with Charging Stand', 2, 10500.00, 21000.00),
(47, 47, 'Servers', 'Lenovo', 'ThinkSystem SR630 V2', '1U Rack Server, 1x Xeon Silver', 1, 108000.00, 108000.00),
(48, 48, 'Software', 'VMware', 'vSphere Essentials Plus Kit', '1 Year Support and Subscription', 1, 45000.00, 45000.00),
(49, 49, 'Laptops', 'Dell', 'Latitude 5530 Business Laptop', '15.6\", i7-1255U, 16GB', 5, 78000.00, 390000.00),
(50, 50, 'Networking', 'Palo Alto', 'PA-440 Next-Gen Firewall', 'Appliance only', 1, 155000.00, 155000.00),
(51, 51, 'Peripherals', 'Seagate', 'IronWolf Pro 16TB NAS HDD', '7200 RPM, SATA 6Gb/s', 3, 25500.00, 76500.00),
(52, 52, 'CCTV', 'Hikvision', 'Face Recognition Terminal', 'MinMoe Touch-Free Access Control', 1, 24000.00, 24000.00),
(53, 53, 'Desktops', 'HP', 'EliteDesk 800 G6 SFF', 'Core i7, 16GB, 512GB SSD', 3, 40000.00, 120000.00),
(54, 54, 'Printers', 'Brother', 'HL-L2370DW Compact Laser', 'Monochrome, Wireless', 6, 9000.00, 54000.00),
(55, 55, 'Servers', 'Dell', 'PowerVault ME5012', 'SAN Storage Array', 1, 455000.00, 455000.00),
(56, 56, 'Networking', 'Ruckus', 'R550 Indoor Wi-Fi 6 AP', 'Dual-band 802.11ax', 4, 46250.00, 185000.00),
(57, 57, 'Software', 'Veeam', 'Backup & Replication V11', 'Enterprise Plus, 1 Socket', 1, 91500.00, 91500.00),
(58, 58, 'Office Supplies', 'HP', '65A Black Original LaserJet Toner', 'CF265A', 3, 9000.00, 27000.00),
(59, 59, 'Laptops', 'Lenovo', 'ThinkBook 15 Gen 4', 'Core i5, 8GB, 256GB SSD', 4, 36000.00, 144000.00),
(60, 60, 'Peripherals', 'BenQ', 'GW2480T 24\" IPS Monitor', 'Eye-care, Ergonomic', 7, 9000.00, 63000.00),
(61, 61, 'Networking', 'Cisco', 'C9200L-48P-4G-E', 'Catalyst 9200L 48-port PoE+', 2, 260000.00, 520000.00),
(62, 62, 'CCTV', 'Uniview', 'Thermal & Optical Bi-spectrum', 'Network Bullet Camera', 1, 215000.00, 215000.00),
(63, 63, 'Servers', 'HP', 'ProLiant MicroServer Gen10 Plus', 'Compact Entry-level Server', 2, 53250.00, 106500.00),
(64, 64, 'Software', 'Kaspersky', 'Endpoint Security for Business', 'Select Tier, 25 Nodes', 1, 30000.00, 30000.00),
(65, 65, 'Laptops', 'Apple', 'MacBook Air M2', '8-core CPU, 8-core GPU, 8GB RAM', 3, 56000.00, 168000.00),
(66, 66, 'Peripherals', 'Wacom', 'Cintiq 16 Pen Display', 'Creative Pen Display', 2, 36000.00, 72000.00),
(67, 67, 'Desktops', 'Lenovo', 'IdeaCentre AIO 3i', 'All-in-One PC, 24\", Core i5', 10, 58500.00, 585000.00),
(68, 68, 'Networking', 'MikroTik', 'NetMetal 5SHP', 'Outdoor Wireless AP', 5, 49000.00, 245000.00),
(69, 69, 'Printers', 'Epson', 'WorkForce Pro WF-C5790', 'Network Color AIO Printer', 3, 40500.00, 121500.00),
(70, 70, 'Peripherals', 'Elgato', 'Stream Deck XL', 'Advanced Studio Controller', 3, 11000.00, 33000.00),
(101, 117, '01', 'Lenovo', 'example', 'example', 100, 1000.00, 100000.00),
(102, 118, '02', 'HP', 'faf', 'fafa', 14, 20000.00, 280000.00),
(103, 119, '01', 'Generic/Other', 'example', 'example', 20, 1000.00, 20000.00),
(104, 120, '01', 'Generic/Other', 'PLDT', '500 MBPS', 25, 1700.00, 42500.00),
(105, 121, '01', 'Generic/Other', 'example', 'example', 10, 1000.00, 10000.00),
(106, 122, '01', 'HP', 'example', 'exa,ple', 10, 1000.00, 10000.00),
(107, 123, '01', 'Lenovo', 'example', 'gfhsfh', 9, 2000.00, 18000.00);

-- --------------------------------------------------------

--
-- Table structure for table `pr_items`
--

CREATE TABLE `pr_items` (
  `item_id` int(11) NOT NULL,
  `pr_id` int(11) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `specifications` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pr_items`
--

INSERT INTO `pr_items` (`item_id`, `pr_id`, `category`, `brand`, `item_name`, `specifications`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 1, 'Laptops', 'Lenovo', 'ThinkPad T14 Gen 3 Corporate', 'Intel Core i7-1260P, 16GB DDR4, 512GB SSD', 5, 52000.00, 260000.00),
(2, 2, 'CCTV', 'Hikvision', '8-Channel 4K NVR CCTV Package', '8-Ch NVR, 4x 5MP Bullet, 4x Dome', 4, 18750.00, 75000.00),
(3, 3, 'Peripherals', 'Logitech', 'MX Master 3S Wireless Combo', 'Quiet tactile typing, MagSpeed scrolling', 5, 8500.00, 42500.00),
(4, 4, 'Office Supplies', 'HP', 'HP 652 Original Ink Cartridges', 'Black & Tri-color combo packs (Bulk)', 10, 1500.00, 15000.00),
(5, 5, 'Networking', 'Ubiquiti', 'UniFi 6 Long-Range Access Point', 'WiFi 6, 4x4 MU-MIMO, PoE+ powered', 12, 12000.00, 144000.00),
(6, 6, 'Printers', 'Epson', 'EcoTank L3250 Wi-Fi AIO Printer', 'Print, Scan, Copy. Ultra-high yield', 6, 9500.00, 57000.00),
(7, 7, 'Servers', 'Dell', 'PowerEdge R740 Rack Server', 'Intel Xeon Silver 4210R, 64GB RAM', 1, 325000.00, 325000.00),
(8, 8, 'Networking', 'Cisco', 'Catalyst 9200L 48-port PoE+', 'Network Switch Enterprise License', 1, 125000.00, 125000.00),
(9, 9, 'Software', 'Microsoft', 'Windows Server 2022 Standard', '16 Core License Pack', 2, 34000.00, 68000.00),
(10, 10, 'Peripherals', 'APC', 'Smart-UPS 1500VA LCD 230V', 'Battery Backup & Surge Protector', 1, 28500.00, 28500.00),
(11, 11, 'Laptops', 'HP', 'EliteBook 840 G9 Notebook', 'Intel Core i5-1235U, 16GB, 512GB SSD', 2, 66000.00, 132000.00),
(12, 12, 'Printers', 'Brother', 'MFC-L8900CDW Laser Printer', 'Business Color Laser All-in-One', 2, 47000.00, 94000.00),
(13, 13, 'Desktops', 'Dell', 'OptiPlex 7090 Micro Form Factor', 'Intel Core i7, 16GB RAM, 512GB SSD', 1, 48500.00, 48500.00),
(14, 14, 'CCTV', 'Dahua', '4MP Lite IR Vari-focal Dome', 'Network Camera, H.265+', 2, 6000.00, 12000.00),
(15, 15, 'Software', 'Adobe', 'Creative Cloud Teams (Annual)', 'Full App Suite Subscription', 3, 60000.00, 180000.00),
(16, 16, 'Networking', 'MikroTik', 'Cloud Core Router CCR2004', '12x 10G SFP+ and 2x 25G SFP28', 1, 66500.00, 66500.00),
(17, 17, 'Laptops', 'Apple', 'MacBook Pro 14-inch M2 Pro', '10-core CPU, 16-core GPU, 16GB RAM', 2, 130000.00, 260000.00),
(18, 18, 'Desktops', 'Lenovo', 'ThinkCentre M70q Tiny', 'Intel Core i5, 8GB RAM, 256GB SSD', 4, 27500.00, 110000.00),
(19, 19, 'Peripherals', 'ViewSonic', 'VG2455 24\" IPS Monitor', '1080p, USB-C, Ergonomic Stand', 5, 11100.00, 55500.00),
(20, 20, 'Peripherals', 'Jabra', 'Evolve2 65 UC Wireless Headset', 'Noise-canceling, USB-A adapter', 2, 9500.00, 19000.00),
(21, 21, 'Servers', 'HP', 'ProLiant DL380 Gen10 Server', 'Intel Xeon Gold, 128GB RAM', 1, 216000.00, 216000.00),
(22, 22, 'Software', 'Autodesk', 'AutoCAD 2024 Commercial', '1-Year Subscription', 1, 85500.00, 85500.00),
(23, 23, 'Laptops', 'Dell', 'XPS 15 9520 Workstation', 'Intel Core i9, 32GB RAM, 1TB SSD', 3, 130000.00, 390000.00),
(24, 24, 'CCTV', 'Uniview', '32-Channel 4K NVR System', 'With 16x 4MP Turret Cameras, 8TB HDD', 1, 135000.00, 135000.00),
(25, 25, 'Networking', 'Fortinet', 'FortiGate 60F Firewall', 'Hardware + 1yr Unified Threat Protection', 1, 62500.00, 62500.00),
(26, 26, 'Printers', 'HP', 'LaserJet Pro M404dw', 'Wireless Monochrome Printer', 2, 12000.00, 24000.00),
(27, 27, 'Laptops', 'Lenovo', 'ThinkPad E14 Gen 4', 'Intel Core i5, 8GB RAM, 512GB SSD', 6, 48000.00, 288000.00),
(28, 28, 'Peripherals', 'Epson', 'EB-X51 XGA 3LCD Projector', '3800 Lumens, HDMI, Portable', 3, 35000.00, 105000.00),
(29, 29, 'Servers', 'Dell', 'PowerEdge T440 Tower Server', 'Intel Xeon Silver, 32GB RAM', 2, 262500.00, 525000.00),
(30, 30, 'Networking', 'Aruba', 'Instant On 1930 24G 4SFP/SFP+', 'Smart-Managed Switch', 4, 40000.00, 160000.00),
(31, 31, 'Office Supplies', 'Brother', 'TN-3448 Toner Cartridge', 'High Yield Black Toner', 5, 6000.00, 30000.00),
(32, 32, 'CCTV', 'Hikvision', 'AcuSense 8MP Bullet Camera', 'DarkFighter, Strobe Light & Audio Alarm', 4, 19000.00, 76000.00),
(33, 33, 'Laptops', 'HP', 'ProBook 450 G9 Notebook', 'Intel Core i7, 16GB, 512GB', 6, 60000.00, 360000.00),
(34, 34, 'Peripherals', 'Synology', 'DiskStation DS923+ NAS', '4-Bay Network Attached Storage', 2, 72500.00, 145000.00),
(35, 35, 'Networking', 'Ubiquiti', 'UniFi Dream Machine Pro', 'Enterprise Security Gateway & Network Appliance', 2, 35750.00, 71500.00),
(36, 36, 'Peripherals', 'APC', 'Back-UPS Pro 1500VA', 'BR1500G UPS System', 2, 13500.00, 27000.00),
(37, 37, 'Desktops', 'Apple', 'Mac Studio M2 Max', '12-core CPU, 30-core GPU, 32GB RAM', 2, 120000.00, 240000.00),
(38, 38, 'Printers', 'Canon', 'imageCLASS LBP236dw', 'Monochrome Laser Printer', 5, 19000.00, 95000.00),
(39, 39, 'Software', 'Microsoft', 'Office LTSC Standard 2021', 'Volume License', 3, 15833.33, 47500.00),
(40, 40, 'Peripherals', 'Logitech', 'Brio 4K Webcam', 'Ultra HD Web Camera for Video Conferencing', 2, 9000.00, 18000.00),
(41, 41, 'CCTV', 'Dahua', '8-Channel TiOC 2.0 Package', 'Full Color, Active Deterrence', 2, 48000.00, 96000.00),
(42, 42, 'Office Supplies', 'Epson', '003 Ink Bottles Full Set', 'CMYK Ink Refill', 15, 2400.00, 36000.00),
(43, 43, 'Laptops', 'Lenovo', 'ThinkPad L13 Yoga Gen 3', '2-in-1, Intel Core i5, 16GB RAM', 4, 78000.00, 312000.00),
(44, 44, 'Networking', 'Cisco', 'Meraki MR46 Wi-Fi 6 AP', 'Cloud Managed Indoor Access Point', 2, 62500.00, 125000.00),
(45, 45, 'Peripherals', 'Dell', 'UltraSharp 27 4K USB-C Hub Monitor', 'U2723QE IPS Black Technology', 2, 30750.00, 61500.00),
(46, 46, 'Peripherals', 'Poly', 'Voyager Focus 2 UC', 'Bluetooth Headset with Charging Stand', 2, 10500.00, 21000.00),
(47, 47, 'Servers', 'Lenovo', 'ThinkSystem SR630 V2', '1U Rack Server, 1x Xeon Silver', 1, 108000.00, 108000.00),
(48, 48, 'Software', 'VMware', 'vSphere Essentials Plus Kit', '1 Year Support and Subscription', 1, 45000.00, 45000.00),
(49, 49, 'Laptops', 'Dell', 'Latitude 5530 Business Laptop', '15.6\", i7-1255U, 16GB', 5, 78000.00, 390000.00),
(50, 50, 'Networking', 'Palo Alto', 'PA-440 Next-Gen Firewall', 'Appliance only', 1, 155000.00, 155000.00),
(51, 51, 'Peripherals', 'Seagate', 'IronWolf Pro 16TB NAS HDD', '7200 RPM, SATA 6Gb/s', 3, 25500.00, 76500.00),
(52, 52, 'CCTV', 'Hikvision', 'Face Recognition Terminal', 'MinMoe Touch-Free Access Control', 1, 24000.00, 24000.00),
(53, 53, 'Desktops', 'HP', 'EliteDesk 800 G6 SFF', 'Core i7, 16GB, 512GB SSD', 3, 40000.00, 120000.00),
(54, 54, 'Printers', 'Brother', 'HL-L2370DW Compact Laser', 'Monochrome, Wireless', 6, 9000.00, 54000.00),
(55, 55, 'Servers', 'Dell', 'PowerVault ME5012', 'SAN Storage Array', 1, 455000.00, 455000.00),
(56, 56, 'Networking', 'Ruckus', 'R550 Indoor Wi-Fi 6 AP', 'Dual-band 802.11ax', 4, 46250.00, 185000.00),
(57, 57, 'Software', 'Veeam', 'Backup & Replication V11', 'Enterprise Plus, 1 Socket', 1, 91500.00, 91500.00),
(58, 58, 'Office Supplies', 'HP', '65A Black Original LaserJet Toner', 'CF265A', 3, 9000.00, 27000.00),
(59, 59, 'Laptops', 'Lenovo', 'ThinkBook 15 Gen 4', 'Core i5, 8GB, 256GB SSD', 4, 36000.00, 144000.00),
(60, 60, 'Peripherals', 'BenQ', 'GW2480T 24\" IPS Monitor', 'Eye-care, Ergonomic', 7, 9000.00, 63000.00),
(61, 61, 'Networking', 'Cisco', 'C9200L-48P-4G-E', 'Catalyst 9200L 48-port PoE+', 2, 260000.00, 520000.00),
(62, 62, 'CCTV', 'Uniview', 'Thermal & Optical Bi-spectrum', 'Network Bullet Camera', 1, 215000.00, 215000.00),
(63, 63, 'Servers', 'HP', 'ProLiant MicroServer Gen10 Plus', 'Compact Entry-level Server', 2, 53250.00, 106500.00),
(64, 64, 'Software', 'Kaspersky', 'Endpoint Security for Business', 'Select Tier, 25 Nodes', 1, 30000.00, 30000.00),
(65, 65, 'Laptops', 'Apple', 'MacBook Air M2', '8-core CPU, 8-core GPU, 8GB RAM', 3, 56000.00, 168000.00),
(66, 66, 'Peripherals', 'Wacom', 'Cintiq 16 Pen Display', 'Creative Pen Display', 2, 36000.00, 72000.00),
(67, 67, 'Desktops', 'Lenovo', 'IdeaCentre AIO 3i', 'All-in-One PC, 24\", Core i5', 10, 58500.00, 585000.00),
(68, 68, 'Networking', 'MikroTik', 'NetMetal 5SHP', 'Outdoor Wireless AP', 5, 49000.00, 245000.00),
(69, 69, 'Printers', 'Epson', 'WorkForce Pro WF-C5790', 'Network Color AIO Printer', 3, 40500.00, 121500.00),
(70, 70, 'Peripherals', 'Elgato', 'Stream Deck XL', 'Advanced Studio Controller', 3, 11000.00, 33000.00),
(71, 71, 'CCTV', 'Dahua', 'ANPR Camera System', 'Automatic Number Plate Recognition', 2, 96000.00, 192000.00),
(72, 72, 'Software', 'Slack', 'Enterprise Grid License', 'Annual per user (50 pax)', 1, 81000.00, 81000.00),
(73, 73, 'Servers', 'Dell', 'PowerEdge R650xs Rack Server', '1U, Dual Socket', 2, 325000.00, 650000.00),
(74, 74, 'Laptops', 'HP', 'ZBook Firefly 14 G9', 'Mobile Workstation', 3, 91666.67, 275000.00),
(75, 75, 'Peripherals', 'Samsung', '980 PRO 2TB PCIe NVMe Gen4', 'M.2 Internal SSD', 9, 15166.67, 136500.00),
(76, 76, 'Office Supplies', 'Brother', 'DR-3455 Drum Unit', 'Yields approx. 30,000 pages', 6, 6000.00, 36000.00),
(77, 77, 'Networking', 'Aruba', 'CX 6000 48G Class4 PoE', 'Access Switch', 2, 108000.00, 216000.00),
(78, 78, 'Peripherals', 'Logitech', 'Rally Bar Mini', 'All-in-One Video Bar', 1, 90000.00, 90000.00),
(79, 79, 'CCTV', 'Hikvision', 'PanVu 360 Panoramic Camera', 'Multisensor Network Camera', 1, 715000.00, 715000.00),
(80, 80, 'Desktops', 'Dell', 'Precision 3660 Tower', 'Workstation PC, Core i9', 3, 101666.67, 305000.00),
(81, 81, 'Software', 'Zoom', 'Zoom Rooms License', 'Annual Subscription', 5, 30300.00, 151500.00),
(82, 82, 'Printers', 'HP', 'DesignJet T230 24-in', 'Large Format Printer', 1, 39000.00, 39000.00),
(83, 83, 'Servers', 'Lenovo', 'ThinkSystem DM5000H', 'Hybrid Storage Array', 1, 240000.00, 240000.00),
(84, 84, 'Networking', 'Fortinet', 'FortiAP 431F', 'Wi-Fi 6 Indoor AP', 3, 33000.00, 99000.00),
(85, 85, 'Laptops', 'Apple', 'MacBook Pro 16-inch M2 Max', '12-core CPU, 38-core GPU', 4, 195000.00, 780000.00),
(86, 86, 'Peripherals', 'APC', 'Symmetra LX 16kVA', 'Scalable UPS System', 1, 335000.00, 335000.00),
(87, 87, 'CCTV', 'Uniview', 'Face Recognition Access Control', 'With Temperature Measurement', 2, 83250.00, 166500.00),
(88, 88, 'Office Supplies', 'Epson', 'T664 Ink Bottles (Bulk Pack)', 'For L-Series Tanks', 20, 2100.00, 42000.00),
(89, 89, 'Networking', 'Cisco', 'ISR 4331 Router', 'Integrated Services Router', 2, 132000.00, 264000.00),
(90, 90, 'Peripherals', 'Yeti', 'Blue Yeti X USB Microphone', 'Professional Condenser Mic', 8, 13500.00, 108000.00),
(91, 91, 'Servers', 'Dell', 'PowerEdge R750', '2U Rack Server, Dual Xeon', 2, 422500.00, 845000.00),
(92, 92, 'Desktops', 'HP', 'Z2 Mini G9 Workstation', 'Compact Workstation', 5, 73000.00, 365000.00),
(93, 93, 'Software', 'Microsoft', 'SQL Server 2022 Standard', 'Per Core License', 2, 90750.00, 181500.00),
(94, 94, 'Printers', 'Brother', 'MFC-J3930DW', 'A3 Inkjet All-in-One Printer', 2, 22500.00, 45000.00),
(95, 95, 'Laptops', 'Lenovo', 'ThinkPad P1 Gen 5', 'Mobile Workstation', 2, 144000.00, 288000.00),
(96, 96, 'Networking', 'Ubiquiti', 'EdgeSwitch 48-Port 500W', 'Managed Gigabit Switch', 2, 58500.00, 117000.00),
(97, 97, 'CCTV', 'Dahua', '16-Channel AI NVR', 'With Face Recognition Support', 5, 182000.00, 910000.00),
(98, 98, 'Peripherals', 'ViewSonic', 'IFP6550 65\" Interactive Display', 'ViewBoard for Education/Business', 2, 197500.00, 395000.00),
(99, 99, 'Office Supplies', 'HP', 'HP 72 130-ml Cyan Ink', 'DesignJet Cartridge', 30, 6550.00, 196500.00),
(100, 100, 'Peripherals', 'Polycom', 'RealPresence Trio 8800', 'IP Conference Phone', 1, 48000.00, 48000.00),
(101, 101, '01', 'Lenovo', 'example', 'example', 100, 1000.00, 100000.00),
(102, 179, '01', 'Lenovo', 'example', 'example', 100, 1000.00, 100000.00),
(103, 180, '01', 'Generic/Other', 'example', 'example', 20, 1000.00, 20000.00),
(104, 181, '05', 'Generic/Other', 'PLDT', '500 MBPS', 25, 1700.00, 42500.00),
(105, 182, '01', 'Generic/Other', 'example', 'example', 10, 1000.00, 10000.00),
(106, 183, '01', 'Lenovo', 'example', 'gfhsfh', 10, 2000.00, 20000.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL,
  `po_number` varchar(50) NOT NULL,
  `client_name` varchar(100) NOT NULL,
  `quotation_number` varchar(50) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('Pending','GM-Approved','Finance-Approved','President-Approved','Funded','For Pick-up/Delivery','Delivered','Partially-Collected','Collected','Rejected','Invalid') DEFAULT 'Pending',
  `is_viewed` tinyint(1) DEFAULT 0,
  `current_location` varchar(50) DEFAULT 'GM',
  `created_by` int(11) DEFAULT NULL,
  `date_created` datetime DEFAULT current_timestamp(),
  `actual_delivery_date` date DEFAULT NULL,
  `expected_collection_date` date DEFAULT NULL,
  `pr_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `po_number`, `client_name`, `quotation_number`, `amount`, `status`, `is_viewed`, `current_location`, `created_by`, `date_created`, `actual_delivery_date`, `expected_collection_date`, `pr_id`) VALUES
(1, 'PO-202511-5000', 'Jollibee Foods', NULL, 184675.00, 'Rejected', 0, 'Supplier / Vendor', 3, '2025-11-05 06:53:26', NULL, '2025-11-28', 3),
(2, 'PO-202511-5001', 'STI College', NULL, 136400.01, 'GM-Approved', 0, 'Finance Dept', 3, '2025-11-06 23:29:14', NULL, '2025-12-02', 5),
(3, 'PO-202511-5002', 'STI College', NULL, 214043.03, 'Funded', 0, 'Procurement Office', 3, '2025-11-09 14:43:02', NULL, '2025-12-05', 6),
(4, 'PO-202511-5003', 'Yazaki-Torres', NULL, 142279.02, 'Rejected', 0, 'President Office', 3, '2025-11-10 13:38:13', NULL, '2025-12-08', 8),
(5, 'PO-202511-5004', 'Toyota Santa Rosa', NULL, 172865.91, 'Collected', 0, 'Delivered to Client', 3, '2025-11-12 12:19:34', NULL, '2025-12-11', 9),
(6, 'PO-202511-5005', 'Jollibee Foods', NULL, 71569.25, 'President-Approved', 0, 'Procurement Office', 3, '2025-11-13 08:11:40', NULL, '2025-12-06', 10),
(7, 'PO-202511-5006', 'Carmelray Admin', NULL, 31362.21, 'Pending', 0, 'Supplier / Vendor', 3, '2025-11-18 06:01:52', NULL, '2025-12-04', 12),
(8, 'PO-202511-5007', 'STI College', NULL, 245410.15, 'Funded', 0, 'Delivered to Client', 3, '2025-11-20 12:05:56', NULL, '2025-12-16', 14),
(9, 'PO-202511-5008', 'STI College', NULL, 304043.13, 'Funded', 0, 'In Transit', 3, '2025-11-21 05:16:37', NULL, '2025-12-16', 15),
(10, 'PO-202511-5009', 'Honda Cars', NULL, 202756.56, 'Funded', 0, 'In Transit', 3, '2025-11-21 08:20:54', NULL, '2025-12-15', 16),
(11, 'PO-202511-5010', 'Toyota Santa Rosa', NULL, 98625.72, 'Finance-Approved', 0, 'Finance Dept', 3, '2025-11-22 19:28:45', NULL, '2025-12-16', 17),
(12, 'PO-202511-5011', 'Jollibee Foods', NULL, 199481.56, 'Finance-Approved', 0, 'GM Desk', 3, '2025-11-24 15:56:37', NULL, '2025-12-17', 18),
(13, 'PO-202511-5012', 'City Govt of Calamba', NULL, 251438.50, 'Rejected', 0, 'In Transit', 3, '2025-11-25 17:17:23', NULL, '2025-12-13', 20),
(14, 'PO-202511-5013', 'City Govt of Calamba', NULL, 221401.65, 'Pending', 0, 'Supplier / Vendor', 3, '2025-11-26 15:34:38', NULL, '2025-12-11', 21),
(15, 'PO-202511-5014', 'City Govt of Calamba', NULL, 236430.02, 'Collected', 0, 'Procurement Office', 3, '2025-11-27 17:09:00', NULL, '2025-12-16', 23),
(16, 'PO-202511-5015', 'STI College', NULL, 258753.40, 'Funded', 0, 'Procurement Office', 3, '2025-12-02 04:28:17', NULL, '2025-12-21', 25),
(17, 'PO-202512-5016', 'Honda Cars', NULL, 212513.02, 'Finance-Approved', 0, 'GM Desk', 3, '2025-12-04 01:24:49', NULL, '2025-12-30', 26),
(18, 'PO-202512-5017', 'STI College', NULL, 172041.71, 'GM-Approved', 0, 'In Transit', 3, '2025-12-03 08:43:45', NULL, '2025-12-20', 27),
(19, 'PO-202512-5018', 'City Govt of Calamba', NULL, 24756.38, 'President-Approved', 0, 'Supplier / Vendor', 3, '2025-12-05 23:30:04', NULL, '2025-12-31', 28),
(20, 'PO-202512-5019', 'Honda Cars', NULL, 109425.07, 'Pending', 0, 'President Office', 3, '2025-12-05 23:08:53', NULL, '2025-12-26', 29),
(21, 'PO-202512-5020', 'Honda Cars', NULL, 293428.23, 'Rejected', 0, 'President Office', 3, '2025-12-07 17:04:35', NULL, '2025-12-29', 30),
(22, 'PO-202512-5021', 'Yazaki-Torres', NULL, 147535.29, 'Funded', 0, 'Procurement Office', 3, '2025-12-10 07:38:16', NULL, '2026-01-02', 32),
(23, 'PO-202512-5022', 'Toyota Santa Rosa', NULL, 95713.25, 'Funded', 0, 'President Office', 3, '2025-12-11 07:38:38', NULL, '2025-12-28', 34),
(24, 'PO-202512-5023', 'Toyota Santa Rosa', NULL, 291071.27, 'Funded', 0, 'GM Desk', 3, '2025-12-10 16:46:23', NULL, '2025-12-26', 35),
(25, 'PO-202512-5024', 'Calamba Medical Center', NULL, 173284.72, 'President-Approved', 0, 'GM Desk', 3, '2025-12-13 06:21:32', NULL, '2026-01-06', 37),
(26, 'PO-202512-5025', 'Jollibee Foods', NULL, 106900.35, 'Pending', 0, 'Delivered to Client', 3, '2025-12-15 03:00:41', NULL, '2026-01-02', 39),
(27, 'PO-202512-5026', 'SM Prime Holdings', NULL, 128065.17, 'Rejected', 0, 'President Office', 3, '2025-12-20 15:45:11', NULL, '2026-01-16', 42),
(28, 'PO-202512-5027', 'LISP 1 Operations', NULL, 19506.66, 'President-Approved', 0, 'Delivered to Client', 3, '2025-12-22 21:31:44', NULL, '2026-01-21', 45),
(29, 'PO-202512-5028', 'SM Prime Holdings', NULL, 137342.13, 'Funded', 0, 'President Office', 3, '2025-12-24 23:40:14', NULL, '2026-01-20', 49),
(30, 'PO-202512-5029', 'City Govt of Calamba', NULL, 17544.21, 'President-Approved', 0, 'Supplier / Vendor', 3, '2025-12-25 03:02:03', NULL, '2026-01-23', 50),
(31, 'PO-202512-5030', 'Honda Cars', NULL, 235505.99, 'Pending', 0, 'Delivered to Client', 3, '2025-12-28 05:45:11', NULL, '2026-01-18', 52),
(32, 'PO-202512-5031', 'Calamba Medical Center', NULL, 59082.05, 'President-Approved', 0, 'Procurement Office', 3, '2025-12-28 03:19:54', NULL, '2026-01-24', 53),
(33, 'PO-202512-5032', 'LISP 1 Operations', NULL, 240451.13, 'Funded', 0, 'Finance Dept', 3, '2025-12-29 23:35:39', NULL, '2026-01-19', 54),
(34, 'PO-202512-5033', 'Jollibee Foods', NULL, 47525.07, 'Collected', 0, 'Supplier / Vendor', 3, '2025-12-29 14:32:10', NULL, '2026-01-16', 55),
(35, 'PO-202512-5034', 'DepEd Laguna', NULL, 308036.22, 'GM-Approved', 0, 'President Office', 3, '2025-12-31 14:59:18', NULL, '2026-01-24', 56),
(36, 'PO-202601-5035', 'STI College', NULL, 342783.46, 'Collected', 0, 'Delivered to Client', 3, '2026-01-09 01:15:19', NULL, '2026-02-05', 61),
(37, 'PO-202601-5036', 'Honda Cars', NULL, 274478.67, 'Pending', 0, 'Delivered to Client', 3, '2026-01-07 11:12:25', NULL, '2026-02-01', 62),
(38, 'PO-202601-5037', 'STI College', NULL, 24963.45, 'Rejected', 0, 'Supplier / Vendor', 3, '2026-01-10 23:33:57', NULL, '2026-02-06', 63),
(39, 'PO-202601-5038', 'DepEd Laguna', NULL, 102474.09, 'Funded', 0, 'In Transit', 3, '2026-01-12 09:42:46', NULL, '2026-02-04', 64),
(40, 'PO-202601-5039', 'LISP 1 Operations', NULL, 115657.52, 'Pending', 0, 'President Office', 3, '2026-01-15 18:37:29', NULL, '2026-01-30', 66),
(41, 'PO-202601-5040', 'Honda Cars', NULL, 26188.90, 'Funded', 0, 'GM Desk', 3, '2026-01-14 21:57:50', NULL, '2026-02-05', 67),
(42, 'PO-202601-5041', 'STI College', NULL, 187715.70, 'Funded', 0, 'Supplier / Vendor', 3, '2026-01-15 19:17:45', NULL, '2026-02-07', 68),
(43, 'PO-202601-5042', 'DepEd Laguna', NULL, 93779.75, 'GM-Approved', 0, 'In Transit', 3, '2026-01-16 20:43:32', NULL, '2026-02-03', 69),
(44, 'PO-202601-5043', 'Carmelray Admin', NULL, 64950.63, 'Finance-Approved', 0, 'Delivered to Client', 3, '2026-01-17 14:10:24', NULL, '2026-02-03', 70),
(45, 'PO-202601-5044', 'Toyota Santa Rosa', NULL, 118884.60, 'President-Approved', 0, 'GM Desk', 3, '2026-01-17 18:04:37', NULL, '2026-02-15', 71),
(46, 'PO-202601-5045', 'Calamba Medical Center', NULL, 308587.09, 'Collected', 0, 'GM Desk', 3, '2026-01-19 06:07:01', NULL, '2026-02-14', 72),
(47, 'PO-202601-5046', 'LISP 1 Operations', NULL, 335756.98, 'President-Approved', 0, 'Supplier / Vendor', 3, '2026-01-25 04:59:34', NULL, '2026-02-14', 73),
(48, 'PO-202601-5047', 'LISP 1 Operations', NULL, 82283.59, 'Collected', 0, 'Delivered to Client', 3, '2026-01-25 07:47:22', NULL, '2026-02-19', 74),
(49, 'PO-202601-5048', 'Yazaki-Torres', NULL, 303869.21, 'Pending', 0, 'Finance Dept', 3, '2026-01-25 12:29:07', NULL, '2026-02-24', 75),
(50, 'PO-202601-5049', 'Honda Cars', NULL, 149033.85, 'Funded', 0, 'Delivered to Client', 3, '2026-01-26 04:20:32', NULL, '2026-02-19', 76),
(51, 'PO-202601-5050', 'City Govt of Calamba', NULL, 274961.68, 'GM-Approved', 0, 'President Office', 3, '2026-01-26 13:17:19', NULL, '2026-02-15', 77),
(52, 'PO-202601-5051', 'SM Prime Holdings', NULL, 145730.00, 'Pending', 0, 'Delivered to Client', 3, '2026-01-29 05:32:48', NULL, '2026-02-19', 78),
(53, 'PO-202601-5052', 'STI College', NULL, 99437.93, 'Collected', 0, 'Delivered to Client', 3, '2026-01-31 02:17:31', NULL, '2026-02-22', 81),
(54, 'PO-202601-5053', 'Carmelray Admin', NULL, 284575.39, 'Finance-Approved', 0, 'Supplier / Vendor', 3, '2026-01-31 15:53:30', NULL, '2026-02-26', 82),
(55, 'PO-202601-5054', 'Jollibee Foods', NULL, 310722.69, 'Pending', 0, 'Supplier / Vendor', 3, '2026-02-01 13:48:41', NULL, '2026-02-20', 83),
(56, 'PO-202602-5055', 'Carmelray Admin', NULL, 21034.00, 'Finance-Approved', 0, 'Procurement Office', 3, '2026-02-02 19:19:09', NULL, '2026-02-20', 86),
(57, 'PO-202602-5056', 'Carmelray Admin', NULL, 63999.96, 'Finance-Approved', 0, 'President Office', 3, '2026-02-04 00:19:01', NULL, '2026-02-22', 87),
(58, 'PO-202602-5057', 'DepEd Laguna', NULL, 285248.47, 'President-Approved', 0, 'Procurement Office', 3, '2026-02-04 05:30:36', NULL, '2026-03-03', 88),
(59, 'PO-202602-5058', 'Honda Cars', NULL, 345498.14, 'President-Approved', 0, 'In Transit', 3, '2026-02-05 01:22:02', NULL, '2026-02-25', 90),
(60, 'PO-202602-5059', 'SM Prime Holdings', NULL, 161453.08, 'Collected', 0, 'President Office', 3, '2026-02-05 23:04:31', NULL, '2026-03-02', 92),
(61, 'PO-202602-5060', 'Honda Cars', NULL, 341231.16, 'GM-Approved', 0, 'Delivered to Client', 3, '2026-02-12 05:04:06', NULL, '2026-03-07', 93),
(62, 'PO-202602-5061', 'City Govt of Calamba', NULL, 317347.32, 'President-Approved', 0, 'President Office', 3, '2026-02-14 12:54:36', NULL, '2026-03-07', 95),
(63, 'PO-202602-5062', 'Carmelray Admin', NULL, 194579.47, 'Pending', 0, 'Supplier / Vendor', 3, '2026-02-17 18:33:47', NULL, '2026-03-12', 97),
(64, 'PO-202602-5063', 'Toyota Santa Rosa', NULL, 113878.62, 'Pending', 0, 'In Transit', 3, '2026-02-19 07:07:25', NULL, '2026-03-20', 98),
(65, 'PO-202602-5064', 'City Govt of Calamba', NULL, 212815.56, 'Funded', 0, 'Procurement Office', 3, '2026-02-20 22:50:05', NULL, '2026-03-08', 102),
(66, 'PO-202602-5065', 'LISP 1 Operations', NULL, 218911.53, 'President-Approved', 0, 'Delivered to Client', 3, '2026-02-22 05:07:38', NULL, '2026-03-15', 103),
(67, 'PO-202602-5066', 'Carmelray Admin', NULL, 74346.13, 'President-Approved', 0, 'In Transit', 3, '2026-02-23 13:03:06', NULL, '2026-03-11', 104),
(68, 'PO-202602-5067', 'Yazaki-Torres', NULL, 283222.10, 'Collected', 0, 'GM Desk', 3, '2026-02-24 09:10:07', NULL, '2026-03-22', 106),
(69, 'PO-202602-5068', 'STI College', NULL, 293337.26, 'Collected', 0, 'Supplier / Vendor', 3, '2026-02-25 23:03:01', NULL, '2026-03-16', 108),
(70, 'PO-202602-5069', 'DepEd Laguna', NULL, 74736.91, 'Rejected', 0, 'Supplier / Vendor', 3, '2026-02-25 08:13:12', NULL, '2026-03-13', 109),
(71, 'PO-202602-5070', 'Toyota Santa Rosa', NULL, 154606.29, 'Finance-Approved', 0, 'Finance Dept', 3, '2026-02-26 04:27:32', NULL, '2026-03-25', 110),
(72, 'PO-202602-5071', 'Toyota Santa Rosa', NULL, 308623.15, 'Finance-Approved', 0, 'Finance Dept', 3, '2026-02-28 08:10:04', NULL, '2026-03-19', 111),
(73, 'PO-202602-5072', 'Toyota Santa Rosa', NULL, 189433.71, 'Pending', 0, 'Procurement Office', 3, '2026-02-27 20:39:07', NULL, '2026-03-28', 112),
(74, 'PO-202602-5073', 'City Govt of Calamba', NULL, 39069.41, 'President-Approved', 0, 'GM Desk', 3, '2026-02-28 10:38:36', NULL, '2026-03-21', 115),
(75, 'PO-202603-5074', 'LISP 1 Operations', NULL, 155732.77, 'Funded', 0, 'In Transit', 3, '2026-03-04 09:43:03', NULL, '2026-03-20', 117),
(76, 'PO-202603-5075', 'LISP 1 Operations', NULL, 205418.57, 'Rejected', 0, 'President Office', 3, '2026-03-04 16:35:50', NULL, '2026-03-24', 118),
(77, 'PO-202603-5076', 'Nestle Cabuyao', NULL, 240913.87, 'Rejected', 0, 'Procurement Office', 3, '2026-03-03 19:48:15', NULL, '2026-03-30', 119),
(78, 'PO-202603-5077', 'Yazaki-Torres', NULL, 238291.19, 'Rejected', 0, 'GM Desk', 3, '2026-03-07 14:13:57', NULL, '2026-03-26', 120),
(79, 'PO-202603-5078', 'City Govt of Calamba', NULL, 164906.10, 'Finance-Approved', 0, 'GM Desk', 3, '2026-03-07 04:19:50', NULL, '2026-03-26', 121),
(80, 'PO-202603-5079', 'DepEd Laguna', NULL, 107974.77, 'Pending', 0, 'Finance Dept', 3, '2026-03-08 07:31:15', NULL, '2026-03-24', 122),
(81, 'PO-202603-5080', 'City Govt of Calamba', NULL, 276667.48, 'Funded', 0, 'Finance Dept', 3, '2026-03-08 05:24:03', NULL, '2026-04-02', 123),
(82, 'PO-202603-5081', 'Yazaki-Torres', NULL, 305710.00, 'Rejected', 0, 'GM Desk', 3, '2026-03-08 13:01:14', NULL, '2026-03-27', 124),
(83, 'PO-202603-5082', 'Carmelray Admin', NULL, 227608.78, 'Collected', 0, 'GM Desk', 3, '2026-03-09 06:02:17', NULL, '2026-03-28', 125),
(84, 'PO-202603-5083', 'Carmelray Admin', NULL, 248138.91, 'GM-Approved', 0, 'Finance Dept', 3, '2026-03-09 11:08:41', NULL, '2026-03-30', 126),
(85, 'PO-202603-5084', 'Carmelray Admin', NULL, 282134.04, 'Rejected', 0, 'Delivered to Client', 3, '2026-03-10 23:17:06', NULL, '2026-03-30', 127),
(86, 'PO-202603-5085', 'Calamba Medical Center', NULL, 114940.22, 'President-Approved', 0, 'In Transit', 3, '2026-03-12 21:35:31', NULL, '2026-04-05', 128),
(87, 'PO-202603-5086', 'Calamba Medical Center', NULL, 283073.54, 'Funded', 0, 'In Transit', 3, '2026-03-11 10:48:34', NULL, '2026-04-05', 129),
(88, 'PO-202603-5087', 'Honda Cars', NULL, 166253.45, 'Funded', 0, 'President Office', 3, '2026-03-14 14:56:43', NULL, '2026-04-09', 130),
(89, 'PO-202603-5088', 'Carmelray Admin', NULL, 238951.24, 'Finance-Approved', 0, 'Procurement Office', 3, '2026-03-14 20:00:40', NULL, '2026-04-03', 132),
(90, 'PO-202603-5089', 'Toyota Santa Rosa', NULL, 133431.89, 'President-Approved', 0, 'Finance Dept', 3, '2026-03-23 21:01:25', NULL, '2026-04-19', 133),
(91, 'PO-202603-5090', 'Nestle Cabuyao', NULL, 232739.77, 'GM-Approved', 0, 'Procurement Office', 3, '2026-03-25 12:06:07', NULL, '2026-04-09', 135),
(92, 'PO-202603-5091', 'Jollibee Foods', NULL, 199362.26, 'Rejected', 0, 'Delivered to Client', 3, '2026-03-26 18:49:18', NULL, '2026-04-20', 136),
(93, 'PO-202603-5092', 'Yazaki-Torres', NULL, 91887.54, 'Pending', 0, 'Supplier / Vendor', 3, '2026-03-26 19:35:56', NULL, '2026-04-16', 137),
(94, 'PO-202603-5093', 'Nestle Cabuyao', NULL, 296113.51, 'President-Approved', 0, 'Procurement Office', 3, '2026-03-29 04:27:30', NULL, '2026-04-14', 140),
(95, 'PO-202603-5094', 'DepEd Laguna', NULL, 243854.47, 'Collected', 0, 'GM Desk', 3, '2026-03-31 15:17:18', NULL, '2026-04-20', 147),
(96, 'PO-202604-5095', 'STI College', NULL, 32378.76, 'Finance-Approved', 0, 'Procurement Office', 3, '2026-04-06 09:46:47', NULL, '2026-05-01', 152),
(97, 'PO-202604-5096', 'Calamba Medical Center', NULL, 232421.27, 'Pending', 0, 'Finance Dept', 3, '2026-04-06 06:11:46', NULL, '2026-05-04', 153),
(98, 'PO-202604-5097', 'Honda Cars', NULL, 285637.84, 'Collected', 0, 'Finance Dept', 3, '2026-04-07 00:12:41', NULL, '2026-04-22', 154),
(99, 'PO-202604-5098', 'Carmelray Admin', NULL, 108854.20, 'President-Approved', 0, 'In Transit', 3, '2026-04-06 18:51:01', NULL, '2026-05-02', 155),
(100, 'PO-202604-5099', 'DepEd Laguna', NULL, 237090.48, 'President-Approved', 0, 'Supplier / Vendor', 3, '2026-04-07 14:31:29', NULL, '2026-05-07', 156),
(101, 'PO-202604-5100', 'Honda Cars', NULL, 240132.44, 'GM-Approved', 0, 'Supplier / Vendor', 3, '2026-04-08 02:49:27', NULL, '2026-05-01', 157),
(102, 'PO-202604-5101', 'DepEd Laguna', NULL, 218119.53, 'Rejected', 0, 'Finance Dept', 3, '2026-04-09 04:28:32', NULL, '2026-04-27', 158),
(103, 'PO-202604-5102', 'Honda Cars', NULL, 113467.82, 'Funded', 0, 'Procurement Office', 3, '2026-04-10 15:33:46', NULL, '2026-04-29', 161),
(104, 'PO-202604-5103', 'STI College', NULL, 258206.00, 'Funded', 0, 'President Office', 3, '2026-04-11 12:11:33', NULL, '2026-04-26', 162),
(105, 'PO-202604-5104', 'DepEd Laguna', NULL, 46670.30, 'Rejected', 0, 'Procurement Office', 3, '2026-04-10 22:58:58', NULL, '2026-04-28', 164),
(106, 'PO-202604-5105', 'Yazaki-Torres', NULL, 262822.61, 'Finance-Approved', 0, 'President Office', 3, '2026-04-12 12:38:50', NULL, '2026-04-28', 165),
(107, 'PO-202604-5106', 'Honda Cars', NULL, 216995.92, 'GM-Approved', 0, 'Supplier / Vendor', 3, '2026-04-12 12:00:11', NULL, '2026-05-06', 166),
(108, 'PO-202604-5107', 'City Govt of Calamba', NULL, 222210.04, 'Finance-Approved', 0, 'Delivered to Client', 3, '2026-04-15 02:15:42', NULL, '2026-05-03', 168),
(109, 'PO-202604-5108', 'Nestle Cabuyao', NULL, 257416.84, 'GM-Approved', 0, 'GM Desk', 3, '2026-04-15 23:02:57', NULL, '2026-04-30', 169),
(110, 'PO-202604-5109', 'Jollibee Foods', NULL, 281944.19, 'GM-Approved', 0, 'Finance Dept', 3, '2026-04-15 06:40:49', NULL, '2026-05-03', 170),
(111, 'PO-202604-5110', 'Toyota Santa Rosa', NULL, 216198.44, 'President-Approved', 0, 'Delivered to Client', 3, '2026-04-16 14:03:26', NULL, '2026-05-09', 172),
(112, 'PO-202604-5111', 'City Govt of Calamba', NULL, 310029.36, 'Funded', 0, 'President Office', 3, '2026-04-17 02:05:19', NULL, '2026-05-09', 173),
(113, 'PO-202604-5112', 'STI College', NULL, 235426.44, 'Funded', 0, 'Finance Dept', 3, '2026-04-18 08:31:55', NULL, '2026-05-17', 174),
(114, 'PO-202604-5113', 'LISP 1 Operations', NULL, 105804.35, 'Pending', 0, 'GM Desk', 3, '2026-04-19 18:35:06', NULL, '2026-05-17', 176),
(115, 'PO-202604-5114', 'City Govt of Calamba', NULL, 53701.90, 'Rejected', 0, 'Supplier / Vendor', 3, '2026-04-19 19:25:02', NULL, '2026-05-14', 177),
(116, 'PO-202604-5115', 'STI College', NULL, 301524.16, 'Rejected', 0, 'Procurement Office', 3, '2026-04-20 08:47:55', NULL, '2026-05-09', 178),
(117, 'PO-2026-0001', 'ccc', '01-0001 ccc', 100000.00, 'Collected', 0, 'Finance Dept. (Collection)', 3, '2026-04-19 21:07:27', NULL, NULL, 179),
(118, 'PO-2026-0002', 'minhs', '02-0002 minhs', 280000.00, 'Partially-Collected', 0, 'Finance Dept. (Collection)', 3, '2026-04-19 21:40:43', NULL, NULL, NULL),
(119, 'PO-2026-0003', 'example', '01-0003 example', 20000.00, 'Finance-Approved', 0, 'Office of the President', 3, '2026-04-23 08:06:38', NULL, NULL, 180),
(120, 'PO-2026-0004', 'Palo-Alto Elementary School', '01-0004 Palo-Alto Elementary School', 42500.00, 'GM-Approved', 0, 'Finance Dept.', 3, '2026-04-23 14:11:04', NULL, NULL, 181),
(121, 'PO-2026-0005', 'example', '01-0005 example', 10000.00, 'Collected', 0, 'Finance Dept. (Collection)', 3, '2026-04-23 15:13:59', NULL, NULL, 182),
(122, 'PO-2026-0006', 'example', '01-0006 example', 10000.00, 'Collected', 0, 'Finance Dept. (Collection)', 3, '2026-05-02 19:46:33', NULL, NULL, NULL),
(123, 'PO-2026-0007', 'cccc', '01-0007 cccc', 18000.00, 'Pending', 1, 'Office of the GM', 3, '2026-05-21 19:40:13', NULL, NULL, 183);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requests`
--

CREATE TABLE `purchase_requests` (
  `pr_id` int(11) NOT NULL,
  `pr_number` varchar(50) NOT NULL,
  `client_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('Pending','Approved','Rejected','Converted_to_PO') DEFAULT 'Pending',
  `created_by` int(11) DEFAULT NULL,
  `date_created` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_requests`
--

INSERT INTO `purchase_requests` (`pr_id`, `pr_number`, `client_name`, `amount`, `status`, `created_by`, `date_created`) VALUES
(1, 'PR-202511-1000', 'DepEd Laguna', 243069.16, 'Rejected', 3, '2025-11-01 13:44:34'),
(2, 'PR-202511-1001', 'Nestle Cabuyao', 110162.71, 'Pending', 3, '2025-11-01 16:04:27'),
(3, 'PR-202511-1002', 'Jollibee Foods', 184675.00, 'Converted_to_PO', 3, '2025-11-02 13:54:22'),
(4, 'PR-202511-1003', 'LISP 1 Operations', 181066.71, 'Pending', 3, '2025-11-05 17:49:40'),
(5, 'PR-202511-1004', 'STI College', 136400.01, 'Converted_to_PO', 3, '2025-11-05 11:16:15'),
(6, 'PR-202511-1005', 'STI College', 214043.03, 'Converted_to_PO', 3, '2025-11-06 15:15:35'),
(7, 'PR-202511-1006', 'Honda Cars', 94963.52, 'Approved', 3, '2025-11-06 13:35:05'),
(8, 'PR-202511-1007', 'Yazaki-Torres', 142279.02, 'Converted_to_PO', 3, '2025-11-08 10:54:01'),
(9, 'PR-202511-1008', 'Toyota Santa Rosa', 172865.91, 'Converted_to_PO', 3, '2025-11-10 17:49:56'),
(10, 'PR-202511-1009', 'Jollibee Foods', 71569.25, 'Converted_to_PO', 3, '2025-11-11 13:16:45'),
(11, 'PR-202511-1010', 'Nestle Cabuyao', 81664.81, 'Rejected', 3, '2025-11-13 14:20:42'),
(12, 'PR-202511-1011', 'Carmelray Admin', 31362.21, 'Converted_to_PO', 3, '2025-11-16 16:00:46'),
(13, 'PR-202511-1012', 'City Govt of Calamba', 66374.94, 'Approved', 3, '2025-11-17 14:00:00'),
(14, 'PR-202511-1013', 'STI College', 245410.15, 'Converted_to_PO', 3, '2025-11-18 09:48:50'),
(15, 'PR-202511-1014', 'STI College', 304043.13, 'Converted_to_PO', 3, '2025-11-18 13:32:12'),
(16, 'PR-202511-1015', 'Honda Cars', 202756.56, 'Converted_to_PO', 3, '2025-11-18 12:11:04'),
(17, 'PR-202511-1016', 'Toyota Santa Rosa', 98625.72, 'Converted_to_PO', 3, '2025-11-21 08:16:28'),
(18, 'PR-202511-1017', 'Jollibee Foods', 199481.56, 'Converted_to_PO', 3, '2025-11-22 11:12:17'),
(19, 'PR-202511-1018', 'Honda Cars', 271940.77, 'Pending', 3, '2025-11-24 11:37:27'),
(20, 'PR-202511-1019', 'City Govt of Calamba', 251438.50, 'Converted_to_PO', 3, '2025-11-24 14:14:34'),
(21, 'PR-202511-1020', 'City Govt of Calamba', 221401.65, 'Converted_to_PO', 3, '2025-11-24 09:14:19'),
(22, 'PR-202511-1021', 'DepEd Laguna', 114956.95, 'Pending', 3, '2025-11-24 14:31:09'),
(23, 'PR-202511-1022', 'City Govt of Calamba', 236430.02, 'Converted_to_PO', 3, '2025-11-26 14:36:01'),
(24, 'PR-202511-1023', 'Toyota Santa Rosa', 79616.74, 'Pending', 3, '2025-11-29 16:20:44'),
(25, 'PR-202511-1024', 'STI College', 258753.40, 'Converted_to_PO', 3, '2025-11-29 09:16:48'),
(26, 'PR-202512-1025', 'Honda Cars', 212513.02, 'Converted_to_PO', 3, '2025-12-01 10:08:28'),
(27, 'PR-202512-1026', 'STI College', 172041.71, 'Converted_to_PO', 3, '2025-12-01 13:07:36'),
(28, 'PR-202512-1027', 'City Govt of Calamba', 24756.38, 'Converted_to_PO', 3, '2025-12-03 11:56:41'),
(29, 'PR-202512-1028', 'Honda Cars', 109425.07, 'Converted_to_PO', 3, '2025-12-03 13:23:29'),
(30, 'PR-202512-1029', 'Honda Cars', 293428.23, 'Converted_to_PO', 3, '2025-12-05 13:18:55'),
(31, 'PR-202512-1030', 'STI College', 224013.33, 'Rejected', 3, '2025-12-06 13:50:27'),
(32, 'PR-202512-1031', 'Yazaki-Torres', 147535.29, 'Converted_to_PO', 3, '2025-12-07 15:16:50'),
(33, 'PR-202512-1032', 'Toyota Santa Rosa', 332222.70, 'Rejected', 3, '2025-12-08 12:51:17'),
(34, 'PR-202512-1033', 'Toyota Santa Rosa', 95713.25, 'Converted_to_PO', 3, '2025-12-08 09:44:56'),
(35, 'PR-202512-1034', 'Toyota Santa Rosa', 291071.27, 'Converted_to_PO', 3, '2025-12-09 11:55:06'),
(36, 'PR-202512-1035', 'Jollibee Foods', 194184.33, 'Pending', 3, '2025-12-09 11:36:19'),
(37, 'PR-202512-1036', 'Calamba Medical Center', 173284.72, 'Converted_to_PO', 3, '2025-12-10 15:36:47'),
(38, 'PR-202512-1037', 'Calamba Medical Center', 266385.70, 'Rejected', 3, '2025-12-12 15:38:05'),
(39, 'PR-202512-1038', 'Jollibee Foods', 106900.35, 'Converted_to_PO', 3, '2025-12-12 14:55:36'),
(40, 'PR-202512-1039', 'Honda Cars', 336347.05, 'Approved', 3, '2025-12-13 12:50:35'),
(41, 'PR-202512-1040', 'Toyota Santa Rosa', 287882.34, 'Pending', 3, '2025-12-16 16:38:20'),
(42, 'PR-202512-1041', 'SM Prime Holdings', 128065.17, 'Converted_to_PO', 3, '2025-12-18 10:49:18'),
(43, 'PR-202512-1042', 'Toyota Santa Rosa', 346710.27, 'Rejected', 3, '2025-12-19 10:19:43'),
(44, 'PR-202512-1043', 'DepEd Laguna', 34024.36, 'Approved', 3, '2025-12-19 14:22:03'),
(45, 'PR-202512-1044', 'LISP 1 Operations', 19506.66, 'Converted_to_PO', 3, '2025-12-20 10:45:19'),
(46, 'PR-202512-1045', 'Calamba Medical Center', 163252.71, 'Pending', 3, '2025-12-20 11:07:11'),
(47, 'PR-202512-1046', 'Carmelray Admin', 40270.75, 'Rejected', 3, '2025-12-20 10:50:30'),
(48, 'PR-202512-1047', 'City Govt of Calamba', 18467.33, 'Rejected', 3, '2025-12-20 11:09:14'),
(49, 'PR-202512-1048', 'SM Prime Holdings', 137342.13, 'Converted_to_PO', 3, '2025-12-22 09:54:11'),
(50, 'PR-202512-1049', 'City Govt of Calamba', 17544.21, 'Converted_to_PO', 3, '2025-12-22 10:52:18'),
(51, 'PR-202512-1050', 'STI College', 161584.13, 'Pending', 3, '2025-12-23 17:08:35'),
(52, 'PR-202512-1051', 'Honda Cars', 235505.99, 'Converted_to_PO', 3, '2025-12-25 16:35:20'),
(53, 'PR-202512-1052', 'Calamba Medical Center', 59082.05, 'Converted_to_PO', 3, '2025-12-25 08:23:05'),
(54, 'PR-202512-1053', 'LISP 1 Operations', 240451.13, 'Converted_to_PO', 3, '2025-12-27 12:23:54'),
(55, 'PR-202512-1054', 'Jollibee Foods', 47525.07, 'Converted_to_PO', 3, '2025-12-28 14:31:49'),
(56, 'PR-202512-1055', 'DepEd Laguna', 308036.22, 'Converted_to_PO', 3, '2025-12-29 13:40:13'),
(57, 'PR-202512-1056', 'Carmelray Admin', 173708.05, 'Approved', 3, '2025-12-31 08:39:08'),
(58, 'PR-202601-1057', 'Honda Cars', 62895.99, 'Pending', 3, '2026-01-01 13:50:46'),
(59, 'PR-202601-1058', 'DepEd Laguna', 336232.12, 'Approved', 3, '2026-01-03 08:17:28'),
(60, 'PR-202601-1059', 'Yazaki-Torres', 19309.85, 'Rejected', 3, '2026-01-03 09:07:36'),
(61, 'PR-202601-1060', 'STI College', 342783.46, 'Converted_to_PO', 3, '2026-01-06 10:20:48'),
(62, 'PR-202601-1061', 'Honda Cars', 274478.67, 'Converted_to_PO', 3, '2026-01-06 09:36:34'),
(63, 'PR-202601-1062', 'STI College', 24963.45, 'Converted_to_PO', 3, '2026-01-09 08:55:43'),
(64, 'PR-202601-1063', 'DepEd Laguna', 102474.09, 'Converted_to_PO', 3, '2026-01-10 15:36:10'),
(65, 'PR-202601-1064', 'DepEd Laguna', 265000.53, 'Approved', 3, '2026-01-10 17:18:40'),
(66, 'PR-202601-1065', 'LISP 1 Operations', 115657.52, 'Converted_to_PO', 3, '2026-01-13 09:26:12'),
(67, 'PR-202601-1066', 'Honda Cars', 26188.90, 'Converted_to_PO', 3, '2026-01-13 16:51:12'),
(68, 'PR-202601-1067', 'STI College', 187715.70, 'Converted_to_PO', 3, '2026-01-14 12:18:43'),
(69, 'PR-202601-1068', 'DepEd Laguna', 93779.75, 'Converted_to_PO', 3, '2026-01-14 14:05:51'),
(70, 'PR-202601-1069', 'Carmelray Admin', 64950.63, 'Converted_to_PO', 3, '2026-01-15 17:35:30'),
(71, 'PR-202601-1070', 'Toyota Santa Rosa', 118884.60, 'Converted_to_PO', 3, '2026-01-16 17:38:14'),
(72, 'PR-202601-1071', 'Calamba Medical Center', 308587.09, 'Converted_to_PO', 3, '2026-01-17 17:23:16'),
(73, 'PR-202601-1072', 'LISP 1 Operations', 335756.98, 'Converted_to_PO', 3, '2026-01-22 11:27:49'),
(74, 'PR-202601-1073', 'LISP 1 Operations', 82283.59, 'Converted_to_PO', 3, '2026-01-22 08:51:23'),
(75, 'PR-202601-1074', 'Yazaki-Torres', 303869.21, 'Converted_to_PO', 3, '2026-01-23 15:46:35'),
(76, 'PR-202601-1075', 'Honda Cars', 149033.85, 'Converted_to_PO', 3, '2026-01-24 12:18:34'),
(77, 'PR-202601-1076', 'City Govt of Calamba', 274961.68, 'Converted_to_PO', 3, '2026-01-25 11:24:19'),
(78, 'PR-202601-1077', 'SM Prime Holdings', 145730.00, 'Converted_to_PO', 3, '2026-01-26 09:19:29'),
(79, 'PR-202601-1078', 'Toyota Santa Rosa', 339488.05, 'Approved', 3, '2026-01-28 13:04:43'),
(80, 'PR-202601-1079', 'LISP 1 Operations', 171971.17, 'Pending', 3, '2026-01-29 13:17:28'),
(81, 'PR-202601-1080', 'STI College', 99437.93, 'Converted_to_PO', 3, '2026-01-29 11:30:39'),
(82, 'PR-202601-1081', 'Carmelray Admin', 284575.39, 'Converted_to_PO', 3, '2026-01-30 10:07:12'),
(83, 'PR-202601-1082', 'Jollibee Foods', 310722.69, 'Converted_to_PO', 3, '2026-01-31 10:07:20'),
(84, 'PR-202601-1083', 'Carmelray Admin', 280319.78, 'Pending', 3, '2026-01-31 09:13:19'),
(85, 'PR-202602-1084', 'City Govt of Calamba', 292554.29, 'Approved', 3, '2026-02-01 13:27:17'),
(86, 'PR-202602-1085', 'Carmelray Admin', 21034.00, 'Converted_to_PO', 3, '2026-02-01 08:30:32'),
(87, 'PR-202602-1086', 'Carmelray Admin', 63999.96, 'Converted_to_PO', 3, '2026-02-01 13:18:16'),
(88, 'PR-202602-1087', 'DepEd Laguna', 285248.47, 'Converted_to_PO', 3, '2026-02-01 08:02:59'),
(89, 'PR-202602-1088', 'Toyota Santa Rosa', 300145.65, 'Approved', 3, '2026-02-02 09:40:14'),
(90, 'PR-202602-1089', 'Honda Cars', 345498.14, 'Converted_to_PO', 3, '2026-02-03 12:00:00'),
(91, 'PR-202602-1090', 'Jollibee Foods', 220841.73, 'Pending', 3, '2026-02-04 15:36:33'),
(92, 'PR-202602-1091', 'SM Prime Holdings', 161453.08, 'Converted_to_PO', 3, '2026-02-04 08:54:53'),
(93, 'PR-202602-1092', 'Honda Cars', 341231.16, 'Converted_to_PO', 3, '2026-02-09 14:29:38'),
(94, 'PR-202602-1093', 'Carmelray Admin', 235611.35, 'Rejected', 3, '2026-02-10 16:19:07'),
(95, 'PR-202602-1094', 'City Govt of Calamba', 317347.32, 'Converted_to_PO', 3, '2026-02-12 11:04:59'),
(96, 'PR-202602-1095', 'Honda Cars', 260962.36, 'Approved', 3, '2026-02-13 10:18:26'),
(97, 'PR-202602-1096', 'Carmelray Admin', 194579.47, 'Converted_to_PO', 3, '2026-02-16 15:33:24'),
(98, 'PR-202602-1097', 'Toyota Santa Rosa', 113878.62, 'Converted_to_PO', 3, '2026-02-16 15:43:40'),
(99, 'PR-202602-1098', 'DepEd Laguna', 234673.46, 'Rejected', 3, '2026-02-17 15:00:04'),
(100, 'PR-202602-1099', 'Yazaki-Torres', 174401.99, 'Rejected', 3, '2026-02-17 15:42:47'),
(101, 'PR-202602-1100', 'SM Prime Holdings', 19862.48, 'Rejected', 3, '2026-02-18 11:11:09'),
(102, 'PR-202602-1101', 'City Govt of Calamba', 212815.56, 'Converted_to_PO', 3, '2026-02-18 16:49:53'),
(103, 'PR-202602-1102', 'LISP 1 Operations', 218911.53, 'Converted_to_PO', 3, '2026-02-19 12:06:41'),
(104, 'PR-202602-1103', 'Carmelray Admin', 74346.13, 'Converted_to_PO', 3, '2026-02-20 16:16:52'),
(105, 'PR-202602-1104', 'DepEd Laguna', 15296.65, 'Approved', 3, '2026-02-20 17:26:15'),
(106, 'PR-202602-1105', 'Yazaki-Torres', 283222.10, 'Converted_to_PO', 3, '2026-02-21 12:31:42'),
(107, 'PR-202602-1106', 'Honda Cars', 119291.64, 'Rejected', 3, '2026-02-22 12:12:34'),
(108, 'PR-202602-1107', 'STI College', 293337.26, 'Converted_to_PO', 3, '2026-02-23 11:21:10'),
(109, 'PR-202602-1108', 'DepEd Laguna', 74736.91, 'Converted_to_PO', 3, '2026-02-23 08:46:20'),
(110, 'PR-202602-1109', 'Toyota Santa Rosa', 154606.29, 'Converted_to_PO', 3, '2026-02-24 12:00:47'),
(111, 'PR-202602-1110', 'Toyota Santa Rosa', 308623.15, 'Converted_to_PO', 3, '2026-02-26 14:00:10'),
(112, 'PR-202602-1111', 'Toyota Santa Rosa', 189433.71, 'Converted_to_PO', 3, '2026-02-26 11:54:42'),
(113, 'PR-202602-1112', 'STI College', 335377.13, 'Rejected', 3, '2026-02-26 12:44:30'),
(114, 'PR-202602-1113', 'Toyota Santa Rosa', 93517.00, 'Rejected', 3, '2026-02-26 08:11:14'),
(115, 'PR-202602-1114', 'City Govt of Calamba', 39069.41, 'Converted_to_PO', 3, '2026-02-27 10:33:52'),
(116, 'PR-202602-1115', 'Yazaki-Torres', 78883.41, 'Pending', 3, '2026-02-27 09:41:14'),
(117, 'PR-202603-1116', 'LISP 1 Operations', 155732.77, 'Converted_to_PO', 3, '2026-03-01 16:36:36'),
(118, 'PR-202603-1117', 'LISP 1 Operations', 205418.57, 'Converted_to_PO', 3, '2026-03-02 13:37:12'),
(119, 'PR-202603-1118', 'Nestle Cabuyao', 240913.87, 'Converted_to_PO', 3, '2026-03-02 13:40:28'),
(120, 'PR-202603-1119', 'Yazaki-Torres', 238291.19, 'Converted_to_PO', 3, '2026-03-04 15:27:15'),
(121, 'PR-202603-1120', 'City Govt of Calamba', 164906.10, 'Converted_to_PO', 3, '2026-03-05 17:53:57'),
(122, 'PR-202603-1121', 'DepEd Laguna', 107974.77, 'Converted_to_PO', 3, '2026-03-05 08:56:06'),
(123, 'PR-202603-1122', 'City Govt of Calamba', 276667.48, 'Converted_to_PO', 3, '2026-03-05 09:52:26'),
(124, 'PR-202603-1123', 'Yazaki-Torres', 305710.00, 'Converted_to_PO', 3, '2026-03-06 17:16:15'),
(125, 'PR-202603-1124', 'Carmelray Admin', 227608.78, 'Converted_to_PO', 3, '2026-03-06 17:16:06'),
(126, 'PR-202603-1125', 'Carmelray Admin', 248138.91, 'Converted_to_PO', 3, '2026-03-07 08:12:36'),
(127, 'PR-202603-1126', 'Carmelray Admin', 282134.04, 'Converted_to_PO', 3, '2026-03-09 09:57:26'),
(128, 'PR-202603-1127', 'Calamba Medical Center', 114940.22, 'Converted_to_PO', 3, '2026-03-10 16:18:11'),
(129, 'PR-202603-1128', 'Calamba Medical Center', 283073.54, 'Converted_to_PO', 3, '2026-03-10 09:59:41'),
(130, 'PR-202603-1129', 'Honda Cars', 166253.45, 'Converted_to_PO', 3, '2026-03-12 12:32:17'),
(131, 'PR-202603-1130', 'DepEd Laguna', 334738.54, 'Pending', 3, '2026-03-12 15:24:08'),
(132, 'PR-202603-1131', 'Carmelray Admin', 238951.24, 'Converted_to_PO', 3, '2026-03-13 10:40:53'),
(133, 'PR-202603-1132', 'Toyota Santa Rosa', 133431.89, 'Converted_to_PO', 3, '2026-03-21 11:10:09'),
(134, 'PR-202603-1133', 'Carmelray Admin', 122449.37, 'Approved', 3, '2026-03-23 17:06:15'),
(135, 'PR-202603-1134', 'Nestle Cabuyao', 232739.77, 'Converted_to_PO', 3, '2026-03-23 11:24:40'),
(136, 'PR-202603-1135', 'Jollibee Foods', 199362.26, 'Converted_to_PO', 3, '2026-03-24 16:14:03'),
(137, 'PR-202603-1136', 'Yazaki-Torres', 91887.54, 'Converted_to_PO', 3, '2026-03-24 10:38:35'),
(138, 'PR-202603-1137', 'Calamba Medical Center', 50680.95, 'Rejected', 3, '2026-03-25 14:19:43'),
(139, 'PR-202603-1138', 'SM Prime Holdings', 131859.59, 'Rejected', 3, '2026-03-25 12:58:44'),
(140, 'PR-202603-1139', 'Nestle Cabuyao', 296113.51, 'Converted_to_PO', 3, '2026-03-27 08:02:23'),
(141, 'PR-202603-1140', 'SM Prime Holdings', 62750.97, 'Approved', 3, '2026-03-28 11:35:43'),
(142, 'PR-202603-1141', 'LISP 1 Operations', 145954.41, 'Approved', 3, '2026-03-28 10:08:09'),
(143, 'PR-202603-1142', 'Carmelray Admin', 160638.78, 'Approved', 3, '2026-03-28 17:35:55'),
(144, 'PR-202603-1143', 'LISP 1 Operations', 231544.24, 'Approved', 3, '2026-03-29 13:17:03'),
(145, 'PR-202603-1144', 'LISP 1 Operations', 173632.56, 'Approved', 3, '2026-03-29 08:31:14'),
(146, 'PR-202603-1145', 'Honda Cars', 176884.24, 'Rejected', 3, '2026-03-30 17:25:14'),
(147, 'PR-202603-1146', 'DepEd Laguna', 243854.47, 'Converted_to_PO', 3, '2026-03-30 11:29:08'),
(148, 'PR-202604-1147', 'STI College', 254369.79, 'Pending', 3, '2026-04-02 12:38:58'),
(149, 'PR-202604-1148', 'Nestle Cabuyao', 130192.59, 'Rejected', 3, '2026-04-02 12:03:16'),
(150, 'PR-202604-1149', 'DepEd Laguna', 33502.99, 'Approved', 3, '2026-04-03 11:16:53'),
(151, 'PR-202604-1150', 'DepEd Laguna', 165663.36, 'Approved', 3, '2026-04-03 16:45:50'),
(152, 'PR-202604-1151', 'STI College', 32378.76, 'Converted_to_PO', 3, '2026-04-04 08:09:26'),
(153, 'PR-202604-1152', 'Calamba Medical Center', 232421.27, 'Converted_to_PO', 3, '2026-04-04 13:28:33'),
(154, 'PR-202604-1153', 'Honda Cars', 285637.84, 'Converted_to_PO', 3, '2026-04-04 09:56:32'),
(155, 'PR-202604-1154', 'Carmelray Admin', 108854.20, 'Converted_to_PO', 3, '2026-04-05 16:58:26'),
(156, 'PR-202604-1155', 'DepEd Laguna', 237090.48, 'Converted_to_PO', 3, '2026-04-05 14:39:57'),
(157, 'PR-202604-1156', 'Honda Cars', 240132.44, 'Converted_to_PO', 3, '2026-04-06 08:17:13'),
(158, 'PR-202604-1157', 'DepEd Laguna', 218119.53, 'Converted_to_PO', 3, '2026-04-07 17:40:19'),
(159, 'PR-202604-1158', 'LISP 1 Operations', 182633.23, 'Pending', 3, '2026-04-07 14:43:54'),
(160, 'PR-202604-1159', 'Jollibee Foods', 298555.35, 'Approved', 3, '2026-04-08 12:34:55'),
(161, 'PR-202604-1160', 'Honda Cars', 113467.82, 'Converted_to_PO', 3, '2026-04-08 14:33:10'),
(162, 'PR-202604-1161', 'STI College', 258206.00, 'Converted_to_PO', 3, '2026-04-08 15:08:06'),
(163, 'PR-202604-1162', 'SM Prime Holdings', 83755.87, 'Rejected', 3, '2026-04-09 17:16:48'),
(164, 'PR-202604-1163', 'DepEd Laguna', 46670.30, 'Converted_to_PO', 3, '2026-04-09 16:41:06'),
(165, 'PR-202604-1164', 'Yazaki-Torres', 262822.61, 'Converted_to_PO', 3, '2026-04-10 17:29:56'),
(166, 'PR-202604-1165', 'Honda Cars', 216995.92, 'Converted_to_PO', 3, '2026-04-11 09:36:07'),
(167, 'PR-202604-1166', 'Honda Cars', 58542.65, 'Approved', 3, '2026-04-11 12:57:21'),
(168, 'PR-202604-1167', 'City Govt of Calamba', 222210.04, 'Converted_to_PO', 3, '2026-04-12 12:29:41'),
(169, 'PR-202604-1168', 'Nestle Cabuyao', 257416.84, 'Converted_to_PO', 3, '2026-04-13 15:22:36'),
(170, 'PR-202604-1169', 'Jollibee Foods', 281944.19, 'Converted_to_PO', 3, '2026-04-13 12:21:50'),
(171, 'PR-202604-1170', 'Jollibee Foods', 343183.74, 'Pending', 3, '2026-04-13 11:57:38'),
(172, 'PR-202604-1171', 'Toyota Santa Rosa', 216198.44, 'Converted_to_PO', 3, '2026-04-14 15:00:05'),
(173, 'PR-202604-1172', 'City Govt of Calamba', 310029.36, 'Converted_to_PO', 3, '2026-04-14 17:11:08'),
(174, 'PR-202604-1173', 'STI College', 235426.44, 'Converted_to_PO', 3, '2026-04-15 13:09:16'),
(175, 'PR-202604-1174', 'City Govt of Calamba', 277024.08, 'Rejected', 3, '2026-04-15 10:50:03'),
(176, 'PR-202604-1175', 'LISP 1 Operations', 105804.35, 'Converted_to_PO', 3, '2026-04-17 13:09:29'),
(177, 'PR-202604-1176', 'City Govt of Calamba', 53701.90, 'Converted_to_PO', 3, '2026-04-17 14:21:11'),
(178, 'PR-202604-1177', 'STI College', 301524.16, 'Converted_to_PO', 3, '2026-04-17 11:16:36'),
(179, 'PR-2026-0001', 'ccc', 100000.00, 'Converted_to_PO', 16, '2026-04-19 21:05:54'),
(180, 'PR-2026-0002', 'example', 20000.00, 'Converted_to_PO', 16, '2026-04-23 08:05:13'),
(181, 'PR-2026-0003', 'Palo-Alto Elementary School', 42500.00, 'Converted_to_PO', 16, '2026-04-23 14:02:55'),
(182, 'PR-2026-0004', 'example', 10000.00, 'Converted_to_PO', 16, '2026-04-23 15:12:50'),
(183, 'PR-2026-0005', 'cccc', 20000.00, 'Converted_to_PO', 16, '2026-05-21 19:24:19');

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `quotation_id` int(11) NOT NULL,
  `quotation_number` varchar(50) NOT NULL,
  `client_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `client_po_number` varchar(50) DEFAULT NULL,
  `approval_mode` varchar(50) DEFAULT 'Formal PO',
  `po_file_path` varchar(255) DEFAULT NULL,
  `status` enum('Pending PO','PO Received','Converted to PR') DEFAULT 'Pending PO',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quotations`
--

INSERT INTO `quotations` (`quotation_id`, `quotation_number`, `client_name`, `amount`, `client_po_number`, `approval_mode`, `po_file_path`, `status`, `created_by`, `created_at`) VALUES
(1, 'QTN-2025-0001', 'ccc', 10000.00, 'PO-CCC-0001', 'Formal PO', NULL, 'PO Received', 16, '2026-05-20 12:10:59'),
(2, 'dff342', 'minhs', 200000.00, 'CPO-2026-0001', 'Email Confirmation', '1779361795_CPO-2026-0001_40886167.png', 'PO Received', 16, '2026-05-20 12:16:58'),
(3, 'QTN-2026-0001', 'cccc', 20000.00, 'PO-CCC-0002', 'Formal PO', NULL, 'Converted to PR', 16, '2026-05-20 12:27:55'),
(4, 'QTN-2026-0002', 'vsdgsgs', 343.00, 'CPO-2026-0002', 'Chat/Viber Agreement', '1779362700_CPO-2026-0002_cece5f5c.jpg', 'PO Received', 16, '2026-05-21 11:22:45');

-- --------------------------------------------------------

--
-- Table structure for table `quotation_items`
--

CREATE TABLE `quotation_items` (
  `item_id` int(11) NOT NULL,
  `quotation_id` int(11) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `specifications` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quotation_items`
--

INSERT INTO `quotation_items` (`item_id`, `quotation_id`, `category`, `brand`, `item_name`, `specifications`, `quantity`, `unit_price`, `total_price`) VALUES
(1, 3, '01', 'Lenovo', 'example', 'gfhsfh', 10, 2000.00, 20000.00),
(2, 4, '02', 'Lenovo', 'gsg', 'gsgsg', 1, 343.00, 343.00);

-- --------------------------------------------------------

--
-- Table structure for table `retention_policies`
--

CREATE TABLE `retention_policies` (
  `policy_id` int(11) NOT NULL,
  `policy_name` varchar(100) NOT NULL,
  `retention_years` int(11) NOT NULL DEFAULT 0,
  `retention_months` int(11) NOT NULL DEFAULT 0,
  `action_after_retention` enum('Destroy','Permanent Archive') NOT NULL DEFAULT 'Destroy',
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `retention_policies`
--

INSERT INTO `retention_policies` (`policy_id`, `policy_name`, `retention_years`, `retention_months`, `action_after_retention`, `description`) VALUES
(1, 'Financial and Accounting Records', 10, 0, 'Destroy', 'Invoices, receipts, tax documents. (10 taon)'),
(2, 'Employee & HR Records', 5, 0, 'Destroy', 'Keep 5 years after resignation.'),
(3, 'General Business Correspondence', 2, 0, 'Destroy', 'Routine emails, memos, basic letters.'),
(4, 'Permanent Corporate Records', 99, 0, 'Permanent Archive', 'Articles of incorporation, board minutes.');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('Procurement','GM','Finance','President','Supply Chain','Admin','Sales Staff') NOT NULL,
  `status` varchar(50) DEFAULT 'Active',
  `avatar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expire` datetime DEFAULT NULL,
  `account_status` enum('Active','Pending_Approval') DEFAULT 'Active',
  `pending_email` varchar(100) DEFAULT NULL,
  `email_verification_code` varchar(10) DEFAULT NULL,
  `email_code_expire` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `full_name`, `email`, `role`, `status`, `avatar`, `created_at`, `reset_token`, `reset_token_expire`, `account_status`, `pending_email`, `email_verification_code`, `email_code_expire`) VALUES
(2, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin@example.com', 'Admin', 'Active', 'uploads/avatars/avatar_2_1771462744.jpg', '2026-02-16 11:52:53', NULL, NULL, 'Active', NULL, NULL, NULL),
(3, 'procure', '$2y$10$twHlaSlyQwW3yaqhnFOZ..il5ZcxUL7eEeJYBlHSL6yk4CV3lFRR6', 'Procurement', 'tamayolheilhei@gmail.com', 'Procurement', 'Active', 'uploads/avatars/avatar_3_1774007157.jpeg', '2026-02-16 11:53:34', '489831', '2026-05-17 22:36:07', 'Active', NULL, NULL, NULL),
(6, 'pres', '$2y$10$nA3a1Ql.Kkf0EsSzNL8QQ.EMA0mFRaWKaEOSAXZuWzl8UIiHrP5wi', 'President', NULL, 'President', 'Active', NULL, '2026-02-16 12:46:41', NULL, NULL, 'Active', NULL, NULL, NULL),
(8, 'finance', '$2y$10$Clt.mwaCaXqtMKofDtLObOt1qvgr4V/n9qg9w3iRK5wGoQ1qjN7A.', 'Finance', NULL, 'Finance', 'Active', NULL, '2026-02-16 17:41:02', NULL, NULL, 'Active', NULL, NULL, NULL),
(9, 'gm', '$2y$10$TrrP/EeiGOvwDkl8lJ/TluxnDWHJ8VlV59A5MRfWZ0.e3NF49LxQW', 'General Manager', NULL, 'GM', 'Active', NULL, '2026-02-16 17:41:19', NULL, NULL, 'Active', NULL, NULL, NULL),
(12, 'supplyy', '$2y$10$uVkKvJ7ksoyVKMVq2tR8iuHxDZMyZtuJ8DDt58OIla.INoNM0Ncm2', 'Supply Chain', NULL, 'Supply Chain', 'Active', NULL, '2026-02-16 18:06:09', NULL, NULL, 'Active', NULL, NULL, '2026-02-19 10:32:55'),
(16, 'sales', '$2y$10$gTVNV2Xirnq40zS2.a9RHuE1uLV9Pg74WHLxDXyJSEsrgxt5s9Co.', 'sales staff', NULL, 'Sales Staff', 'Active', NULL, '2026-03-25 11:53:14', NULL, NULL, 'Active', NULL, NULL, NULL),
(17, 'lhei', '$2y$10$Mj6OmHqmWiZ/SEbUW/RRSu/yw4J//.9RZyY6ufpIqLEbgAXyL7H8q', 'lheinard', NULL, 'GM', 'Active', NULL, '2026-03-27 14:25:08', NULL, NULL, 'Active', NULL, NULL, NULL),
(20, 'supply', '$2y$10$Aw1PfON3.Rqqxo6CixtBvOjgBSJ3eMYYaI13KauMNmTmf7QvnduWC', 'supplyyy', NULL, 'Supply Chain', 'Active', NULL, '2026-04-23 07:16:08', NULL, NULL, 'Active', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_requests`
--

CREATE TABLE `user_requests` (
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `request_type` varchar(50) NOT NULL,
  `new_value` varchar(255) NOT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_requests`
--

INSERT INTO `user_requests` (`request_id`, `user_id`, `request_type`, `new_value`, `status`, `requested_at`) VALUES
(8, 12, 'Unlock Account', 'Password Reset Complete', 'Approved', '2026-02-16 18:06:41'),
(9, 12, 'Change Password', '$2y$10$bOP5l4qDmvbkVf4FfsbsGe9JJtTK7L6GWULAN6H17MjhUMCpM.f1W', 'Approved', '2026-02-19 01:29:45'),
(10, 12, 'Change Password', '$2y$10$uVkKvJ7ksoyVKMVq2tR8iuHxDZMyZtuJ8DDt58OIla.INoNM0Ncm2', 'Approved', '2026-02-19 01:35:34'),
(11, 3, 'Change Password', '$2y$10$EptttjVo2wXWetJuG0cUCO5x0CaFYgXD1gs3p0B2bKV7SDq1NrGNq', 'Approved', '2026-02-19 02:33:40'),
(12, 3, 'Change Password', '$2y$10$yVQXz59ZQdEG1ny8whu8c.LgeOtF3w3JQg.5C8rPPjZa0tY1aEOke', 'Approved', '2026-03-04 05:12:17'),
(13, 12, 'Change Username', 'supplyy', 'Approved', '2026-03-19 15:25:57');

-- --------------------------------------------------------

--
-- Table structure for table `workflow_rules`
--

CREATE TABLE `workflow_rules` (
  `rule_id` int(11) NOT NULL,
  `current_status` varchar(50) NOT NULL,
  `action_key` varchar(50) NOT NULL,
  `required_role` varchar(50) NOT NULL,
  `next_status` varchar(50) NOT NULL,
  `next_location` varchar(50) NOT NULL,
  `notify_target` varchar(50) DEFAULT NULL,
  `notify_message` text DEFAULT NULL,
  `button_label` varchar(50) NOT NULL,
  `button_class` varchar(50) NOT NULL,
  `button_icon` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `workflow_rules`
--

INSERT INTO `workflow_rules` (`rule_id`, `current_status`, `action_key`, `required_role`, `next_status`, `next_location`, `notify_target`, `notify_message`, `button_label`, `button_class`, `button_icon`) VALUES
(1, 'Pending', 'approve_gm', 'GM', 'GM-Approved', 'Finance', 'Finance', 'PO #{po_number} GM-Approved. Needs Validation.', 'GM Approve', 'btn-success', 'fas fa-check'),
(2, 'Pending', 'reject', 'GM', 'Rejected', 'Procurement', 'Procurement', 'PO #{po_number} Rejected by GM.', 'Reject', 'btn-danger', 'fas fa-times'),
(3, 'GM-Approved', 'approve_finance', 'Finance', 'Finance-Approved', 'President', 'President', 'PO #{po_number} Finance-Approved. Needs Sign-off.', 'Finance Validate', 'btn-success', 'fas fa-check'),
(4, 'GM-Approved', 'reject', 'Finance', 'Rejected', 'Procurement', 'Procurement', 'PO #{po_number} Rejected by Finance.', 'Reject', 'btn-danger', 'fas fa-times'),
(5, 'Finance-Approved', 'approve_president', 'President', 'President-Approved', 'Finance', 'Finance', 'PO #{po_number} President-Approved. Ready for Funding.', 'Final Sign-off', 'btn-success', 'fas fa-star'),
(6, 'Finance-Approved', 'reject', 'President', 'Rejected', 'Procurement', 'Procurement', 'PO #{po_number} Rejected by President.', 'Reject', 'btn-danger', 'fas fa-times'),
(7, 'President-Approved', 'mark_funded', 'Finance', 'Funded', 'Supply Chain', 'Supply Chain', 'PO #{po_number} Funded. Ready for Delivery.', 'Release Funding', 'btn-info text-white', 'fas fa-coins'),
(8, 'Funded', 'mark_delivered', 'Supply Chain', 'Delivered', 'Procurement', 'Procurement', 'PO #{po_number} Delivered. Monitor Collection.', 'Mark Delivered', 'btn-primary', 'fas fa-truck');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `company_folders`
--
ALTER TABLE `company_folders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`doc_id`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `fk_doc_policy` (`policy_id`);

--
-- Indexes for table `document_audit_trail`
--
ALTER TABLE `document_audit_trail`
  ADD PRIMARY KEY (`trail_id`),
  ADD KEY `doc_id` (`doc_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `audit_log_id` (`audit_log_id`);

--
-- Indexes for table `document_categories`
--
ALTER TABLE `document_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_category_policy` (`policy_id`);

--
-- Indexes for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD PRIMARY KEY (`version_id`),
  ADD KEY `doc_id` (`doc_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notif_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`);

--
-- Indexes for table `po_history`
--
ALTER TABLE `po_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `po_items`
--
ALTER TABLE `po_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `po_id` (`po_id`);

--
-- Indexes for table `pr_items`
--
ALTER TABLE `pr_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `fk_pr_item` (`pr_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_po_pr` (`pr_id`);

--
-- Indexes for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD PRIMARY KEY (`pr_id`),
  ADD UNIQUE KEY `pr_number` (`pr_number`),
  ADD KEY `fk_pr_user` (`created_by`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`quotation_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `fk_quotation_item` (`quotation_id`);

--
-- Indexes for table `retention_policies`
--
ALTER TABLE `retention_policies`
  ADD PRIMARY KEY (`policy_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_requests`
--
ALTER TABLE `user_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `workflow_rules`
--
ALTER TABLE `workflow_rules`
  ADD PRIMARY KEY (`rule_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1281;

--
-- AUTO_INCREMENT for table `company_folders`
--
ALTER TABLE `company_folders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `doc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=143;

--
-- AUTO_INCREMENT for table `document_audit_trail`
--
ALTER TABLE `document_audit_trail`
  MODIFY `trail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `document_categories`
--
ALTER TABLE `document_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `document_versions`
--
ALTER TABLE `document_versions`
  MODIFY `version_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notif_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `po_history`
--
ALTER TABLE `po_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=514;

--
-- AUTO_INCREMENT for table `po_items`
--
ALTER TABLE `po_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- AUTO_INCREMENT for table `pr_items`
--
ALTER TABLE `pr_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=124;

--
-- AUTO_INCREMENT for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  MODIFY `pr_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=184;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `quotation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `quotation_items`
--
ALTER TABLE `quotation_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `retention_policies`
--
ALTER TABLE `retention_policies`
  MODIFY `policy_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `user_requests`
--
ALTER TABLE `user_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `workflow_rules`
--
ALTER TABLE `workflow_rules`
  MODIFY `rule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_doc_policy` FOREIGN KEY (`policy_id`) REFERENCES `retention_policies` (`policy_id`) ON DELETE SET NULL;

--
-- Constraints for table `document_categories`
--
ALTER TABLE `document_categories`
  ADD CONSTRAINT `fk_category_policy` FOREIGN KEY (`policy_id`) REFERENCES `retention_policies` (`policy_id`) ON DELETE SET NULL;

--
-- Constraints for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD CONSTRAINT `document_versions_ibfk_1` FOREIGN KEY (`doc_id`) REFERENCES `documents` (`doc_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_versions_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `po_history`
--
ALTER TABLE `po_history`
  ADD CONSTRAINT `po_history_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `po_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `po_items`
--
ALTER TABLE `po_items`
  ADD CONSTRAINT `po_items_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE;

--
-- Constraints for table `pr_items`
--
ALTER TABLE `pr_items`
  ADD CONSTRAINT `fk_pr_item` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_pr` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `quotations`
--
ALTER TABLE `quotations`
  ADD CONSTRAINT `fk_quotation_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD CONSTRAINT `fk_quotation_item` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`quotation_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_requests`
--
ALTER TABLE `user_requests`
  ADD CONSTRAINT `user_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
