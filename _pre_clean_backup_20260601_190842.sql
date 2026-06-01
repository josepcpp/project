-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: posiisdb
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `posiisdb`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `posiisdb` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `posiisdb`;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `log_type` enum('Sales','Disposal','Deliveries','Payments','Out_of_Stock','System','Prices') NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,9,'',1,'Pipeline push: NEW product \"Pocari Sweat 900ML\" (barcode: 4800350108878) — 10 units @ ₱100.00 (Batch #1)',NULL,NULL,'2026-05-29 20:05:38'),(2,6,'Sales',1,'Completed sale #RCPT-20260529-CC5B. Mode: Cash. Total: ₱1,008.00',NULL,NULL,'2026-05-29 20:23:36'),(3,6,'Sales',2,'Completed sale #RCPT-20260529-6174. Mode: Cash. Total: ₱112.00',NULL,NULL,'2026-05-29 20:24:12'),(4,9,'',1,'Pipeline push: +10 units for \"Pocari Sweat 900ML\" (Batch #3)',NULL,NULL,'2026-05-30 18:08:54'),(5,10,'',1,'Pipeline push: +9 units for \"Pocari Sweat 900ML\" (Batch #7) [PRICE CHANGE PENDING REVIEW]',NULL,NULL,'2026-05-30 19:50:32'),(6,10,'',1,'Pipeline push: +9 units for \"Pocari Sweat 900ML\" (Batch #4) [PRICE CHANGE PENDING REVIEW]',NULL,NULL,'2026-05-30 19:50:42'),(7,10,'',1,'Pipeline push: +9 units for \"Pocari Sweat 900ML\" (Batch #6) [PRICE CHANGE PENDING REVIEW]',NULL,NULL,'2026-05-30 19:54:10'),(8,10,'',1,'Pipeline push: +9 units for \"Pocari Sweat 900ML\" (Batch #5) [PRICE CHANGE PENDING REVIEW]',NULL,NULL,'2026-05-30 19:54:12'),(9,6,'',2,'Pipeline push: NEW item \"Nescafe Classic\" (barcode: 123123123532) — 9 units @ cost ₱100.00 — awaiting Admin selling price (Batch #8).',NULL,NULL,'2026-05-31 12:22:36'),(10,6,'Prices',2,'SELLING PRICE SET: Nescafe Classic → ₱111.00 (released to POS)','0.00','111.00','2026-05-31 12:39:41'),(11,6,'',1,'Exchange #EXC-20260531-3563 for receipt RCPT-20260529-6174. Delta: collect ₱11.00',NULL,NULL,'2026-05-31 12:40:34'),(12,6,'',2,'Pipeline push: +9 units for \"Nescafe Classic\" (Batch #8) — cost unchanged, sellable.',NULL,NULL,'2026-05-31 14:15:49'),(13,9,'',3,'Pipeline push: NEW item \"Mang Tomas Siga Jr, 80G\" (barcode: 4801668606117) — 10 units @ cost ₱100.00 — awaiting Admin selling price (Batch #9).',NULL,NULL,'2026-05-31 18:19:33'),(14,6,'Prices',3,'SELLING PRICE SET: Mang Tomas Siga Jr, 80G → ₱102.00 (released to POS)','0.00','102.00','2026-05-31 18:20:18'),(15,9,'',1,'Pipeline push: +10 units for \"Pocari Sweat 900ML\" (Batch #11) — cost unchanged, sellable.',NULL,NULL,'2026-05-31 20:38:09'),(16,6,'Prices',1,'TIER UPDATE: Pocari Sweat 900ML —',NULL,NULL,'2026-05-31 20:38:28'),(17,6,'Prices',1,'RETAIL PRICE: Pocari Sweat 900ML ₱100.00 → ₱111.00','100.00','111.00','2026-05-31 20:38:28'),(21,8,'',2,'Exchange #EXC-20260601-6E23 for receipt RCPT-20260529-6174. Delta: collect ₱920.00',NULL,NULL,'2026-06-01 09:17:33'),(22,8,'',3,'Exchange #EXC-20260601-CBE9 for receipt RCPT-20260529-CC5B. Delta: collect ₱1,787.00',NULL,NULL,'2026-06-01 09:21:15'),(23,8,'Sales',3,'Completed sale #RCPT-20260601-5A89. Mode: Cash. Total: ₱124.32',NULL,NULL,'2026-06-01 09:21:35'),(24,8,'Disposal',1,'REFUND REQUEST #RCPT-20260601-5A89: 1 pcs of \"Pocari Sweat 900ML\" — awaiting admin approval. Est. ₱124.32',NULL,NULL,'2026-06-01 09:27:07'),(25,8,'',4,'Exchange #EXC-20260601-71F3 for receipt RCPT-20260601-5A89. Delta: collect ₱111.00',NULL,NULL,'2026-06-01 09:37:02');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_notifications`
--

DROP TABLE IF EXISTS `admin_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('POS','DELIVERY','PAYMENT','DELETE_REQ') DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_notifications`
--

LOCK TABLES `admin_notifications` WRITE;
/*!40000 ALTER TABLE `admin_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backup_logs`
--

DROP TABLE IF EXISTS `backup_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) DEFAULT NULL,
  `size_kb` int(11) DEFAULT NULL,
  `status` enum('success','failed') DEFAULT 'success',
  `method` enum('manual','auto') DEFAULT 'manual',
  `triggered_by` int(11) DEFAULT NULL,
  `trigger_username` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup_logs`
--

LOCK TABLES `backup_logs` WRITE;
/*!40000 ALTER TABLE `backup_logs` DISABLE KEYS */;
INSERT INTO `backup_logs` VALUES (1,'backup_posiisdb_20260531_182016.sql',0,'failed','manual',6,'0','2026-05-31 16:20:16');
/*!40000 ALTER TABLE `backup_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bundle_items`
--

DROP TABLE IF EXISTS `bundle_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bundle_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_bundle` (`bundle_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bundle_items`
--

LOCK TABLES `bundle_items` WRITE;
/*!40000 ALTER TABLE `bundle_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `bundle_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bundles`
--

DROP TABLE IF EXISTS `bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bundles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `bundle_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bundles`
--

LOCK TABLES `bundles` WRITE;
/*!40000 ALTER TABLE `bundles` DISABLE KEYS */;
/*!40000 ALTER TABLE `bundles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_groups`
--

DROP TABLE IF EXISTS `customer_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `label` varchar(50) NOT NULL,
  `discount_type` enum('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_groups`
--

LOCK TABLES `customer_groups` WRITE;
/*!40000 ALTER TABLE `customer_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deliveries`
--

DROP TABLE IF EXISTS `deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `status` enum('PENDING','VERIFIED') DEFAULT 'PENDING',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deliveries`
--

LOCK TABLES `deliveries` WRITE;
/*!40000 ALTER TABLE `deliveries` DISABLE KEYS */;
/*!40000 ALTER TABLE `deliveries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_damage_tickets`
--

DROP TABLE IF EXISTS `delivery_damage_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_damage_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `raised_by` int(11) DEFAULT NULL,
  `raised_by_username` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected','expired') DEFAULT 'pending',
  `damage_summary` text DEFAULT NULL,
  `total_deduction` decimal(10,2) DEFAULT 0.00,
  `snapshot_discrepancy` decimal(10,2) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_by_username` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  CONSTRAINT `delivery_damage_tickets_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `receiving_batches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_damage_tickets`
--

LOCK TABLES `delivery_damage_tickets` WRITE;
/*!40000 ALTER TABLE `delivery_damage_tickets` DISABLE KEYS */;
INSERT INTO `delivery_damage_tickets` VALUES (1,4,9,'JosepValidate','rejected','Damaged Item',100.00,100.00,6,'0','2026-05-31 02:39:16',NULL,'2026-05-30 18:11:26'),(2,7,9,'JosepValidate','rejected','Leak',100.00,100.00,6,'0','2026-05-31 03:19:31',NULL,'2026-05-30 19:18:55'),(3,5,9,'JosepValidate','rejected','ehey',100.00,100.00,6,'0','2026-05-31 03:53:09',NULL,'2026-05-30 19:52:25'),(4,6,9,'JosepValidate','rejected','ehey',100.00,100.00,6,'0','2026-05-31 03:53:07',NULL,'2026-05-30 19:52:36'),(5,8,9,'JosepValidate','approved','damaged item',100.00,100.00,6,'josepadmin','2026-05-31 22:15:49',NULL,'2026-05-30 20:17:30');
/*!40000 ALTER TABLE `delivery_damage_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_queue`
--

DROP TABLE IF EXISTS `delivery_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `status` enum('PENDING','VALIDATED') DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `delivery_queue_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_queue`
--

LOCK TABLES `delivery_queue` WRITE;
/*!40000 ALTER TABLE `delivery_queue` DISABLE KEYS */;
/*!40000 ALTER TABLE `delivery_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_return_request_items`
--

DROP TABLE IF EXISTS `delivery_return_request_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_return_request_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT 'Damaged',
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_return_request_items`
--

LOCK TABLES `delivery_return_request_items` WRITE;
/*!40000 ALTER TABLE `delivery_return_request_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `delivery_return_request_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_return_requests`
--

DROP TABLE IF EXISTS `delivery_return_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_return_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_return_requests`
--

LOCK TABLES `delivery_return_requests` WRITE;
/*!40000 ALTER TABLE `delivery_return_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `delivery_return_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery_returns`
--

DROP TABLE IF EXISTS `delivery_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT 'Damaged',
  `deduct_pay` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery_returns`
--

LOCK TABLES `delivery_returns` WRITE;
/*!40000 ALTER TABLE `delivery_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `delivery_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `discounts`
--

DROP TABLE IF EXISTS `discounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `discounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `promo_code` varchar(20) DEFAULT NULL,
  `type` enum('Percentage','Fixed') NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `usage_limit` int(11) DEFAULT 0 COMMENT '0 means unlimited',
  `used_count` int(11) DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `scope` enum('store','product','category') NOT NULL DEFAULT 'store',
  `target_product_id` int(11) DEFAULT NULL,
  `target_category` varchar(100) DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `conflict_rule` enum('best_for_customer','priority_order','stack') NOT NULL DEFAULT 'best_for_customer',
  PRIMARY KEY (`id`),
  UNIQUE KEY `promo_code` (`promo_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `discounts`
--

LOCK TABLES `discounts` WRITE;
/*!40000 ALTER TABLE `discounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `discounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `disposals`
--

DROP TABLE IF EXISTS `disposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `disposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `reason` enum('Expired','Damaged','Lost') NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `disposed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `disposals_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `disposals`
--

LOCK TABLES `disposals` WRITE;
/*!40000 ALTER TABLE `disposals` DISABLE KEYS */;
/*!40000 ALTER TABLE `disposals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exchange_items`
--

DROP TABLE IF EXISTS `exchange_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchange_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_id` int(11) NOT NULL,
  `direction` enum('return','outgoing') NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exchange_items`
--

LOCK TABLES `exchange_items` WRITE;
/*!40000 ALTER TABLE `exchange_items` DISABLE KEYS */;
INSERT INTO `exchange_items` VALUES (1,1,'return',1,'Pocari Sweat 900ML',1,100.00,100.00),(2,1,'outgoing',2,'Nescafe Classic',1,111.00,111.00),(3,2,'return',1,'Pocari Sweat 900ML',1,100.00,100.00),(4,2,'outgoing',3,'Mang Tomas Siga Jr, 80G',10,102.00,1020.00),(5,3,'return',1,'Pocari Sweat 900ML',1,100.00,100.00),(6,3,'outgoing',2,'Nescafe Classic',17,111.00,1887.00),(7,4,'return',1,'Pocari Sweat 900ML',1,111.00,111.00),(8,4,'outgoing',1,'Pocari Sweat 900ML',2,111.00,222.00);
/*!40000 ALTER TABLE `exchange_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exchanges`
--

DROP TABLE IF EXISTS `exchanges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchanges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_no` varchar(50) NOT NULL,
  `original_sale_id` int(11) NOT NULL,
  `original_receipt_no` varchar(50) DEFAULT NULL,
  `delta_type` enum('none','collect','refund') NOT NULL DEFAULT 'none',
  `delta_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_mode` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `processed_username` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exchanges`
--

LOCK TABLES `exchanges` WRITE;
/*!40000 ALTER TABLE `exchanges` DISABLE KEYS */;
INSERT INTO `exchanges` VALUES (1,'EXC-20260531-3563',2,'RCPT-20260529-6174','collect',11.00,'Cash',NULL,6,'josepadmin','','2026-05-31 12:40:34'),(2,'EXC-20260601-6E23',2,'RCPT-20260529-6174','collect',920.00,'Cash',NULL,8,'JosepRec','','2026-06-01 09:17:33'),(3,'EXC-20260601-CBE9',1,'RCPT-20260529-CC5B','collect',1787.00,'Cash',NULL,8,'JosepRec','','2026-06-01 09:21:15'),(4,'EXC-20260601-71F3',3,'RCPT-20260601-5A89','collect',111.00,'Cash',NULL,8,'JosepRec','','2026-06-01 09:37:02');
/*!40000 ALTER TABLE `exchanges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `help_queries`
--

DROP TABLE IF EXISTS `help_queries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `help_queries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_username` varchar(50) DEFAULT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('Pending','Resolved') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `help_queries`
--

LOCK TABLES `help_queries` WRITE;
/*!40000 ALTER TABLE `help_queries` DISABLE KEYS */;
/*!40000 ALTER TABLE `help_queries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_restrictions`
--

DROP TABLE IF EXISTS `ip_restrictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ip_restrictions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_cidr` varchar(50) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip` (`ip_cidr`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_restrictions`
--

LOCK TABLES `ip_restrictions` WRITE;
/*!40000 ALTER TABLE `ip_restrictions` DISABLE KEYS */;
INSERT INTO `ip_restrictions` VALUES (1,'::1','Admin (auto-added)',NULL,1,6,'2026-05-31 10:42:01');
/*!40000 ALTER TABLE `ip_restrictions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_id` int(11) DEFAULT NULL,
  `recipient_role` varchar(30) DEFAULT NULL,
  `type` enum('discrepancy','price_change','override','batch_rejected') NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient_id`),
  KEY `idx_role` (`recipient_role`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,NULL,'admin','discrepancy',4,'Batch #4 has a subtotal discrepancy. Computed: ₱900.00. Receiver reported damaged items.',1,'2026-05-30 18:11:03'),(2,NULL,'admin','discrepancy',4,'Damage Return Ticket raised for Batch #4 by @JosepValidate. Deduction: ₱100.00. Review required.',1,'2026-05-30 18:11:26'),(3,NULL,'validator','discrepancy',4,'Damage Return Ticket for Batch #4 was rejected by @josepadmin.',1,'2026-05-30 18:39:16'),(4,8,'validator','override',4,'Batch #4 has been re-opened for re-validation. Reason: Finalize',1,'2026-05-30 18:45:34'),(5,NULL,'admin','discrepancy',7,'Damage Return Ticket raised for Batch #7 by @JosepValidate. Deduction: ₱100.00. Review required.',1,'2026-05-30 19:18:55'),(6,NULL,'validator','discrepancy',7,'Damage Return Ticket for Batch #7 was rejected by @josepadmin.',1,'2026-05-30 19:19:31'),(7,8,'validator','override',7,'Batch #7 has been re-opened for re-validation. Reason: re-validate',1,'2026-05-30 19:22:18'),(8,NULL,'price_checker','override',4,'Batch #4 reopened for repricing - its damage ticket was rejected.',0,'2026-05-30 19:41:49'),(9,NULL,'price_checker','override',7,'Batch #7 reopened for repricing - its damage ticket was rejected.',0,'2026-05-30 19:41:49'),(11,8,'validator','override',5,'Batch #5 has been re-opened for re-validation. Reason: reopen',0,'2026-05-30 19:42:46'),(12,NULL,'admin','price_change',7,'Price change detected for \"Pocari Sweat 900ML\" (barcode: 4800350108878): ₱100.00 → ₱111.11 (Batch #7).',1,'2026-05-30 19:50:32'),(13,NULL,'admin','price_change',4,'Price change detected for \"Pocari Sweat 900ML\" (barcode: 4800350108878): ₱100.00 → ₱111.11 (Batch #4).',1,'2026-05-30 19:50:42'),(14,8,'validator','override',6,'Batch #6 has been re-opened for re-validation. Reason: rkasufd',0,'2026-05-30 19:51:59'),(15,NULL,'admin','discrepancy',5,'Damage Return Ticket raised for Batch #5 by @JosepValidate. Deduction: ₱100.00. Review required.',1,'2026-05-30 19:52:25'),(16,NULL,'admin','discrepancy',6,'Damage Return Ticket raised for Batch #6 by @JosepValidate. Deduction: ₱100.00. Review required.',1,'2026-05-30 19:52:36'),(17,NULL,'price_checker','override',6,'Batch #6 reopened for repricing — its damage ticket was rejected by @josepadmin.',0,'2026-05-30 19:53:07'),(18,NULL,'price_checker','override',5,'Batch #5 reopened for repricing — its damage ticket was rejected by @josepadmin.',0,'2026-05-30 19:53:09'),(19,NULL,'admin','price_change',6,'Price change detected for \"Pocari Sweat 900ML\" (barcode: 4800350108878): ₱100.00 → ₱111.11 (Batch #6).',1,'2026-05-30 19:54:10'),(20,NULL,'admin','price_change',5,'Price change detected for \"Pocari Sweat 900ML\" (barcode: 4800350108878): ₱100.00 → ₱111.11 (Batch #5).',1,'2026-05-30 19:54:12'),(21,NULL,'admin','discrepancy',8,'Damage Return Ticket raised for Batch #8 by @JosepValidate. Deduction: ₱100.00. Review required.',1,'2026-05-30 20:17:30'),(22,NULL,'admin','price_change',NULL,'Price Monitor margin report by @JosepPriceCheck — 1 item(s) below 10% margin:\n• Pocari Sweat 900ML (4800350108878): ₱100.00 / cost ₱100.00 = 0.0%',1,'2026-05-31 11:17:12'),(23,NULL,'admin','price_change',NULL,'Price Monitor flag by @JosepPriceCheck: \"Nescafe Classic\" (barcode: 123123123532) — delivered price first recorded delivery. Review required.',1,'2026-05-31 12:21:38'),(24,NULL,'admin','price_change',NULL,'Price Monitor flag by @JosepPriceCheck: \"Pocari Sweat 900ML\" (barcode: 4800350108878) — delivered price ₱111.11 → ₱111.11 (no change +0.00 / +0.0%). Review required.',1,'2026-05-31 12:22:03'),(25,8,'receiver','override',8,'Batch #8 discrepancy was overridden and accepted. Inventory updated.',0,'2026-05-31 12:22:36'),(26,NULL,'admin','price_change',8,'New item awaiting price: \"Nescafe Classic\" (barcode: 123123123532) — 9 unit(s) in Inventory. Set a selling price to release to POS.',1,'2026-05-31 12:22:36'),(27,NULL,'admin','price_change',9,'New item awaiting price: \"Mang Tomas Siga Jr, 80G\" (barcode: 4801668606117) — 10 unit(s) in Inventory. Set a selling price to release to POS.',0,'2026-05-31 18:19:33');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pipeline_price_changes`
--

DROP TABLE IF EXISTS `pipeline_price_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pipeline_price_changes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `raised_by` int(11) DEFAULT NULL,
  `raised_by_username` varchar(100) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pipeline_price_changes`
--

LOCK TABLES `pipeline_price_changes` WRITE;
/*!40000 ALTER TABLE `pipeline_price_changes` DISABLE KEYS */;
INSERT INTO `pipeline_price_changes` VALUES (1,7,8,'4800350108878','Pocari Sweat 900ML',100.00,111.11,'Inplay',10,'JosepPriceCheck','pending',NULL,NULL,'2026-05-30 19:50:32'),(2,4,5,'4800350108878','Pocari Sweat 900ML',100.00,111.11,'PauPauPauPau',10,'JosepPriceCheck','pending',NULL,NULL,'2026-05-30 19:50:42'),(3,6,7,'4800350108878','Pocari Sweat 900ML',100.00,111.11,'Inplay',10,'JosepPriceCheck','pending',NULL,NULL,'2026-05-30 19:54:10'),(4,5,6,'4800350108878','Pocari Sweat 900ML',100.00,111.11,'Josep',10,'JosepPriceCheck','pending',NULL,NULL,'2026-05-30 19:54:12');
/*!40000 ALTER TABLE `pipeline_price_changes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_history`
--

DROP TABLE IF EXISTS `price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `price_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `change_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `price_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_history`
--

LOCK TABLES `price_history` WRITE;
/*!40000 ALTER TABLE `price_history` DISABLE KEYS */;
INSERT INTO `price_history` VALUES (1,2,0.00,111.00,'2026-05-31 12:39:41'),(2,3,0.00,102.00,'2026-05-31 18:20:18'),(3,1,100.00,111.00,'2026-05-31 20:38:28');
/*!40000 ALTER TABLE `price_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_update_logs`
--

DROP TABLE IF EXISTS `price_update_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `price_update_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `action` enum('submitted','step1_approved','step1_rejected','step2_approved','step2_rejected','applied','cancelled','deferred','auto_applied') DEFAULT 'submitted',
  `actor_id` int(11) NOT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `old_price` decimal(10,2) DEFAULT NULL,
  `new_price` decimal(10,2) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_update_logs`
--

LOCK TABLES `price_update_logs` WRITE;
/*!40000 ALTER TABLE `price_update_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `price_update_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_update_requests`
--

DROP TABLE IF EXISTS `price_update_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `price_update_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `locked_qty` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_update_requests`
--

LOCK TABLES `price_update_requests` WRITE;
/*!40000 ALTER TABLE `price_update_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `price_update_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_tiers`
--

DROP TABLE IF EXISTS `pricing_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pricing_tiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `min_qty` int(11) NOT NULL,
  `discount_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `label` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tier_product_minqty` (`product_id`,`min_qty`),
  KEY `idx_product_active` (`product_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_tiers`
--

LOCK TABLES `pricing_tiers` WRITE;
/*!40000 ALTER TABLE `pricing_tiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `procurement_audit_log`
--

DROP TABLE IF EXISTS `procurement_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `procurement_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `tally_result` varchar(20) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `procurement_audit_log`
--

LOCK TABLES `procurement_audit_log` WRITE;
/*!40000 ALTER TABLE `procurement_audit_log` DISABLE KEYS */;
INSERT INTO `procurement_audit_log` VALUES (1,1,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-29 20:03:32'),(2,1,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-29 20:04:54'),(3,1,9,'JosepValidate','validator','validated_tally','match',NULL,'2026-05-29 20:05:38'),(4,1,9,'JosepValidate','validator','inventory_pushed',NULL,NULL,'2026-05-29 20:05:38'),(5,2,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 17:43:38'),(6,2,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 17:44:21'),(7,2,9,'JosepValidate','validator','validated_tally','match',NULL,'2026-05-30 17:44:53'),(8,3,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 18:08:06'),(9,3,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 18:08:30'),(10,3,9,'JosepValidate','validator','validated_tally','match',NULL,'2026-05-30 18:08:54'),(11,3,9,'JosepValidate','validator','inventory_pushed',NULL,NULL,'2026-05-30 18:08:54'),(12,4,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 18:09:46'),(13,4,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 18:10:44'),(14,4,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 18:11:03'),(15,4,6,'josepadmin','admin','reopen_validator',NULL,'Finalize','2026-05-30 18:45:34'),(16,4,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 18:46:57'),(17,5,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 18:49:58'),(18,5,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 18:51:15'),(19,5,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 18:51:43'),(20,6,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 19:10:13'),(21,6,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 19:10:50'),(22,6,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 19:11:20'),(23,7,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 19:14:08'),(24,7,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 19:14:50'),(25,7,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 19:17:42'),(26,7,6,'josepadmin','admin','reopen_validator',NULL,'re-validate','2026-05-30 19:22:18'),(27,5,6,'josepadmin','admin','reopen_validator',NULL,'reopen','2026-05-30 19:42:46'),(28,7,10,'JosepPriceCheck','price_checker','validated_tally','match',NULL,'2026-05-30 19:50:32'),(29,7,10,'JosepPriceCheck','price_checker','inventory_pushed',NULL,NULL,'2026-05-30 19:50:32'),(30,4,10,'JosepPriceCheck','price_checker','validated_tally','match',NULL,'2026-05-30 19:50:42'),(31,4,10,'JosepPriceCheck','price_checker','inventory_pushed',NULL,NULL,'2026-05-30 19:50:42'),(32,6,6,'josepadmin','admin','reopen_validator',NULL,'rkasufd','2026-05-30 19:51:59'),(33,5,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 19:52:20'),(34,6,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 19:52:28'),(35,6,6,'josepadmin','admin','reopen_price_checker',NULL,'Damage ticket rejected.','2026-05-30 19:53:07'),(36,5,6,'josepadmin','admin','reopen_price_checker',NULL,'Damage ticket rejected.','2026-05-30 19:53:09'),(37,6,10,'JosepPriceCheck','price_checker','validated_tally','match',NULL,'2026-05-30 19:54:10'),(38,6,10,'JosepPriceCheck','price_checker','inventory_pushed',NULL,NULL,'2026-05-30 19:54:10'),(39,5,10,'JosepPriceCheck','price_checker','validated_tally','match',NULL,'2026-05-30 19:54:12'),(40,5,10,'JosepPriceCheck','price_checker','inventory_pushed',NULL,NULL,'2026-05-30 19:54:12'),(41,8,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-30 20:15:13'),(42,8,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-30 20:16:20'),(43,8,9,'JosepValidate','validator','validated_discrepancy','discrepancy',NULL,'2026-05-30 20:17:20'),(44,8,6,'josepadmin','admin','override_accepted',NULL,'go na yan be','2026-05-31 12:22:36'),(45,8,6,'josepadmin','admin','inventory_pushed',NULL,NULL,'2026-05-31 12:22:36'),(46,8,6,'josepadmin','admin','inventory_pushed',NULL,NULL,'2026-05-31 14:15:49'),(47,8,6,'josepadmin','admin','supplier_paid',NULL,'Paid ₱900.00 (net of ₱100.00 damage)','2026-05-31 14:16:22'),(48,9,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-31 14:30:08'),(49,9,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-31 18:18:52'),(50,9,9,'JosepValidate','validator','validated_tally','match',NULL,'2026-05-31 18:19:33'),(51,9,9,'JosepValidate','validator','inventory_pushed',NULL,NULL,'2026-05-31 18:19:33'),(52,10,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-31 18:38:48'),(53,11,6,'josepadmin','admin','voucher_created',NULL,NULL,'2026-05-31 20:14:59'),(54,11,8,'JosepRec','receiver','items_encoded',NULL,NULL,'2026-05-31 20:37:37'),(55,11,9,'JosepValidate','validator','validated_tally','match',NULL,'2026-05-31 20:38:09'),(56,11,9,'JosepValidate','validator','inventory_pushed',NULL,NULL,'2026-05-31 20:38:09');
/*!40000 ALTER TABLE `procurement_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `procurement_payments`
--

DROP TABLE IF EXISTS `procurement_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `procurement_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `receipt_subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `damage_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('paid') NOT NULL DEFAULT 'paid',
  `verified_by` int(11) DEFAULT NULL,
  `verified_by_username` varchar(100) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `procurement_payments`
--

LOCK TABLES `procurement_payments` WRITE;
/*!40000 ALTER TABLE `procurement_payments` DISABLE KEYS */;
INSERT INTO `procurement_payments` VALUES (1,8,1000.00,100.00,900.00,NULL,'Cash',NULL,'paid',6,'josepadmin','2026-05-31 22:16:22','2026-05-31 14:16:22');
/*!40000 ALTER TABLE `procurement_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_disposals`
--

DROP TABLE IF EXISTS `product_disposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_disposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_disposals`
--

LOCK TABLES `product_disposals` WRITE;
/*!40000 ALTER TABLE `product_disposals` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_disposals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) DEFAULT NULL,
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
  `archived_at` datetime DEFAULT NULL,
  `receiving_batch_id` int(11) DEFAULT NULL,
  `draft_reason` enum('new','cost_change') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_product_supplier` (`supplier_id`),
  CONSTRAINT `fk_product_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_product_supplier_rel` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,NULL,'4800350108878',NULL,'Pocari Sweat 900ML',111.00,56,'VERIFIED',100.00,'General',100.00,0,0.00,0,0.00,'active',57,0,'2026-12-13','2026-06-01 17:37:02',11,NULL),(2,NULL,'123123123532',NULL,'Nescafe Classic',111.00,0,'VERIFIED',100.00,'General',100.00,0,0.00,0,0.00,'archived',17,0,'2027-08-16','2026-06-01 17:21:15',8,NULL),(3,NULL,'4801668606117',NULL,'Mang Tomas Siga Jr, 80G',102.00,0,'VERIFIED',100.00,'General',100.00,0,0.00,0,0.00,'archived',10,0,'2026-12-03','2026-06-01 17:17:33',9,NULL);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products_backup_clean`
--

DROP TABLE IF EXISTS `products_backup_clean`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products_backup_clean` (
  `id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products_backup_clean`
--

LOCK TABLES `products_backup_clean` WRITE;
/*!40000 ALTER TABLE `products_backup_clean` DISABLE KEYS */;
/*!40000 ALTER TABLE `products_backup_clean` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products_temp`
--

DROP TABLE IF EXISTS `products_temp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products_temp` (
  `id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `quantity` decimal(32,0) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products_temp`
--

LOCK TABLES `products_temp` WRITE;
/*!40000 ALTER TABLE `products_temp` DISABLE KEYS */;
/*!40000 ALTER TABLE `products_temp` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receiving_batches`
--

DROP TABLE IF EXISTS `receiving_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receiver_id` int(11) DEFAULT NULL,
  `receiver_username` varchar(100) DEFAULT NULL,
  `status` enum('pending_request','pending_validation','pending_inventory','validated_tally','validated_discrepancy','on_hold','completed','rejected','pending_reprice') DEFAULT 'pending_request',
  `supplier_name` varchar(255) DEFAULT NULL,
  `supplier_contact` varchar(255) DEFAULT NULL,
  `control_subtotal` decimal(12,2) DEFAULT NULL,
  `computed_subtotal` decimal(12,2) DEFAULT NULL,
  `tally_result` enum('match','discrepancy') DEFAULT NULL,
  `request_created_by` int(11) DEFAULT NULL,
  `request_created_at` datetime DEFAULT NULL,
  `validator_id` int(11) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `resolution_action` enum('reopen_receiver','reopen_validator','override','rejected','reopen_price_checker') DEFAULT NULL,
  `resolution_by` int(11) DEFAULT NULL,
  `resolution_reason` text DEFAULT NULL,
  `resolution_at` datetime DEFAULT NULL,
  `inventory_pushed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receiving_batches`
--

LOCK TABLES `receiving_batches` WRITE;
/*!40000 ALTER TABLE `receiving_batches` DISABLE KEYS */;
INSERT INTO `receiving_batches` VALUES (1,8,'JosepRec','completed','Josep Corp','09109491242',1000.00,1000.00,'match',6,'2026-05-30 04:03:32',9,'2026-05-30 04:05:38',NULL,NULL,NULL,NULL,'2026-05-30 04:05:38','2026-05-29 20:03:32'),(2,8,'JosepRec','validated_tally','PauPauPauPau','0918487134435',1000.00,1000.00,'match',6,'2026-05-31 01:43:38',9,'2026-05-31 01:44:53',NULL,NULL,NULL,NULL,NULL,'2026-05-30 17:43:38'),(3,8,'JosepRec','completed','PauPauPauPau','0918487134435',1000.00,1000.00,'match',6,'2026-05-31 02:08:06',9,'2026-05-31 02:08:54',NULL,NULL,NULL,NULL,'2026-05-31 02:08:54','2026-05-30 18:08:06'),(4,8,'JosepRec','completed','PauPauPauPau','0918487134435',1000.00,999.99,'match',6,'2026-05-31 02:09:46',10,'2026-05-31 03:50:42','reopen_price_checker',6,'Damage ticket rejected - reopened for repricing (migrated).','2026-05-31 03:40:49','2026-05-31 03:50:42','2026-05-30 18:09:46'),(5,8,'JosepRec','completed','Josep','098386823764',1000.00,999.99,'match',6,'2026-05-31 02:49:58',10,'2026-05-31 03:54:12','reopen_price_checker',6,'Damage ticket rejected.','2026-05-31 03:53:09','2026-05-31 03:54:12','2026-05-30 18:49:58'),(6,8,'JosepRec','completed','Inplay','0981364873',1000.00,999.99,'match',6,'2026-05-31 03:10:13',10,'2026-05-31 03:54:10','reopen_price_checker',6,'Damage ticket rejected.','2026-05-31 03:53:07','2026-05-31 03:54:10','2026-05-30 19:10:13'),(7,8,'JosepRec','completed','Inplay','0912738712',1000.00,999.99,'match',6,'2026-05-31 03:14:08',10,'2026-05-31 03:50:32','reopen_price_checker',6,'Damage ticket rejected - reopened for repricing (migrated).','2026-05-31 03:40:49','2026-05-31 03:50:32','2026-05-30 19:14:08'),(8,8,'JosepRec','completed','Discreapancy','091283812763',1000.00,900.00,'match',6,'2026-05-31 04:15:13',9,'2026-05-31 04:17:20','override',6,'go na yan be','2026-05-31 20:22:36','2026-05-31 22:15:49','2026-05-30 20:15:13'),(9,8,'JosepRec','completed','Josep Trading Co.','092198726187',1000.00,1000.00,'match',6,'2026-05-31 22:30:08',9,'2026-06-01 02:19:33',NULL,NULL,NULL,NULL,'2026-06-01 02:19:33','2026-05-31 14:30:08'),(10,NULL,NULL,'pending_request','Josep Trading Co.','091037673874',1000.00,NULL,NULL,6,'2026-06-01 02:38:48',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-31 18:38:48'),(11,8,'JosepRec','completed','Sample Voucher','091821387123',1000.00,1000.00,'match',6,'2026-06-01 04:14:59',9,'2026-06-01 04:38:09',NULL,NULL,NULL,NULL,'2026-06-01 04:38:09','2026-05-31 20:14:59');
/*!40000 ALTER TABLE `receiving_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receiving_items`
--

DROP TABLE IF EXISTS `receiving_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `expiry_date` date DEFAULT NULL,
  `base_price` decimal(10,2) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `match_flag` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `damaged_qty` int(11) DEFAULT 0,
  `damage_notes` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receiving_items`
--

LOCK TABLES `receiving_items` WRITE;
/*!40000 ALTER TABLE `receiving_items` DISABLE KEYS */;
INSERT INTO `receiving_items` VALUES (2,1,'4800350108878','Pocari Sweat 900ML',10,'2027-03-06',100.00,1000.00,1,'2026-05-29 20:04:54',0,NULL),(3,2,'4800350108878','Pocari Sweat 900ML',10,'2027-03-06',100.00,1000.00,1,'2026-05-30 17:44:21',0,NULL),(4,3,'4800350108878','Pocari Sweat 900ML',10,'2027-03-06',100.00,1000.00,1,'2026-05-30 18:08:30',0,NULL),(5,4,'4800350108878','Pocari Sweat 900ML',9,'2027-10-31',111.11,999.99,1,'2026-05-30 18:10:44',1,'Leakage'),(6,5,'4800350108878','Pocari Sweat 900ML',9,'2027-03-06',111.11,999.99,1,'2026-05-30 18:51:15',1,'Leakage'),(7,6,'4800350108878','Pocari Sweat 900ML',9,'2027-03-06',111.11,999.99,1,'2026-05-30 19:10:50',1,'Leakage'),(8,7,'4800350108878','Pocari Sweat 900ML',9,'2027-03-06',111.11,999.99,1,'2026-05-30 19:14:50',1,'Leakage'),(10,8,'123123123532','Nescafe Classic',9,'2027-08-16',100.00,900.00,1,'2026-05-30 20:16:20',1,'Damaged'),(11,9,'4801668606117','Mang Tomas Siga Jr, 80G',10,'2026-12-03',100.00,1000.00,1,'2026-05-31 18:18:52',0,NULL),(12,11,'4800350108878','Pocari Sweat 900ML',10,'2026-12-13',100.00,1000.00,1,'2026-05-31 20:37:37',0,NULL);
/*!40000 ALTER TABLE `receiving_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refunds`
--

DROP TABLE IF EXISTS `refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refunds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `reject_note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `refunds_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `refunds_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refunds`
--

LOCK TABLES `refunds` WRITE;
/*!40000 ALTER TABLE `refunds` DISABLE KEYS */;
INSERT INTO `refunds` VALUES (1,3,1,1,'restock',124.32,'Customer Return','pending',8,NULL,NULL,NULL,'2026-06-01 09:27:07',NULL);
/*!40000 ALTER TABLE `refunds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `customer_group_id` int(11) DEFAULT NULL,
  `group_discount_amt` decimal(10,2) DEFAULT 0.00,
  `bundle_discount_amt` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` VALUES (1,'RCPT-20260529-CC5B',NULL,1008.00,1010.00,2.00,'2026-05-30 04:23:36','2026-05-29 20:23:36','Cash',NULL,'None',0.00,0,0.00,0.00),(2,'RCPT-20260529-6174',NULL,112.00,112.00,0.00,'2026-05-30 04:24:12','2026-05-29 20:24:12','Cash',NULL,'None',0.00,0,0.00,0.00),(3,'RCPT-20260601-5A89',NULL,124.32,124.99,0.67,'2026-06-01 17:21:35','2026-06-01 09:21:35','Cash',NULL,'None',0.00,0,0.00,0.00);
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales_items`
--

DROP TABLE IF EXISTS `sales_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales_items`
--

LOCK TABLES `sales_items` WRITE;
/*!40000 ALTER TABLE `sales_items` DISABLE KEYS */;
INSERT INTO `sales_items` VALUES (1,1,1,9,100.00),(2,2,1,1,100.00),(3,3,1,1,111.00);
/*!40000 ALTER TABLE `sales_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `security_flags`
--

DROP TABLE IF EXISTS `security_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `flag_type` varchar(50) DEFAULT NULL,
  `severity` enum('low','medium','high') DEFAULT 'medium',
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `status` enum('open','reviewed','dismissed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `security_flags`
--

LOCK TABLES `security_flags` WRITE;
/*!40000 ALTER TABLE `security_flags` DISABLE KEYS */;
/*!40000 ALTER TABLE `security_flags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) DEFAULT NULL,
  `setting_value` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_delete_requests`
--

DROP TABLE IF EXISTS `stock_delete_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_delete_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('PENDING','APPROVED','REJECTED') DEFAULT 'PENDING',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_delete_requests_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_delete_requests`
--

LOCK TABLES `stock_delete_requests` WRITE;
/*!40000 ALTER TABLE `stock_delete_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_delete_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_invoice_id` varchar(50) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `supplier_code` varchar(50) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `contact` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_invoice_id` (`supplier_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_messages`
--

DROP TABLE IF EXISTS `support_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_username` varchar(100) DEFAULT NULL,
  `sender_role` varchar(50) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_messages`
--

LOCK TABLES `support_messages` WRITE;
/*!40000 ALTER TABLE `support_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_tickets`
--

DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('open','in_progress','resolved') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_tickets`
--

LOCK TABLES `support_tickets` WRITE;
/*!40000 ALTER TABLE `support_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) DEFAULT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'damage_ticket_expiry_days','3'),(2,'low_stock_threshold','10'),(3,'tax_display_mode','exclusive'),(4,'price_rounding_rule','none'),(9,'ip_restriction_enabled','0'),(11,'backup_last_run','2026-05-31 18:20:16');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('superadmin','admin','staff','owner','member','receiver','validator','price_checker') DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE','TERMINATED') DEFAULT 'ACTIVE',
  `terminated_at` datetime DEFAULT NULL,
  `termination_reason` varchar(255) DEFAULT NULL,
  `reset_requested` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (5,'superadmin@josep',NULL,NULL,'$2y$10$u652Bf0H1QJKrA6b8ey/IulBLdgsirKqmu7vWnYmv3a4HBthlkhrO','superadmin','ACTIVE',NULL,NULL,1),(6,'josepadmin','Joseph Christian Polido','09109492142','$2y$10$42YJgOG0SxhsxxSeGtG2W.Ch7fUTbirjC67SADHyyBQmI3i1CEbia','admin','ACTIVE',NULL,NULL,0),(7,'josepstafff','Joseph Christian Polido','09109492142','$2y$10$dAR2NXoN4KdrtoBvJAwgY.QLMNLAdj7zsgLJJQu1QaQZ8/uCANnlu','staff','ACTIVE',NULL,NULL,0),(8,'JosepRec','Josep Christian Polido','092684763736','$2y$10$UuUrkwhHch3RRAeB3svlMOiOYB7mjHZrS53meJi8G1uEXlofwSw9e','receiver','ACTIVE',NULL,NULL,0),(9,'JosepValidate','Josep Christian Polido','09478292746','$2y$10$QECNkYxPZPlDOMoQsIGuT.r6Qic/xzY3WjKy/x/tcMo4HH.R1pB66','validator','ACTIVE',NULL,NULL,0),(10,'JosepPriceCheck','Josep Christian Polido','09478292746','$2y$10$jHTv7Mcg7vnpIRj/KCN1Iury8/MFwDk5TJb9iKZKip4hpohO/2J7W','price_checker','ACTIVE',NULL,NULL,0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'posiisdb'
--

--
-- Dumping routines for database 'posiisdb'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-01 19:08:43
