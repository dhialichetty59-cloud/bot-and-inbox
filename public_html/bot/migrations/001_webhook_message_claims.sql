CREATE TABLE `webhook_message_claims` (
  `message_id` varchar(255) NOT NULL,
  `claim_token` char(32) NOT NULL,
  `status` enum('processing', 'completed') NOT NULL DEFAULT 'processing',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `status_updated_at` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
