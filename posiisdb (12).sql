-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 21, 2026 at 10:12 PM
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
-- Database: `posiisdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `log_type` enum('Sales','Disposal','Deliveries','Payments','Out_of_Stock','System','Prices') NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `log_type`, `item_id`, `message`, `old_value`, `new_value`, `created_at`) VALUES
(1, 5, '', 7, 'SUPERVISION FLAG CLEARED: @josepstafff (ID 7) cleared by @superadmin@josep.', NULL, NULL, '2026-05-14 21:23:50'),
(2, 7, 'Deliveries', 1, 'NEW SHIPMENT: DEL-20260514-5F60 | Supplier: Joseph Award (#1001) | Total: ₱100,000.00', NULL, NULL, '2026-05-14 21:25:03'),
(3, 7, 'Deliveries', 1, 'Officialized Batch (DEL-20260514-5F60): Added 10 units to BearBrandChoco29G.', NULL, NULL, '2026-05-14 21:31:51'),
(4, 7, 'Deliveries', 2, 'Officialized Batch (DEL-20260514-5F60): Added 9 units to DatuPutiPatis150ML.', NULL, NULL, '2026-05-14 21:31:51'),
(5, 7, 'Deliveries', 3, 'Officialized Batch (DEL-20260514-5F60): Added 11 units to TangGrapes19G.', NULL, NULL, '2026-05-14 21:31:51'),
(6, 7, 'Sales', 1, 'Completed sale #RCPT-20260514-2241. Mode: Cash. Total: ₱175.84', NULL, NULL, '2026-05-14 21:34:09'),
(7, 7, 'Disposal', 1, 'REFUND REQUEST #RCPT-20260514-2241: 5 pcs of \"BearBrandChoco29G\" — awaiting admin approval. Est. ₱86.24', NULL, NULL, '2026-05-14 21:34:33'),
(8, 7, 'Disposal', 1, 'REFUND REQUEST #RCPT-20260514-2241: 5 pcs of \"BearBrandChoco29G\" — awaiting admin approval. Est. ₱86.24', NULL, NULL, '2026-05-14 21:34:38'),
(9, 5, 'Disposal', 1, 'REFUND APPROVE #RCPT-20260514-2241: 5 pcs of \"BearBrandChoco29G\". ₱86.24 issued.', '175.84', '89.6', '2026-05-14 21:35:58'),
(10, 5, 'Disposal', 1, 'REFUND APPROVE #RCPT-20260514-2241: 5 pcs of \"BearBrandChoco29G\". ₱89.60 issued.', '89.6', '0', '2026-05-14 21:36:35'),
(11, 5, '', 0, 'Procurement access approved for user ID 7.', NULL, NULL, '2026-05-14 21:38:36'),
(12, 7, '', 1, 'RECOUNT MISMATCH (fail #1): Alert #1 \'DatuPutiPatis150ML\' — expected: 9, actual: 10, variance: -1. Returned to recounting.', NULL, NULL, '2026-05-14 21:38:52'),
(13, 7, '', 2, 'RECOUNT MISMATCH (fail #1): Alert #2 \'TangGrapes19G\' — expected: 11, actual: 9, variance: 2. Returned to recounting.', NULL, NULL, '2026-05-14 21:38:52'),
(14, 7, '', 1, 'RECOUNT MISMATCH (fail #2): Alert #1 \'DatuPutiPatis150ML\' — expected: 9, actual: 10, variance: -1. Returned to recounting.', NULL, NULL, '2026-05-14 21:40:57'),
(15, 7, '', 1, 'SUPERVISION FLAG SET: Alert #1 failed recount twice. 1 staff account(s) flagged for supervision.', NULL, NULL, '2026-05-14 21:40:57'),
(16, 7, '', 2, 'RECOUNT MISMATCH (fail #2): Alert #2 \'TangGrapes19G\' — expected: 11, actual: 10, variance: 1. Returned to recounting.', NULL, NULL, '2026-05-14 21:40:57'),
(17, 7, '', 2, 'SUPERVISION FLAG SET: Alert #2 failed recount twice. 1 staff account(s) flagged for supervision.', NULL, NULL, '2026-05-14 21:40:57'),
(18, 7, 'Deliveries', 2, 'NEW SHIPMENT: DEL-20260514-647C | Supplier: PauCorp (#1002) | Total: ₱50,000.00', NULL, NULL, '2026-05-14 21:42:52'),
(19, 7, 'Deliveries', 4, 'Officialized Batch (DEL-20260514-647C): Added 10 units to DatuPutiPatis150ML.', NULL, NULL, '2026-05-14 21:52:18'),
(20, 7, 'Deliveries', 6, 'Officialized Batch (DEL-20260514-647C): Added 10 units to MangTomasSpc80G.', NULL, NULL, '2026-05-14 21:52:18'),
(21, 7, 'Deliveries', 5, 'Officialized Batch (DEL-20260514-647C): Added 10 units to TangGrapes19G.', NULL, NULL, '2026-05-14 21:52:18'),
(22, 5, 'Deliveries', 7, 'Officialized Batch (DEL-20260514-5F60): Added 10 units to MangTomasSpc80G.', NULL, NULL, '2026-05-14 21:56:45'),
(23, 5, 'Deliveries', 1, 'Officialized Batch (DEL-20260514-5F60): Added 10 units to BearBrandChoco29G.', NULL, NULL, '2026-05-14 22:17:03'),
(24, 5, 'Sales', 2, 'Completed sale #RCPT-20260515-0DEC. Mode: Cash. Total: ₱283.36', NULL, NULL, '2026-05-14 22:44:25'),
(25, 5, 'Prices', 5, 'TIER UPDATE: TangGrapes19G —', NULL, NULL, '2026-05-14 22:45:13'),
(26, 5, 'Deliveries', 9, 'Officialized Batch (DEL-20260514-647C): Added 10 units to BearBrandChoco29G.', NULL, NULL, '2026-05-14 22:47:36'),
(27, 7, '', 3, 'RECOUNT MISMATCH (fail #1): Alert #3 \'BearBrandChoco29G\' — expected: 10, actual: 5, variance: 5. Returned to recounting.', NULL, NULL, '2026-05-14 22:48:26'),
(28, 7, '', 3, 'RECOUNT MISMATCH (fail #2): Alert #3 \'BearBrandChoco29G\' — expected: 10, actual: 5, variance: 5. Returned to recounting.', NULL, NULL, '2026-05-14 22:54:31'),
(29, 7, '', 3, 'SUPERVISION FLAG SET: Alert #3 failed recount twice. 0 staff account(s) flagged for supervision.', NULL, NULL, '2026-05-14 22:54:31'),
(30, 5, '', 0, 'Procurement access approved for user ID 7.', NULL, NULL, '2026-05-15 07:57:29'),
(31, 7, 'Deliveries', 9, 'Officialized Batch (DEL-20260514-647C): Added 59 units to BearBrandChoco29G.', NULL, NULL, '2026-05-15 07:58:56'),
(32, 7, '', 4, 'RECOUNT SUBMITTED (matched): Alert #4 \'BearBrandChoco29G\' — count verified at 59 units.', NULL, NULL, '2026-05-15 08:00:17'),
(33, 7, '', 3, 'RECOUNT SUBMITTED (matched): Alert #3 \'BearBrandChoco29G\' — count verified at 10 units.', NULL, NULL, '2026-05-15 08:00:17'),
(34, 6, 'Deliveries', 3, 'NEW SHIPMENT: DEL-20260521-B97C | Supplier: MARCOPOLLOCORPO (#1003) | Total: ₱10,000.00', NULL, NULL, '2026-05-21 19:42:41');

-- --------------------------------------------------------

--
-- Table structure for table `admin_notifications`
--

CREATE TABLE `admin_notifications` (
  `id` int(11) NOT NULL,
  `type` enum('POS','DELIVERY','PAYMENT','DELETE_REQ') DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `status` enum('PENDING','VERIFIED') DEFAULT 'PENDING'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_items`
--

CREATE TABLE `delivery_items` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `delivered_qty` int(11) NOT NULL,
  `delivered_price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_queue`
--

CREATE TABLE `delivery_queue` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `status` enum('PENDING','VALIDATED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_returns`
--

CREATE TABLE `delivery_returns` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT 'Damaged',
  `deduct_pay` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_return_requests`
--

CREATE TABLE `delivery_return_requests` (
  `id` int(11) NOT NULL,
  `ticket_no` varchar(50) DEFAULT NULL,
  `invoice_no` varchar(100) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `deduct_pay` tinyint(1) DEFAULT 1,
  `requested_by` int(11) DEFAULT NULL,
  `requested_username` varchar(100) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_username` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_return_request_items`
--

CREATE TABLE `delivery_return_request_items` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT 'Damaged',
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discounts`
--

CREATE TABLE `discounts` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `promo_code` varchar(20) DEFAULT NULL,
  `type` enum('Percentage','Fixed') NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `usage_limit` int(11) DEFAULT 0 COMMENT '0 means unlimited',
  `used_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `disposals`
--

CREATE TABLE `disposals` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `reason` enum('Expired','Damaged','Lost') NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `disposed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `help_queries`
--

CREATE TABLE `help_queries` (
  `id` int(11) NOT NULL,
  `staff_username` varchar(50) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('Pending','Resolved') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_approvals`
--

CREATE TABLE `payment_approvals` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_by_username` varchar(100) DEFAULT NULL,
  `step1_approver_id` int(11) DEFAULT NULL,
  `step1_username` varchar(100) DEFAULT NULL,
  `step1_at` datetime DEFAULT NULL,
  `step1_action` enum('approved','denied') DEFAULT NULL,
  `step2_approver_id` int(11) DEFAULT NULL,
  `step2_username` varchar(100) DEFAULT NULL,
  `step2_at` datetime DEFAULT NULL,
  `step2_action` enum('approved','denied') DEFAULT NULL,
  `status` enum('pending_step1','pending_step2','approved','denied') DEFAULT 'pending_step1',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `price_history`
--

CREATE TABLE `price_history` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `change_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `price_update_logs`
--

CREATE TABLE `price_update_logs` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `action` enum('submitted','step1_approved','step1_rejected','step2_approved','step2_rejected','applied','cancelled','deferred','auto_applied') DEFAULT 'submitted',
  `actor_id` int(11) NOT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `old_price` decimal(10,2) DEFAULT NULL,
  `new_price` decimal(10,2) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `price_update_logs`
--

INSERT INTO `price_update_logs` (`id`, `request_id`, `action`, `actor_id`, `actor_username`, `old_price`, `new_price`, `note`, `created_at`) VALUES
(1, 1, 'submitted', 5, 'superadmin@josep', 16.00, 10.00, NULL, '2026-05-14 22:17:03');

-- --------------------------------------------------------

--
-- Table structure for table `price_update_requests`
--

CREATE TABLE `price_update_requests` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `current_price` decimal(10,2) NOT NULL,
  `proposed_price` decimal(10,2) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `invoice` varchar(100) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_username` varchar(100) DEFAULT NULL,
  `step1_by` int(11) DEFAULT NULL,
  `step1_username` varchar(100) DEFAULT NULL,
  `step1_at` datetime DEFAULT NULL,
  `step2_by` int(11) DEFAULT NULL,
  `step2_username` varchar(100) DEFAULT NULL,
  `step2_at` datetime DEFAULT NULL,
  `applied_by` int(11) DEFAULT NULL,
  `applied_username` varchar(100) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_username` varchar(100) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `status` enum('pending','step1_approved','approved','deferred','applied','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `locked_qty` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `price_update_requests`
--

INSERT INTO `price_update_requests` (`id`, `product_id`, `product_name`, `barcode`, `current_price`, `proposed_price`, `supplier_id`, `supplier_name`, `invoice`, `submitted_by`, `submitted_username`, `step1_by`, `step1_username`, `step1_at`, `step2_by`, `step2_username`, `step2_at`, `applied_by`, `applied_username`, `applied_at`, `rejected_by`, `rejected_username`, `rejected_at`, `reject_reason`, `status`, `created_at`, `locked_qty`) VALUES
(1, 1, 'BearBrandChoco29G', '4800361409117', 16.00, 10.00, 1, 'Joseph Award', 'DEL-20260514-5F60', 5, 'superadmin@josep', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-05-14 22:17:03', 10);

-- --------------------------------------------------------

--
-- Table structure for table `procurement_access_log`
--

CREATE TABLE `procurement_access_log` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `staff_username` varchar(100) DEFAULT NULL,
  `action` enum('requested','approved','denied','consumed','recount_auto') DEFAULT 'requested',
  `actioned_by` int(11) DEFAULT NULL,
  `actioned_by_username` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procurement_access_log`
--

INSERT INTO `procurement_access_log` (`id`, `staff_id`, `staff_username`, `action`, `actioned_by`, `actioned_by_username`, `created_at`) VALUES
(1, 7, 'josepstafff', 'consumed', NULL, NULL, '2026-05-14 21:31:51'),
(2, 7, 'josepstafff', 'requested', NULL, NULL, '2026-05-14 21:33:00'),
(3, 7, 'josepstafff', '', 5, 'superadmin@josep', '2026-05-14 21:38:36'),
(4, 7, 'josepstafff', 'consumed', NULL, NULL, '2026-05-14 21:52:18'),
(5, 5, 'superadmin@josep', 'consumed', NULL, NULL, '2026-05-14 21:56:45'),
(6, 5, 'superadmin@josep', 'consumed', NULL, NULL, '2026-05-14 22:17:03'),
(7, 5, 'superadmin@josep', 'consumed', NULL, NULL, '2026-05-14 22:47:36'),
(8, 7, 'josepstafff', 'requested', NULL, NULL, '2026-05-15 07:57:09'),
(9, 7, 'josepstafff', '', 5, 'superadmin@josep', '2026-05-15 07:57:29'),
(10, 7, 'josepstafff', 'consumed', NULL, NULL, '2026-05-15 07:58:56'),
(11, 7, 'josepstafff', 'requested', NULL, NULL, '2026-05-21 19:41:30');

-- --------------------------------------------------------

--
-- Table structure for table `procurement_batches`
--

CREATE TABLE `procurement_batches` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `staff_username` varchar(100) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_username` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `invoice` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `encoding_started_at` datetime DEFAULT NULL,
  `receiving_started_at` datetime DEFAULT NULL,
  `officialized_at` datetime DEFAULT NULL,
  `status` enum('approved','encoding','receiving','complete_clean','complete_errors','recount_pending') DEFAULT 'approved',
  `item_count` int(11) DEFAULT 0,
  `discrepancy_count` int(11) DEFAULT 0,
  `price_flag_count` int(11) DEFAULT 0,
  `minutes_to_complete` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procurement_batches`
--

INSERT INTO `procurement_batches` (`id`, `staff_id`, `staff_username`, `approved_by`, `approved_by_username`, `supplier_id`, `supplier_name`, `invoice`, `approved_at`, `encoding_started_at`, `receiving_started_at`, `officialized_at`, `status`, `item_count`, `discrepancy_count`, `price_flag_count`, `minutes_to_complete`, `created_at`) VALUES
(1, 7, 'josepstafff', 5, 'superadmin@josep', 2, 'PauCorp', 'DEL-20260514-647C', '2026-05-15 05:38:36', '2026-05-15 05:43:35', '2026-05-15 05:50:48', '2026-05-15 05:52:18', 'complete_clean', 3, 0, 0, 351, '2026-05-14 21:38:36'),
(2, 7, 'josepstafff', 5, 'superadmin@josep', 2, 'PauCorp', 'DEL-20260514-647C', '2026-05-15 15:57:29', '2026-05-15 15:57:45', '2026-05-15 15:58:17', '2026-05-15 15:58:56', 'complete_errors', 1, 1, 0, 358, '2026-05-15 07:57:29'),
(3, 7, 'josepstafff', 6, 'josepadmin', 3, 'MARCOPOLLOCORPO', 'DEL-20260521-B97C', '2026-05-22 03:42:41', '2026-05-22 03:43:05', '2026-05-22 03:46:58', NULL, 'receiving', 0, 0, 0, NULL, '2026-05-21 19:42:41');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `parent_barcode` varchar(50) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `delivery_status` enum('PENDING','VERIFIED') DEFAULT 'VERIFIED',
  `cost_price` decimal(10,2) DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'General',
  `last_buy_cost` decimal(10,2) DEFAULT 0.00,
  `bulk_qty_half` int(11) DEFAULT 0 COMMENT 'Qty for half box pricing',
  `price_half_box` decimal(10,2) DEFAULT 0.00,
  `bulk_qty_full` int(11) DEFAULT 0 COMMENT 'Qty for full box pricing',
  `price_full_box` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','archived','draft') DEFAULT 'draft',
  `max_quantity` int(11) DEFAULT 0,
  `tiers_locked` tinyint(1) DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `supplier_id`, `barcode`, `parent_barcode`, `name`, `price`, `quantity`, `delivery_status`, `cost_price`, `category`, `last_buy_cost`, `bulk_qty_half`, `price_half_box`, `bulk_qty_full`, `price_full_box`, `status`, `max_quantity`, `tiers_locked`, `expiry_date`, `archived_at`) VALUES
(1, 1, '4800361409117', NULL, 'BearBrandChoco29G', 16.00, 15, 'VERIFIED', NULL, 'Supplies', 0.00, 0, 0.00, 0, 0.00, 'active', 15, 0, '2027-02-28', '2026-05-15 05:34:09'),
(2, 1, '4801668600542', NULL, 'DatuPutiPatis150ML', 7.00, 9, 'VERIFIED', NULL, 'Food', 0.00, 10, 67.00, 0, 0.00, 'active', 9, 0, '2027-09-15', NULL),
(3, 1, '7622300637996', NULL, 'TangGrapes19G', 23.00, 0, 'VERIFIED', NULL, 'Beverage', 0.00, 0, 0.00, 0, 0.00, 'archived', 11, 0, '2026-06-12', '2026-05-15 06:44:25'),
(4, 2, '4801668600542', NULL, 'DatuPutiPatis150ML', 6.00, 10, 'VERIFIED', NULL, 'Food', 0.00, 0, 0.00, 0, 0.00, 'active', 10, 0, '2027-09-15', NULL),
(5, 2, '7622300637996', NULL, 'TangGrapes19G', 22.00, 10, 'VERIFIED', NULL, 'Beverage', 0.00, 0, 0.00, 0, 0.00, 'active', 10, 0, '2026-05-15', NULL),
(6, 2, '4801668606117', NULL, 'MangTomasSpc80G', 15.00, 10, 'VERIFIED', NULL, 'Food', 0.00, 0, 0.00, 0, 0.00, 'active', 10, 0, '2026-08-24', NULL),
(7, 1, '4801668606117', NULL, 'MangTomasSpc80G', 5.00, 10, 'VERIFIED', NULL, 'Food', 0.00, 0, 0.00, 0, 0.00, 'active', 10, 0, '2026-08-24', NULL),
(9, 2, '4800361409117', NULL, 'BearBrandChoco29G', 15.00, 69, 'VERIFIED', NULL, 'Supplies', 0.00, 0, 0.00, 0, 0.00, 'active', 69, 0, '2026-05-15', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `products_backup_clean`
--

CREATE TABLE `products_backup_clean` (
  `id` int(11) DEFAULT NULL,
  `supplier_id` int(11),
  `barcode` varchar(50) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `quantity` decimal(32,0) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `status` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bulk_qty_half` int(11) DEFAULT NULL COMMENT 'Qty for half box pricing',
  `price_half_box` decimal(10,2) DEFAULT NULL,
  `bulk_qty_full` int(11) DEFAULT NULL COMMENT 'Qty for full box pricing',
  `price_full_box` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products_temp`
--

CREATE TABLE `products_temp` (
  `id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `quantity` decimal(32,0) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `supplier_id` int(11)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_disposals`
--

CREATE TABLE `product_disposals` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `reason` enum('Expired','Contaminated','Damaged','Spoiled','Other') DEFAULT 'Expired',
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_username` varchar(100) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_username` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quantity_alerts`
--

CREATE TABLE `quantity_alerts` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `invoice` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `batch_qty` int(11) DEFAULT NULL,
  `received_qty` int(11) DEFAULT NULL,
  `flagged_by` int(11) DEFAULT NULL,
  `status` enum('pending','recounting','submitted','approved','rejected','resolved') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actual_qty` int(11) DEFAULT NULL,
  `expected_qty` int(11) DEFAULT NULL,
  `variance` int(11) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `fail_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quantity_alerts`
--

INSERT INTO `quantity_alerts` (`id`, `product_name`, `barcode`, `invoice`, `supplier_id`, `batch_qty`, `received_qty`, `flagged_by`, `status`, `created_at`, `actual_qty`, `expected_qty`, `variance`, `requested_by`, `submitted_by`, `submitted_at`, `approved_by`, `approved_at`, `reject_reason`, `product_id`, `fail_count`) VALUES
(1, 'DatuPutiPatis150ML', '4801668600542', 'DEL-20260514-5F60', 1, 10, 9, 7, 'resolved', '2026-05-14 21:31:51', 10, 9, -1, NULL, 7, '2026-05-15 05:40:57', 5, NULL, NULL, 2, 2),
(2, 'TangGrapes19G', '7622300637996', 'DEL-20260514-5F60', 1, 10, 11, 7, 'resolved', '2026-05-14 21:31:51', 10, 11, 1, NULL, 7, '2026-05-15 05:40:57', 5, NULL, NULL, 3, 2),
(3, 'BearBrandChoco29G', '4800361409117', 'DEL-20260514-647C', 2, 5, 10, 5, 'submitted', '2026-05-14 22:47:36', 10, 10, 0, NULL, 7, '2026-05-15 16:00:17', 5, NULL, NULL, 9, 2),
(4, 'BearBrandChoco29G', '4800361409117', 'DEL-20260514-647C', 2, 60, 59, 7, 'submitted', '2026-05-15 07:58:56', 59, 59, 0, NULL, 7, '2026-05-15 16:00:17', 5, NULL, NULL, 9, 0);

-- --------------------------------------------------------

--
-- Table structure for table `recount_mismatch_log`
--

CREATE TABLE `recount_mismatch_log` (
  `id` int(11) NOT NULL,
  `alert_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `invoice` varchar(100) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `expected_qty` int(11) NOT NULL,
  `submitted_qty` int(11) NOT NULL,
  `variance` int(11) NOT NULL,
  `fail_number` int(11) DEFAULT 1,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_username` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `recount_mismatch_log`
--

INSERT INTO `recount_mismatch_log` (`id`, `alert_id`, `product_id`, `product_name`, `barcode`, `invoice`, `supplier_id`, `expected_qty`, `submitted_qty`, `variance`, `fail_number`, `submitted_by`, `submitted_username`, `created_at`) VALUES
(1, 1, 2, 'DatuPutiPatis150ML', '4801668600542', 'DEL-20260514-5F60', 1, 9, 10, -1, 1, 7, 'josepstafff', '2026-05-14 21:38:52'),
(2, 2, 3, 'TangGrapes19G', '7622300637996', 'DEL-20260514-5F60', 1, 11, 9, 2, 1, 7, 'josepstafff', '2026-05-14 21:38:52'),
(3, 1, 2, 'DatuPutiPatis150ML', '4801668600542', 'DEL-20260514-5F60', 1, 9, 10, -1, 2, 7, 'josepstafff', '2026-05-14 21:40:57'),
(4, 2, 3, 'TangGrapes19G', '7622300637996', 'DEL-20260514-5F60', 1, 11, 10, 1, 2, 7, 'josepstafff', '2026-05-14 21:40:57'),
(5, 3, 9, 'BearBrandChoco29G', '4800361409117', 'DEL-20260514-647C', 2, 10, 5, 5, 1, 7, 'josepstafff', '2026-05-14 22:48:26'),
(6, 3, 9, 'BearBrandChoco29G', '4800361409117', 'DEL-20260514-647C', 2, 10, 5, 5, 2, 7, 'josepstafff', '2026-05-14 22:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `refunds`
--

CREATE TABLE `refunds` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `disposition` enum('restock','damaged') NOT NULL DEFAULT 'restock',
  `amount_refunded` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','overridden') NOT NULL DEFAULT 'approved',
  `requested_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `override_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reject_note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `refunds`
--

INSERT INTO `refunds` (`id`, `sale_id`, `product_id`, `qty`, `disposition`, `amount_refunded`, `reason`, `status`, `requested_by`, `reviewed_by`, `reviewed_at`, `override_note`, `created_at`, `reject_note`) VALUES
(1, 1, 1, 5, 'restock', 89.60, 'Customer Return', 'approved', 7, 5, '2026-05-14 23:36:35', NULL, '2026-05-14 21:34:33', NULL),
(2, 1, 1, 5, 'damaged', 86.24, 'Customer Return', 'approved', 7, 5, '2026-05-14 23:35:58', NULL, '2026-05-14 21:34:38', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `receipt_no` varchar(20) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `cash` decimal(10,2) DEFAULT NULL,
  `change_amt` decimal(10,2) DEFAULT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_mode` enum('Cash','GCash','Maya','Credit Card') DEFAULT 'Cash',
  `reference_no` varchar(100) DEFAULT NULL,
  `discount_name` varchar(100) DEFAULT 'None',
  `discount_amount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `receipt_no`, `user_id`, `total`, `cash`, `change_amt`, `sale_date`, `created_at`, `payment_mode`, `reference_no`, `discount_name`, `discount_amount`) VALUES
(1, 'RCPT-20260514-2241', NULL, 0.00, 180.00, 4.16, '2026-05-15 05:34:09', '2026-05-14 21:34:09', 'Cash', NULL, 'None', 0.00),
(2, 'RCPT-20260515-0DEC', NULL, 283.36, 283.36, 0.00, '2026-05-15 06:44:25', '2026-05-14 22:44:25', 'Cash', NULL, 'None', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `sales_items`
--

CREATE TABLE `sales_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_items`
--

INSERT INTO `sales_items` (`id`, `sale_id`, `product_id`, `qty`, `price`) VALUES
(1, 1, 1, 0, 15.70),
(2, 2, 3, 11, 23.00);

-- --------------------------------------------------------

--
-- Table structure for table `security_flags`
--

CREATE TABLE `security_flags` (
  `id` int(11) NOT NULL,
  `flag_type` varchar(50) DEFAULT NULL,
  `severity` enum('low','medium','high') DEFAULT 'medium',
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `status` enum('open','reviewed','dismissed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_flags`
--

INSERT INTO `security_flags` (`id`, `flag_type`, `severity`, `reference_id`, `reference_type`, `message`, `reviewed_by`, `reviewed_at`, `status`, `created_at`) VALUES
(1, 'duplicate_refund', 'high', 1, 'sale', 'Receipt #RCPT-20260514-2241 has 2 refund entries — possible double refund.', NULL, NULL, 'open', '2026-05-14 21:35:04'),
(2, 'access_event', 'low', 7, 'user', 'Procurement access approved for @josepstafff by @superadmin@josep.', NULL, NULL, 'open', '2026-05-14 21:38:36'),
(3, 'recount_double_fail', 'high', 1, 'quantity_alert', 'SUPERVISION TRIGGERED: Alert #1 \'DatuPutiPatis150ML\' (Invoice: DEL-20260514-5F60) failed tally 2 times. Users flagged: 7', NULL, NULL, 'open', '2026-05-14 21:40:57'),
(4, 'recount_double_fail', 'high', 2, 'quantity_alert', 'SUPERVISION TRIGGERED: Alert #2 \'TangGrapes19G\' (Invoice: DEL-20260514-5F60) failed tally 2 times. Users flagged: 7', NULL, NULL, 'open', '2026-05-14 21:40:57'),
(5, 'recount_double_fail', 'high', 3, 'quantity_alert', 'SUPERVISION TRIGGERED: Alert #3 \'BearBrandChoco29G\' (Invoice: DEL-20260514-647C) failed tally 2 times. Users flagged: 7, 5', NULL, NULL, 'open', '2026-05-14 22:54:31'),
(6, 'access_event', 'low', 7, 'user', 'Procurement access approved for @josepstafff by @superadmin@josep.', NULL, NULL, 'open', '2026-05-15 07:57:29');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) DEFAULT NULL,
  `setting_value` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_delete_requests`
--

CREATE TABLE `stock_delete_requests` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_invoice_id` varchar(50) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `supplier_code` varchar(50) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_invoice_id`, `name`, `supplier_code`, `invoice_number`, `contact`, `amount`, `created_at`) VALUES
(1, NULL, 'Joseph Award', '1001', 'DEL-20260514-5F60', '09687576535642', 100000.00, '2026-05-14 21:25:03'),
(2, NULL, 'PauCorp', '1002', 'DEL-20260514-647C', '0023543826343', 50000.00, '2026-05-14 21:42:52'),
(3, NULL, 'MARCOPOLLOCORPO', '1003', 'DEL-20260521-B97C', '09109491242', 10000.00, '2026-05-21 19:42:41');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('PAID','UNPAID') DEFAULT 'UNPAID',
  `payment_date` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_payments`
--

INSERT INTO `supplier_payments` (`id`, `supplier_id`, `invoice_no`, `amount`, `status`, `payment_date`, `created_at`) VALUES
(1, 1, 'DEL-20260514-5F60', 100000.00, 'UNPAID', '2026-05-15 05:25:03', '2026-05-14 21:25:03'),
(2, 2, 'DEL-20260514-647C', 50000.00, 'UNPAID', '2026-05-15 05:42:52', '2026-05-14 21:42:52'),
(3, 3, 'DEL-20260521-B97C', 10000.00, 'UNPAID', '2026-05-22 03:42:41', '2026-05-21 19:42:41');

-- --------------------------------------------------------

--
-- Table structure for table `support_messages`
--

CREATE TABLE `support_messages` (
  `id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_username` varchar(100) DEFAULT NULL,
  `sender_role` varchar(50) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `support_messages`
--

INSERT INTO `support_messages` (`id`, `ticket_id`, `sender_id`, `sender_username`, `sender_role`, `message`, `created_at`) VALUES
(1, 1, 7, 'josepstafff', 'staff', 'Sira printer', '2026-05-14 21:35:00');

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('open','in_progress','resolved') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `support_tickets`
--

INSERT INTO `support_tickets` (`id`, `user_id`, `username`, `subject`, `status`, `created_at`, `resolved_at`, `updated_at`) VALUES
(1, 7, 'josepstafff', 'Printer not working', 'open', '2026-05-14 21:35:00', NULL, '2026-05-15 05:35:00');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) DEFAULT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'store_name', 'My Store'),
(2, 'low_stock_threshold', '10');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('superadmin','admin','staff','owner','member') DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','TERMINATED') DEFAULT 'ACTIVE',
  `terminated_at` datetime DEFAULT NULL,
  `termination_reason` varchar(255) DEFAULT NULL,
  `reset_requested` tinyint(1) DEFAULT 0,
  `procurement_access` enum('none','pending','approved','denied') DEFAULT 'none',
  `supervision_flag` enum('none','supervised') DEFAULT 'none',
  `supervision_flagged_at` datetime DEFAULT NULL,
  `procurement_denial_reason` text DEFAULT NULL,
  `locked_supplier_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `contact_no`, `password`, `role`, `status`, `terminated_at`, `termination_reason`, `reset_requested`, `procurement_access`, `supervision_flag`, `supervision_flagged_at`, `procurement_denial_reason`, `locked_supplier_id`) VALUES
(1, 'admin', NULL, NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'TERMINATED', '2026-05-13 15:18:25', 'qweqwe', 0, 'none', 'none', NULL, NULL, NULL),
(2, 'josep', NULL, NULL, '$2y$10$g.CHFQrBffnC74RxEThDXeqlWNd7Sc7A9YHtdGgSqlSGyfPBd1nx2', 'admin', 'TERMINATED', '2026-05-13 15:18:18', 'qweqwe', 1, 'none', 'none', NULL, NULL, NULL),
(3, 'josepstaff', NULL, NULL, '$2y$10$i5pyoywn/YEjY5uLa6a5xeCVqkPy3CYfmBhjlTt1xq8NYsaBF/2Li', 'staff', 'TERMINATED', '2026-05-13 15:18:10', 'qwe', 1, 'none', 'none', NULL, NULL, NULL),
(4, 'superadmin', NULL, NULL, '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uaxFDPiy2', 'superadmin', 'TERMINATED', '2026-05-13 15:18:04', 'ayoko na', 0, 'none', 'none', NULL, NULL, NULL),
(5, 'superadmin@josep', NULL, NULL, '$2y$10$u652Bf0H1QJKrA6b8ey/IulBLdgsirKqmu7vWnYmv3a4HBthlkhrO', 'superadmin', 'ACTIVE', NULL, NULL, 1, 'none', 'none', NULL, NULL, NULL),
(6, 'josepadmin', 'Joseph Christian Polido', '09109492142', '$2y$10$42YJgOG0SxhsxxSeGtG2W.Ch7fUTbirjC67SADHyyBQmI3i1CEbia', 'admin', 'ACTIVE', NULL, NULL, 0, 'none', 'none', NULL, NULL, NULL),
(7, 'josepstafff', 'Joseph Christian Polido', '09109492142', '$2y$10$dAR2NXoN4KdrtoBvJAwgY.QLMNLAdj7zsgLJJQu1QaQZ8/uCANnlu', 'staff', 'ACTIVE', NULL, NULL, 0, 'approved', 'supervised', '2026-05-15 05:40:57', NULL, 3);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `admin_notifications`
--
ALTER TABLE `admin_notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_queue`
--
ALTER TABLE `delivery_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `delivery_returns`
--
ALTER TABLE `delivery_returns`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_return_requests`
--
ALTER TABLE `delivery_return_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_return_request_items`
--
ALTER TABLE `delivery_return_request_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `discounts`
--
ALTER TABLE `discounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `promo_code` (`promo_code`);

--
-- Indexes for table `disposals`
--
ALTER TABLE `disposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `help_queries`
--
ALTER TABLE `help_queries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `price_history`
--
ALTER TABLE `price_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `price_update_logs`
--
ALTER TABLE `price_update_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `price_update_requests`
--
ALTER TABLE `price_update_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `procurement_access_log`
--
ALTER TABLE `procurement_access_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `procurement_batches`
--
ALTER TABLE `procurement_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_product_supplier` (`supplier_id`);

--
-- Indexes for table `product_disposals`
--
ALTER TABLE `product_disposals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `quantity_alerts`
--
ALTER TABLE `quantity_alerts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `recount_mismatch_log`
--
ALTER TABLE `recount_mismatch_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `refunds`
--
ALTER TABLE `refunds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `security_flags`
--
ALTER TABLE `security_flags`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stock_delete_requests`
--
ALTER TABLE `stock_delete_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_invoice_id` (`supplier_invoice_id`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `support_messages`
--
ALTER TABLE `support_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `admin_notifications`
--
ALTER TABLE `admin_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_items`
--
ALTER TABLE `delivery_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_queue`
--
ALTER TABLE `delivery_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_returns`
--
ALTER TABLE `delivery_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_return_requests`
--
ALTER TABLE `delivery_return_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_return_request_items`
--
ALTER TABLE `delivery_return_request_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discounts`
--
ALTER TABLE `discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `disposals`
--
ALTER TABLE `disposals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `help_queries`
--
ALTER TABLE `help_queries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_approvals`
--
ALTER TABLE `payment_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `price_history`
--
ALTER TABLE `price_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `price_update_logs`
--
ALTER TABLE `price_update_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `price_update_requests`
--
ALTER TABLE `price_update_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `procurement_access_log`
--
ALTER TABLE `procurement_access_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `procurement_batches`
--
ALTER TABLE `procurement_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `product_disposals`
--
ALTER TABLE `product_disposals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quantity_alerts`
--
ALTER TABLE `quantity_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `recount_mismatch_log`
--
ALTER TABLE `recount_mismatch_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `refunds`
--
ALTER TABLE `refunds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `security_flags`
--
ALTER TABLE `security_flags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_delete_requests`
--
ALTER TABLE `stock_delete_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `support_messages`
--
ALTER TABLE `support_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `delivery_queue`
--
ALTER TABLE `delivery_queue`
  ADD CONSTRAINT `delivery_queue_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `disposals`
--
ALTER TABLE `disposals`
  ADD CONSTRAINT `disposals_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `price_history`
--
ALTER TABLE `price_history`
  ADD CONSTRAINT `price_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_product_supplier_rel` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `refunds`
--
ALTER TABLE `refunds`
  ADD CONSTRAINT `refunds_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `refunds_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `stock_delete_requests`
--
ALTER TABLE `stock_delete_requests`
  ADD CONSTRAINT `stock_delete_requests_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
