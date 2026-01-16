-- Fire Department Website Database Schema
-- Create this database structure in your IONOS MariaDB

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Users table with role-based access
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Admin users automatically have all permissions',
  `first_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Landing page content (editable by board members)
CREATE TABLE IF NOT EXISTS `page_content` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_key` varchar(50) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `content` text,
  `image_url` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `page_content_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operations/Einsätze
CREATE TABLE IF NOT EXISTS `operations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text,
  `operation_date` datetime NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `operation_type` varchar(100) DEFAULT NULL,
  `published` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operation_date` (`operation_date`),
  KEY `published` (`published`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `operations_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operation images
CREATE TABLE IF NOT EXISTS `operation_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation_id` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `operation_images_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events
CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text,
  `event_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `status` enum('upcoming','past') NOT NULL DEFAULT 'upcoming',
  `published` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_date` (`event_date`),
  KEY `status` (`status`),
  KEY `published` (`published`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event images
CREATE TABLE IF NOT EXISTS `event_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `event_images_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contact messages
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('new','read','archived') NOT NULL DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Social media links
CREATE TABLE IF NOT EXISTS `social_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` varchar(50) NOT NULL,
  `url` varchar(255) NOT NULL,
  `icon_class` varchar(50) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction categories (maintainable) - Must be before transactions table
CREATE TABLE IF NOT EXISTS `transaction_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `color` varchar(7) NOT NULL DEFAULT '#1976d2',
  `icon` varchar(50) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Financial transactions
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_date` date NOT NULL,
  `booking_text` varchar(200) DEFAULT NULL,
  `purpose` text,
  `payer` varchar(100) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `comment` text,
  `document_id` int(11) DEFAULT NULL,
  `business_year` int(4) DEFAULT NULL COMMENT 'Fiscal year, automatically set from booking_date on import',
  `check_status` enum('unchecked','checked','under_investigation') NOT NULL DEFAULT 'unchecked' COMMENT 'Status of accounting check',
  `checked_in_period_id` int(11) DEFAULT NULL COMMENT 'Check period in which this was finalized (locks the transaction)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `booking_date` (`booking_date`),
  KEY `business_year` (`business_year`),
  KEY `category_id` (`category_id`),
  KEY `created_by` (`created_by`),
  KEY `check_status` (`check_status`),
  KEY `checked_in_period_id` (`checked_in_period_id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `transaction_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction documents (PDFs)
CREATE TABLE IF NOT EXISTS `transaction_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `transaction_documents_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kassenprüfer assignments (auditors checking the accounting)
CREATE TABLE IF NOT EXISTS `kassenpruefer_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL COMMENT 'Active member assigned as Kassenprüfer',
  `role_type` enum('leader','assistant') NOT NULL COMMENT 'leader=Leiter (experienced), assistant=Assistent (new)',
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL COMMENT 'NULL means currently active',
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `role_type` (`role_type`),
  KEY `valid_from` (`valid_from`),
  KEY `valid_until` (`valid_until`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `kassenpruefer_assignments_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `kassenpruefer_assignments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Check periods (batches of transactions to be checked together)
CREATE TABLE IF NOT EXISTS `check_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_name` varchar(100) NOT NULL COMMENT 'e.g., "2025 Annual Check" or "Q1 2026"',
  `business_year` int(4) NOT NULL COMMENT 'Fiscal year being checked',
  `date_from` date NOT NULL COMMENT 'Start date of transactions included',
  `date_to` date NOT NULL COMMENT 'End date of transactions included',
  `status` enum('in_progress','finalized') NOT NULL DEFAULT 'in_progress',
  `leader_id` int(11) NOT NULL COMMENT 'Kassenprüfer leader for this check',
  `assistant_id` int(11) NOT NULL COMMENT 'Kassenprüfer assistant for this check',
  `finalized_at` timestamp NULL DEFAULT NULL COMMENT 'When the leader finalized this check period',
  `finalized_by` int(11) DEFAULT NULL COMMENT 'User who finalized (should be leader)',
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `period_name` (`period_name`),
  KEY `business_year` (`business_year`),
  KEY `status` (`status`),
  KEY `leader_id` (`leader_id`),
  KEY `assistant_id` (`assistant_id`),
  KEY `finalized_by` (`finalized_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `check_periods_ibfk_1` FOREIGN KEY (`leader_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `check_periods_ibfk_2` FOREIGN KEY (`assistant_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `check_periods_ibfk_3` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `check_periods_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction checks (individual check records for each transaction)
CREATE TABLE IF NOT EXISTS `transaction_checks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `check_period_id` int(11) NOT NULL,
  `checked_by_member_id` int(11) NOT NULL COMMENT 'Which Kassenprüfer reviewed this',
  `check_date` date NOT NULL,
  `check_result` enum('approved','under_investigation') NOT NULL,
  `remarks` text COMMENT 'Comments/issues found during check',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `check_period_id` (`check_period_id`),
  KEY `checked_by_member_id` (`checked_by_member_id`),
  KEY `check_result` (`check_result`),
  KEY `check_date` (`check_date`),
  CONSTRAINT `transaction_checks_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_checks_ibfk_2` FOREIGN KEY (`check_period_id`) REFERENCES `check_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_checks_ibfk_3` FOREIGN KEY (`checked_by_member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Members (both active members and supporters)
CREATE TABLE IF NOT EXISTS `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_number` varchar(20) DEFAULT NULL,
  `member_type` enum('active','supporter','pensioner') NOT NULL COMMENT 'active=Einsatzeinheit, supporter=Förderer, pensioner=Altersabteilung',
  `salutation` varchar(20) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `street` varchar(200) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL COMMENT 'Date when member left/became inactive',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `board_position` varchar(100) DEFAULT NULL COMMENT 'Position on the board/command',
  `board_image_url` varchar(255) DEFAULT NULL COMMENT 'Photo for board display',
  `board_sort_order` int(11) DEFAULT 0 COMMENT 'Sort order for board display',
  `is_board_member` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether member is displayed on board/command page',
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_number` (`member_number`),
  KEY `member_type` (`member_type`),
  KEY `active` (`active`),
  KEY `is_board_member` (`is_board_member`),
  KEY `last_name` (`last_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership fees (with validity periods)
CREATE TABLE IF NOT EXISTS `membership_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_type` enum('active','supporter','pensioner') NOT NULL,
  `minimum_amount` decimal(10,2) NOT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date DEFAULT NULL COMMENT 'NULL means currently valid',
  `description` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_type` (`member_type`),
  KEY `valid_from` (`valid_from`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `membership_fees_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Member fee obligations (open positions per member per year)
CREATE TABLE IF NOT EXISTS `member_fee_obligations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `fee_year` int(4) NOT NULL COMMENT 'Year this fee is for',
  `fee_amount` decimal(10,2) NOT NULL COMMENT 'Required amount based on membership_fees',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount paid so far',
  `status` enum('open','partial','paid','cancelled') NOT NULL DEFAULT 'open' COMMENT 'Payment status',
  `due_date` date DEFAULT NULL,
  `generated_date` date NOT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_year` (`member_id`, `fee_year`),
  KEY `fee_year` (`fee_year`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `member_fee_obligations_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_fee_obligations_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Member payments (reduces obligations)
CREATE TABLE IF NOT EXISTS `member_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obligation_id` int(11) NOT NULL COMMENT 'Fee obligation this payment reduces',
  `transaction_id` int(11) DEFAULT NULL COMMENT 'Optional link to transaction from cash management',
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `obligation_id` (`obligation_id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `payment_date` (`payment_date`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `member_payments_ibfk_1` FOREIGN KEY (`obligation_id`) REFERENCES `member_fee_obligations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_payments_ibfk_2` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `member_payments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: admin123 - CHANGE THIS!)
INSERT INTO `users` (`username`, `email`, `password`, `is_admin`, `first_name`, `last_name`) VALUES
('admin', 'admin@example.com', '$2y$10$Q508QQp7rNEJLQXGlnmC2.uzCUmjeVW6PNNANDRzHmzl/9Okger9y', 1, 'Admin', 'User');

-- Insert default page content
INSERT INTO `page_content` (`section_key`, `title`, `content`) VALUES
('hero_welcome', 'Willkommen bei der Freiwilligen Feuerwehr', 'Wir sind rund um die Uhr für Sie da.'),
('about', 'Über uns', 'Hier steht Text über die Feuerwehr...'),
('contact_info', 'Kontakt', 'Notruf: 112\nFeuerwehr: [Ihre Nummer]');

-- Insert default social media
INSERT INTO `social_media` (`platform`, `url`, `icon_class`, `sort_order`) VALUES
('Instagram', 'https://instagram.com/your_handle', 'fab fa-instagram', 1),
('TikTok', 'https://tiktok.com/@your_handle', 'fab fa-tiktok', 2),
('Facebook', 'https://facebook.com/your_page', 'fab fa-facebook', 3);

-- Insert default transaction categories
INSERT INTO `transaction_categories` (`name`, `description`, `color`, `icon`, `sort_order`) VALUES
('Fixkosten', 'Regelmäßige Betriebskosten', '#4caf50', 'fas fa-home', 1),
('Beitrag Einsatzeinheit', 'Mitgliedsbeiträge von Einsatzeinheiten', '#2196f3', 'fas fa-users', 2),
('Beitrag Förderer', 'Zuschüsse und Spenden', '#ff9800', 'fas fa-heart', 3),
('Mieteinnahme', 'Einnahmen aus Vermietungen', '#9c27b0', 'fas fa-building', 4),
('Verpflegung', 'Kosten für Verpflegung', '#f44336', 'fas fa-utensils', 5),
('Event mit Gewinnerwartung', 'Veranstaltungen mit Gewinnziel', '#ffc107', 'fas fa-gift', 6),
('Event ohne Gewinnerwartung', 'Kostenlose oder verlustmachende Events', '#9e9e9e', 'fas fa-calendar', 7),
('Anschaffung', 'Kauf von Ausrüstung und Material', '#e91e63', 'fas fa-shopping-cart', 8),
('Instandhaltung', 'Reparatur und Wartung', '#607d8b', 'fas fa-tools', 9);

-- Insert default membership fees
INSERT INTO `membership_fees` (`member_type`, `minimum_amount`, `valid_from`, `valid_until`, `description`) VALUES
('active', 10.00, '2002-01-01', '2024-12-31', 'Jahresbeitrag Einsatzeinheit'),
('active', 20.00, '2025-01-01', null, 'Jahresbeitrag Einsatzeinheit'),
('supporter', 30.00, '2002-01-01', null, 'Jahresbeitrag Förderer');

-- Insert initial balance / opening balance
INSERT INTO `transactions` (`booking_date`, `booking_text`, `amount`, `business_year`, `comment`) VALUES
('2023-12-31', 'Startsaldo / Anfangsbestand', 9903.38, 2023, 'Anfangsbestand zum Start der Buchführung');

-- Items (equipment/goods that can be lent or rented)
CREATE TABLE IF NOT EXISTS `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `remark` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Price per usage/rental',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obligations for items (lending/rental charges)
CREATE TABLE IF NOT EXISTS `item_obligations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL COMMENT 'Receiver if they are a member, NULL for non-member receivers',
  `receiver_name` varchar(100) DEFAULT NULL COMMENT 'Name of receiver (required if member_id is NULL)',
  `receiver_phone` varchar(20) DEFAULT NULL,
  `receiver_email` varchar(100) DEFAULT NULL,
  `organizing_member_id` int(11) DEFAULT NULL COMMENT 'Member who organized/brokered the deal',
  `total_amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('open','paid','cancelled') NOT NULL DEFAULT 'open',
  `notes` text,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `organizing_member_id` (`organizing_member_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `item_obligations_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `item_obligations_ibfk_2` FOREIGN KEY (`organizing_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `item_obligations_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Line items within an obligation (which items are included and quantities)
CREATE TABLE IF NOT EXISTS `obligation_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `obligation_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL COMMENT 'Price per unit at time of obligation creation',
  `subtotal` decimal(10,2) NOT NULL COMMENT 'quantity * unit_price',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `obligation_id` (`obligation_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `obligation_items_ibfk_1` FOREIGN KEY (`obligation_id`) REFERENCES `item_obligations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `obligation_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
