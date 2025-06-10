SET FOREIGN_KEY_CHECKS = 0;

-- Please replace placeholder password hashes below with actual generated hashes.
-- Example PHP to generate a hash: echo password_hash('adminpass', PASSWORD_DEFAULT);

-- =============================================================================
-- Users
-- Passwords: 'adminpass', 'salespass', 'consultpass'
-- =============================================================================
INSERT INTO `users` (`id`, `username`, `password_hash`, `email`, `first_name`, `last_name`, `role`, `is_admin`, `is_active`, `last_login_at`, `created_at`, `updated_at`, `version`) VALUES
(1, 'admin', '$2y$10$DO.A.n2S4S20sN6BgxX6xO0y8g0y8g0y8g0y8g0y8g0y8g0y8g0yA', 'admin@connectcrm.com', 'Admin', 'User', 'manager', TRUE, TRUE, NULL, NOW(), NOW(), 1),
(2, 'salesuser', '$2y$10$DO.A.n2S4S20sN6BgxX6xO0y8g0y8g0y8g0y8g0y8g0y8g0y8g0yB', 'sales@connectcrm.com', 'Sales', 'Person', 'sales', FALSE, TRUE, NULL, NOW(), NOW(), 1),
(3, 'consultantuser', '$2y$10$DO.A.n2S4S20sN6BgxX6xO0y8g0y8g0y8g0y8g0y8g0y8g0y8g0yC', 'consultant@connectcrm.com', 'Consultant', 'Expert', 'consultant', FALSE, TRUE, NULL, NOW(), NOW(), 1),
(4, 'inactiveuser', '$2y$10$DO.A.n2S4S20sN6BgxX6xO0y8g0y8g0y8g0y8g0y8g0y8g0y8g0yD', 'inactive@connectcrm.com', 'Inactive', 'User', 'viewer', FALSE, FALSE, NULL, NOW(), NOW(), 1);

-- =============================================================================
-- Organisations
-- =============================================================================
INSERT INTO `organisations` (`id`, `name`, `website`, `phone`, `address_street`, `address_city`, `address_state`, `address_zip`, `address_country`, `description`, `industry`, `annual_revenue`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Innovatech Solutions', 'http://innovatech.com', '555-0101', '123 Tech Drive', 'Techville', 'CA', '94043', 'USA', 'Leading provider of innovative tech solutions.', 'Technology', 1500000.00, 1, TRUE, NOW(), NOW(), 1),
(2, 'GreenLeaf Organics', 'http://greenleaf.com', '555-0102', '456 Nature Rd', 'Farmington', 'OR', '97005', 'USA', 'Organic produce and eco-friendly products.', 'Agriculture', 750000.00, 1, TRUE, NOW(), NOW(), 1),
(3, 'Constructo Corp (OrgWithActiveDeal)', 'http://constructo.com', '555-0103', '789 Builder Ave', 'Metro City', 'NY', '10001', 'USA', 'Large scale construction projects.', 'Construction', 10000000.00, 2, TRUE, NOW(), NOW(), 1),
(4, 'Global Consulting Group', NULL, NULL, '101 Advisor Plaza', 'London', 'LDN', 'EC1A 1AA', 'UK', 'Global business consulting services.', 'Consulting', 2500000.00, 2, TRUE, NOW(), NOW(), 1),
(5, 'Archive Systems Ltd (Inactive)', 'http://archive-systems.com', '555-0104', '22 Old Street', 'Archive City', 'OH', '44101', 'USA', 'Historical data archiving services.', 'Information Services', 300000.00, 1, FALSE, NOW(), NOW(), 1);

-- =============================================================================
-- Contacts
-- =============================================================================
INSERT INTO `contacts` (`id`, `first_name`, `last_name`, `email`, `phone_mobile`, `phone_work`, `title`, `organisation_id`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Alice', 'Wonder', 'alice.wonder@innovatech.com', '555-1101', '555-0101 ext 100', 'Lead Developer', 1, 1, TRUE, NOW(), NOW(), 1),
(2, 'Bob', 'Builder', 'bob.builder@constructo.com', '555-1102', NULL, 'Project Manager', 3, 2, TRUE, NOW(), NOW(), 1),
(3, 'Carol', 'Gardner', 'carol.gardner@greenleaf.com', NULL, '555-0102 ext 50', 'Chief Botanist', 2, 1, TRUE, NOW(), NOW(), 1),
(4, 'David', 'Advisor', 'david.advisor@globalconsulting.com', '555-1103', NULL, 'Senior Consultant', 4, 2, TRUE, NOW(), NOW(), 1),
(5, 'Eve', 'Independent', 'eve.independent@example.com', '555-1104', NULL, 'Freelancer', NULL, 1, TRUE, NOW(), NOW(), 1),
(6, 'Frank', 'Archivist (Inactive)', 'frank.archivist@archive-systems.com', '555-1105', NULL, 'Head Archivist', 5, 1, FALSE, NOW(), NOW(), 1);

-- =============================================================================
-- Deals
-- Deal 2 ('WonDeal') ID is 2, Deal 1 ('ActiveDealForOrg1') ID is 1
-- =============================================================================
INSERT INTO `deals` (`id`, `name`, `stage`, `amount`, `close_date`, `probability`, `description`, `organisation_id`, `contact_id`, `assigned_user_id`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Innovatech Website Relaunch (ActiveDealForOrg1)', 'Proposal', 75000.00, DATE_ADD(CURDATE(), INTERVAL 2 MONTH), 0.60, 'Proposal for full website redesign and CRM integration.', 1, 1, 2, 1, TRUE, NOW(), NOW(), 1),
(2, 'GreenLeaf Expansion Project (WonDeal)', 'Won', 120000.00, DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 1.00, 'Successfully closed deal for expanding their distribution network.', 2, 3, 2, 1, TRUE, NOW(), NOW(), 1),
(3, 'Constructo Corp Initial Assessment (LostDeal)', 'Lost', 15000.00, DATE_SUB(CURDATE(), INTERVAL 2 WEEKS), 0.10, 'Lost to competitor after initial assessment phase.', 3, 2, 1, 2, TRUE, NOW(), NOW(), 1),
(4, 'Global Consulting Analytics Setup', 'Qualification', 45000.00, DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 0.40, 'Qualifying needs for new analytics platform.', 4, 4, 3, 2, TRUE, NOW(), NOW(), 1),
(5, 'Innovatech Support Contract (InactiveDeal)', 'Prospecting', 25000.00, DATE_ADD(CURDATE(), INTERVAL 3 MONTH), 0.20, 'Initial talks for ongoing support contract.', 1, 1, 2, 1, FALSE, NOW(), NOW(), 1);


-- =============================================================================
-- Leads
-- Lead 3 ('ConvertedLeadToDeal2') links to Deal ID 2 ('WonDeal')
-- =============================================================================
INSERT INTO `leads` (`id`, `name`, `source`, `status`, `temperature`, `description`, `value`, `expected_close_date`, `contact_id`, `organisation_id`, `assigned_user_id`, `created_by_user_id`, `is_active`, `converted_to_deal_id`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Hot New Inquiry - Innovatech', 'Website', 'New', 'Hot', 'New inquiry from Innovatech website for cloud services.', 25000.00, DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 1, 1, 2, 1, TRUE, NULL, NOW(), NOW(), 1),
(2, 'Qualified Lead - GreenLeaf Organics', 'Referral', 'Qualified', 'Warm', 'Referred by existing client, interested in organic packaging solutions.', 30000.00, DATE_ADD(CURDATE(), INTERVAL 6 WEEKS), 3, 2, 2, 1, TRUE, NULL, NOW(), NOW(), 1),
(3, 'Old Lead Converted (ConvertedLeadToDeal2)', 'Trade Show', 'Converted', 'Hot', 'Met at trade show, showed strong interest. Converted to Deal ID 2.', 120000.00, DATE_SUB(CURDATE(), INTERVAL 2 MONTHS), 3, 2, 2, 1, TRUE, 2, DATE_SUB(NOW(), INTERVAL 2 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH), 2),
(4, 'Unqualified Inquiry - Constructo', 'Cold Call', 'Unqualified', 'Cold', 'Initial cold call, not a good fit for services at this time.', 5000.00, DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 2, 3, 1, 2, TRUE, NULL, NOW(), NOW(), 1),
(5, 'Archived Lead (InactiveLead)', 'Old List', 'Contacted', 'Cold', 'Contacted long ago, no response. Marked inactive.', 10000.00, DATE_SUB(CURDATE(), INTERVAL 3 MONTHS), 6, 5, 3, 1, FALSE, NULL, DATE_SUB(NOW(), INTERVAL 3 MONTH), DATE_SUB(NOW(), INTERVAL 3 MONTH), 1),
(6, 'Lead for Global Consulting', 'Website', 'New', 'Warm', 'Inquiry about international market entry strategy.', 60000.00, DATE_ADD(CURDATE(), INTERVAL 2 MONTHS), 4, 4, 3, 2, TRUE, NULL, NOW(), NOW(), 1);

-- =============================================================================
-- Lead Status History
-- =============================================================================
-- History for Lead 1 ('Hot New Inquiry - Innovatech')
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(1, NULL, 'New', NULL, 'Hot', 1, DATE_SUB(NOW(), INTERVAL 5 MINUTE));

-- History for Lead 2 ('Qualified Lead - GreenLeaf Organics')
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(2, NULL, 'New', NULL, 'Warm', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'New', 'Contacted', 'Warm', 'Warm', 2, DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(2, 'Contacted', 'Qualified', 'Warm', 'Hot', 2, DATE_SUB(NOW(), INTERVAL 6 HOUR));

-- History for Lead 3 ('ConvertedLeadToDeal2')
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(3, NULL, 'New', NULL, 'Cold', 2, DATE_SUB(NOW(), INTERVAL 60 DAY)),
(3, 'New', 'Contacted', 'Cold', 'Warm', 2, DATE_SUB(NOW(), INTERVAL 55 DAY)),
(3, 'Contacted', 'Qualified', 'Warm', 'Hot', 2, DATE_SUB(NOW(), INTERVAL 50 DAY)),
(3, 'Qualified', 'Converted', 'Hot', 'Hot', 1, DATE_SUB(NOW(), INTERVAL 30 DAY)); -- This is the conversion event

-- History for Lead 4 ('Unqualified Inquiry - Constructo')
INSERT INTO `lead_status_history` (`lead_id`, `old_status`, `new_status`, `old_temperature`, `new_temperature`, `changed_by_user_id`, `change_timestamp`) VALUES
(4, NULL, 'New', NULL, 'Cold', 2, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(4, 'New', 'Unqualified', 'Cold', 'Cold', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR));


-- =============================================================================
-- Activities
-- =============================================================================
INSERT INTO `activities` (`id`, `type`, `subject`, `description`, `due_date`, `status`, `notes_content`, `files_json`, `related_to_type`, `related_to_id`, `assigned_to_user_id`, `created_by_user_id`, `is_active`, `created_at`, `updated_at`, `version`) VALUES
(1, 'Call', 'Follow up call with Alice (Innovatech)', 'Discuss project scope and timeline.', DATE_ADD(NOW(), INTERVAL 3 DAY), 'Pending', '<p><strong>Agenda:</strong></p><ul><li>Item 1: Budget</li><li>Item 2: Resources</li></ul><p><em>Make sure to confirm next steps.</em></p>', '[{"name": "project_brief.pdf", "path": "sample_files/project_brief.pdf", "size": 123456, "type": "application/pdf"}]', 'Contact', 1, 2, 1, TRUE, NOW(), NOW(), 1),
(2, 'Meeting', 'Strategy Meeting with Constructo Corp', 'Initial strategy discussion for their new building project.', DATE_ADD(NOW(), INTERVAL 1 WEEK), 'Pending', '<h3>Meeting Prep</h3><ol><li>Review their portfolio.</li><li>Prepare presentation on similar projects.</li></ol>', '[]', 'Organisation', 3, 1, 2, TRUE, NOW(), NOW(), 1),
(3, 'Email', 'Send Proposal to GreenLeaf Organics', 'Email the final proposal document for the packaging solutions.', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Completed', '<p>Proposal sent. Waiting for feedback.</p>', '[{"name": "proposal_v3.docx", "path": "sample_files/proposal_v3.docx", "size": 789123, "type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document"}]', 'Lead', 2, 2, 1, TRUE, NOW(), NOW(), 1),
(4, 'Task', 'Update CRM with notes from Global Consulting call', NULL, 'Pending', 'Task for self to update notes related to David Advisor.', '<p>Call notes are on sticky pad.</p>', '[]', 'User', 3, 3, 3, TRUE, NOW(), NOW(), 1),
(5, 'Other', 'Site Visit - Innovatech Relaunch (Deal 1)', 'On-site visit to Innovatech for discovery phase of website relaunch.', DATE_ADD(NOW(), INTERVAL 5 DAY), 'Pending', '<h4>Visit Checklist:</h4><ul><li>Meet stakeholders</li><li>Assess current infrastructure</li></ul>', '[{"name":"site_visit_agenda.pdf","path":"sample_files/site_visit_agenda.pdf","size":50000,"type":"application/pdf"},{"name":"floor_plan.jpg","path":"sample_files/floor_plan.jpg","size":120000,"type":"image/jpeg"}]', 'Deal', 1, 2, 1, TRUE, NOW(), NOW(), 1),
(6, 'Call', 'Check-in with Inactive User (Inactive Activity)', 'Call to inactive user to see if situation changed.', DATE_SUB(NOW(), INTERVAL 1 MONTH), 'Cancelled', '<p>User is still inactive, no need to pursue.</p>', '[]', 'User', 4, 1, 1, FALSE, DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH), 1);


SET FOREIGN_KEY_CHECKS = 1;
