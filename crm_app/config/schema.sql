SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) UNIQUE,
  `first_name` VARCHAR(50),
  `last_name` VARCHAR(50),
  `role` ENUM('sales', 'consultant', 'support', 'manager', 'viewer') NOT NULL DEFAULT 'viewer',
  `is_admin` BOOLEAN NOT NULL DEFAULT FALSE,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `organisations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `website` VARCHAR(255),
  `phone` VARCHAR(50),
  `address_street` VARCHAR(255),
  `address_city` VARCHAR(100),
  `address_state` VARCHAR(100),
  `address_zip` VARCHAR(20),
  `address_country` VARCHAR(100),
  `description` TEXT,
  `industry` VARCHAR(100),
  `annual_revenue` DECIMAL(15,2),
  `created_by_user_id` INT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` INT NOT NULL DEFAULT 1,
  CONSTRAINT `fk_organisations_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) UNIQUE,
  `phone_mobile` VARCHAR(50),
  `phone_work` VARCHAR(50),
  `title` VARCHAR(100),
  `organisation_id` INT,
  `created_by_user_id` INT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` INT NOT NULL DEFAULT 1,
  CONSTRAINT `fk_contacts_organisation` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_contacts_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `stage` ENUM('Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'Won', 'Lost') NOT NULL DEFAULT 'Prospecting',
  `amount` DECIMAL(15,2) NOT NULL,
  `close_date` DATE,
  `probability` DECIMAL(5,2),
  `description` TEXT,
  `organisation_id` INT NOT NULL,
  `contact_id` INT,
  `assigned_user_id` INT,
  `created_by_user_id` INT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` INT NOT NULL DEFAULT 1,
  CONSTRAINT `fk_deals_organisation` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_deals_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_deals_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_deals_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `source` VARCHAR(100),
  `status` ENUM('New', 'Contacted', 'Qualified', 'Unqualified', 'Converted') NOT NULL DEFAULT 'New',
  `temperature` ENUM('Cold', 'Warm', 'Hot') NOT NULL DEFAULT 'Cold',
  `description` TEXT,
  `value` DECIMAL(15,2),
  `expected_close_date` DATE,
  `contact_id` INT,
  `organisation_id` INT,
  `assigned_user_id` INT,
  `created_by_user_id` INT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `converted_to_deal_id` INT UNIQUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` INT NOT NULL DEFAULT 1,
  CONSTRAINT `fk_leads_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_leads_organisation` FOREIGN KEY (`organisation_id`) REFERENCES `organisations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_leads_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_leads_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_leads_converted_deal` FOREIGN KEY (`converted_to_deal_id`) REFERENCES `deals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_status_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id` INT NOT NULL,
  `old_status` ENUM('New', 'Contacted', 'Qualified', 'Unqualified', 'Converted'),
  `new_status` ENUM('New', 'Contacted', 'Qualified', 'Unqualified', 'Converted') NOT NULL,
  `old_temperature` ENUM('Cold', 'Warm', 'Hot'),
  `new_temperature` ENUM('Cold', 'Warm', 'Hot'),
  `changed_by_user_id` INT NOT NULL,
  `change_timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_lsh_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lsh_changed_by` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activities` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type` ENUM('Call', 'Meeting', 'Email', 'Task', 'Other') NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `due_date` DATETIME,
  `status` ENUM('Pending', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
  `notes_content` TEXT,
  `files_json` JSON,
  `related_to_type` ENUM('Lead', 'Deal', 'Organisation', 'Contact', 'User') NOT NULL,
  `related_to_id` INT NOT NULL,
  `assigned_to_user_id` INT NOT NULL,
  `created_by_user_id` INT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `version` INT NOT NULL DEFAULT 1,
  CONSTRAINT `fk_activities_assigned_to` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_activities_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
