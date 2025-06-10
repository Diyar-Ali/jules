-- Test Data for Basic Consulting CRM

SET FOREIGN_KEY_CHECKS=0;

-- Users
-- Note: For 'admin_user', password_hash is for 'password123'
-- For 'standard_user', password_hash is for 'password123' (use the same hash for simplicity in this test data)
INSERT INTO `users` (`id`, `username`, `password_hash`, `email`, `first_name`, `last_name`, `role`, `is_admin`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'admin_user', '$2y$10$N.gekSYYSMXQpFL3uXyQFeuFqISzMilmGOp3jL4lmua299.PhyM.K', 'admin@example.com', 'Admin', 'User', 'manager', TRUE, TRUE, NOW(), NOW(), 1),
(2, 'standard_user', '$2y$10$N.gekSYYSMXQpFL3uXyQFeuFqISzMilmGOp3jL4lmua299.PhyM.K', 'standard@example.com', 'Standard', 'User', 'consultant', FALSE, TRUE, NOW(), NOW(), 1),
(3, 'sales_user', '$2y$10$N.gekSYYSMXQpFL3uXyQFeuFqISzMilmGOp3jL4lmua299.PhyM.K', 'sales@example.com', 'Sales', 'Person', 'sales', FALSE, TRUE, NOW(), NOW(), 1);

-- Organisations
INSERT INTO `organisations` (`id`, `name`, `website`, `phone`, `address_street`, `address_city`, `address_state`, `address_zip`, `address_country`, `description`, `industry`, `annual_revenue`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Innovatech Solutions', 'https://innovatech.example.com', '555-0101', '123 Tech Park', 'Metropolis', 'CA', '90210', 'USA', 'Leading provider of innovative tech solutions.', 'Technology', 5000000.00, 1, TRUE, NOW(), NOW(), 1),
(2, 'GreenLeaf Consulting', 'https://greenleaf.example.com', '555-0102', '456 Eco Avenue', 'Verdant City', 'FL', '33101', 'USA', 'Environmental consulting and sustainability services.', 'Consulting', 1200000.00, 1, TRUE, NOW(), NOW(), 1),
(3, 'Alpha Corp', 'https://alphacorp.example.com', '555-0103', '789 Business Rd', 'New York', 'NY', '10001', 'USA', 'Global enterprise solutions.', 'Manufacturing', 25000000.00, 2, TRUE, NOW(), NOW(), 1),
(4, 'Inactive Org Ltd', 'https://inactive.example.com', '555-0104', '10 Old Street', 'Ghost Town', 'NV', '89049', 'USA', 'This organisation is no longer active.', 'Defunct', 0.00, 1, FALSE, NOW(), NOW(), 1);

-- Contacts
INSERT INTO `contacts` (`id`, `first_name`, `last_name`, `email`, `phone_mobile`, `phone_work`, `title`, `organisation_id`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Alice', 'Wonder', 'alice.wonder@innovatech.example.com', '555-0201', '555-0101 ext 101', 'CEO', 1, 1, TRUE, NOW(), NOW(), 1),
(2, 'Bob', 'Builder', 'bob.builder@greenleaf.example.com', '555-0202', '555-0102 ext 202', 'Project Manager', 2, 1, TRUE, NOW(), NOW(), 1),
(3, 'Charlie', 'Brown', 'charlie.brown@alphacorp.example.com', '555-0203', '555-0103 ext 303', 'Lead Engineer', 3, 2, TRUE, NOW(), NOW(), 1),
(4, 'Diana', 'Prince', 'diana.prince@example.com', '555-0204', NULL, 'Consultant', NULL, 1, TRUE, NOW(), NOW(), 1), -- No organisation
(5, 'Edward', 'Scissorhands', 'edward.s@inactive.example.com', '555-0205', NULL, 'Special Projects', 4, 1, FALSE, NOW(), NOW(), 1); -- Inactive contact

-- Deals
-- Deals must have an organisation_id
INSERT INTO `deals` (`id`, `name`, `stage`, `amount`, `close_date`, `probability`, `description`, `organisation_id`, `contact_id`, `assigned_user_id`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Innovatech Project Alpha', 'Proposal', 150000.00, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 0.75, 'Proposal for phase 1 of Project Alpha.', 1, 1, 2, 1, TRUE, NOW(), NOW(), 1),
(2, 'GreenLeaf Sustainability Audit', 'Qualification', 75000.00, DATE_ADD(CURDATE(), INTERVAL 60 DAY), 0.50, 'Initial qualification for full sustainability audit.', 2, 2, 1, 1, TRUE, NOW(), NOW(), 1),
(3, 'Alpha Corp System Upgrade', 'Won', 500000.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 1.00, 'Successfully closed deal for system upgrade.', 3, 3, 2, 2, TRUE, NOW(), NOW(), 1),
(4, 'Innovatech Project Beta (Lost)', 'Lost', 200000.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 0.00, 'Lost to competitor.', 1, 1, 1, 1, TRUE, NOW(), NOW(), 1);


-- Leads
-- Note: Lead ID 3 will be converted to Deal ID 1 for testing converted_to_deal_id link
INSERT INTO `leads` (`id`, `name`, `source`, `status`, `temperature`, `description`, `value`, `expected_close_date`, `contact_id`, `organisation_id`, `assigned_user_id`, `created_by_user_id`, `is_active`, `converted_to_deal_id`, `created_at`, `updated_at`, `version`) VALUES
(1, 'New Inquiry - Innovatech Website', 'Website Form', 'New', 'Warm', 'Inquiry about cloud services from website.', 25000.00, DATE_ADD(CURDATE(), INTERVAL 14 DAY), NULL, 1, 2, 1, TRUE, NULL, NOW(), NOW(), 1),
(2, 'Referral - GreenLeaf Expansion', 'Referral', 'Contacted', 'Hot', 'Bob Builder referred a new contact for their expansion project.', 50000.00, DATE_ADD(CURDATE(), INTERVAL 20 DAY), 2, 2, 1, 1, TRUE, NULL, NOW(), NOW(), 1),
(3, 'Old Lead for Conversion Test (Innovatech)', 'Trade Show', 'Qualified', 'Warm', 'Met at trade show, interested in Project Alpha type services.', 150000.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 1, 1, 2, 1, TRUE, 1, DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 2), -- This lead is converted to Deal 1
(4, 'Cold Call - Alpha Corp Subsidiary', 'Cold Call', 'New', 'Cold', 'Initial cold call, low interest.', 10000.00, DATE_ADD(CURDATE(), INTERVAL 90 DAY), NULL, 3, 1, 2, TRUE, NULL, NOW(), NOW(), 1),
(5, 'Unqualified Lead - Spam Form', 'Website Form', 'Unqualified', 'Cold', 'Spam submission.', 0.00, NULL, NULL, NULL, 2, 1, FALSE, NULL, NOW(), NOW(), 1); -- Inactive and Unqualified

-- Lead Status History
-- For Lead ID 1: New -> Contacted
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(1, NULL, 'New', NULL, 'Warm', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1, 'New', 'Contacted', 'Warm', 'Hot', 2, NOW());
-- For Lead ID 2: New -> Contacted
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(2, NULL, 'New', NULL, 'Warm', 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(2, 'New', 'Contacted', 'Warm', 'Hot', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR));
-- For Lead ID 3 (Converted Lead): New -> Qualified -> Converted
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(3, NULL, 'New', NULL, 'Cold', 1, DATE_SUB(NOW(), INTERVAL 12 DAY)),
(3, 'New', 'Qualified', 'Cold', 'Warm', 2, DATE_SUB(NOW(), INTERVAL 11 DAY)),
(3, 'Qualified', 'Converted', 'Warm', 'Warm', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)); -- matches lead update_at

-- Activities
-- files_json: [{"name": "doc1.pdf", "path": "activity_files/mock_doc1.pdf", "size": 10240, "type": "application/pdf"}]
INSERT INTO `activities` (`id`, `type`, `subject`, `description`, `due_date`, `status`, `notes_content`, `files_json`, `related_to_type`, `related_to_id`, `assigned_to_user_id`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Call', 'Follow up with Alice (Innovatech)', 'Discuss Project Alpha proposal details.', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Pending', 'Called, left voicemail. Will try again tomorrow.', NULL, 'Contact', 1, 2, 1, TRUE, NOW(), NOW(), 1),
(2, 'Meeting', 'Project Kickoff - GreenLeaf Audit', 'Internal kickoff meeting for GreenLeaf sustainability audit.', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Pending', '<p><strong>Agenda:</strong></p><ul><li>Introductions</li><li>Scope Review</li><li>Timeline</li></ul>', '[{"name": "agenda.docx", "path": "activity_files/mock_agenda.docx", "size": 20480, "type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document"}]', 'Deal', 2, 1, 1, TRUE, NOW(), NOW(), 1),
(3, 'Email', 'Send Brochure to Alpha Corp Lead', 'Send standard services brochure.', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Completed', 'Brochure sent. Follow up next week.', NULL, 'Lead', 4, 2, 2, TRUE, NOW(), NOW(), 1),
(4, 'Task', 'Prepare Q4 Sales Report', 'Compile sales figures for Q4.', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Pending', 'Need data from all sales team members.', NULL, 'User', 1, 1, 1, TRUE, NOW(), NOW(), 1), -- Related to admin_user
(5, 'Other', 'Site Visit - Innovatech', 'On-site visit to Innovatech for project assessment.', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Pending', 'Confirm travel arrangements.', '[{"name": "travel_plan.pdf", "path": "activity_files/mock_travel.pdf", "size": 51200, "type": "application/pdf"}, {"name": "site_map.jpg", "path": "activity_files/mock_sitemap.jpg", "size": 102400, "type": "image/jpeg"}]', 'Organisation', 1, 2, 1, TRUE, NOW(), NOW(), 1);


SET FOREIGN_KEY_CHECKS=1;
