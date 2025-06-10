SET FOREIGN_KEY_CHECKS = 0;

-- Users Table: Stores login information and roles for CRM users.
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `full_name` VARCHAR(100),
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Organisations Table: Stores company/organisation information.
CREATE TABLE IF NOT EXISTS `organisations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255),
    `city` VARCHAR(100),
    `state_province` VARCHAR(100),
    `postal_code` VARCHAR(20),
    `country` VARCHAR(100),
    `phone_number` VARCHAR(50),
    `website` VARCHAR(255),
    `industry` VARCHAR(100),
    `description` TEXT,
    `created_by_user_id` INT,
    `assigned_to_user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contacts Table: Stores individual contact information, linked to organisations.
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `organisation_id` INT NULL, -- A contact might not be initially linked to an organisation
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) UNIQUE, -- Email should be unique for contacts if it's a primary identifier
    `phone_number` VARCHAR(50),
    `mobile_number` VARCHAR(50),
    `job_title` VARCHAR(100),
    `department` VARCHAR(100),
    `linkedin_profile_url` VARCHAR(255),
    `address` VARCHAR(255),
    `city` VARCHAR(100),
    `state_province` VARCHAR(100),
    `postal_code` VARCHAR(20),
    `country` VARCHAR(100),
    `description` TEXT,
    `created_by_user_id` INT,
    `assigned_to_user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leads Table: Stores potential sales opportunities.
CREATE TABLE IF NOT EXISTS `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `contact_id` INT NULL, -- Lead might originate from a new contact not yet in DB
    `organisation_id` INT NULL, -- Lead might be associated with an organisation
    `lead_source` VARCHAR(100), -- e.g., 'Website', 'Referral', 'Cold Call'
    `lead_status` VARCHAR(50) DEFAULT 'New', -- e.g., 'New', 'Contacted', 'Qualified', 'Lost', 'Converted'
    `lead_value` DECIMAL(10, 2) DEFAULT 0.00,
    `description` TEXT,
    `created_by_user_id` INT,
    `assigned_to_user_id` INT,
    `expected_close_date` DATE NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deals/Opportunities Table: Tracks qualified leads that have become sales opportunities.
CREATE TABLE IF NOT EXISTS `deals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NULL UNIQUE, -- Link to the original lead, if applicable. Can be NULL if deal created directly.
    `contact_id` INT NOT NULL, -- A deal must have a primary contact
    `organisation_id` INT NULL, -- And likely an organisation
    `deal_name` VARCHAR(255) NOT NULL,
    `deal_stage` VARCHAR(50) NOT NULL DEFAULT 'Qualification', -- e.g., 'Qualification', 'Proposal', 'Negotiation', 'Closed Won', 'Closed Lost'
    `deal_value` DECIMAL(10, 2) NOT NULL,
    `currency` VARCHAR(3) DEFAULT 'USD',
    `expected_close_date` DATE,
    `actual_close_date` DATE NULL,
    `description` TEXT,
    `created_by_user_id` INT,
    `assigned_to_user_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE, -- If contact is deleted, deal might be invalid
    FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT -- Don't delete user if they have deals
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lead Status History Table: Tracks changes in lead status over time.
CREATE TABLE IF NOT EXISTS `lead_status_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lead_id` INT NOT NULL,
    `old_status` VARCHAR(50),
    `new_status` VARCHAR(50) NOT NULL,
    `changed_by_user_id` INT,
    `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activities Table: Tracks interactions like calls, emails, meetings.
CREATE TABLE IF NOT EXISTS `activities` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `related_to_type` ENUM('lead', 'deal', 'contact', 'organisation') NOT NULL,
    `related_to_id` INT NOT NULL, -- ID of the lead, deal, contact, or organisation
    `activity_type` VARCHAR(50) NOT NULL, -- e.g., 'Call', 'Email', 'Meeting', 'Task'
    `subject` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `due_date` DATE NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Pending', -- e.g., 'Pending', 'Completed', 'Overdue'
    `created_by_user_id` INT,
    `assigned_to_user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    -- Note: No direct foreign key for related_to_id to enforce referential integrity across multiple tables here.
    -- This would typically be handled at the application layer or with triggers if strict DB enforcement is needed.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
