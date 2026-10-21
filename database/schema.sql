-- =====================================================================
-- YarToken Platform — Complete Enterprise MySQL 8 / MariaDB 10.6+ Schema
-- Precision: DECIMAL(30,8) across all balances, supplies, fees & loans
-- Ledger: Append-only SHA-256 hash-chained double-verifiable journal
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Key-value dynamic configuration
CREATE TABLE IF NOT EXISTS `settings` (
    `skey` VARCHAR(96) NOT NULL,
    `sval` MEDIUMTEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Super-Admins & Operators (with multi-channel OTP + TOTP + Recovery Codes)
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(120) NOT NULL DEFAULT 'مدیر ارشد',
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('superadmin', 'operator', 'auditor') NOT NULL DEFAULT 'superadmin',
    `bale_chat_id` VARCHAR(40) NOT NULL DEFAULT '',
    `telegram_chat_id` VARCHAR(40) NOT NULL DEFAULT '',
    `totp_secret_enc` TEXT DEFAULT NULL,
    `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `totp_last_slice` BIGINT NOT NULL DEFAULT 0,
    `recovery_hashes_json` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(64) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Unified Wallet Accounts (System Treasury, Users, Agents, Merchants, Escrow)
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_type` ENUM('user', 'agent', 'merchant', 'treasury', 'fee_vault', 'burn_sink', 'escrow') NOT NULL DEFAULT 'user',
    `wallet_code` VARCHAR(32) NOT NULL COMMENT 'Human friendly ID e.g. YAR-8F3K92',
    `evm_address` CHAR(42) NOT NULL COMMENT '0x... EIP-55 wallet address (Android BIP-44 or derived)',
    `public_key_hex` VARCHAR(132) NOT NULL DEFAULT '' COMMENT 'Compressed/uncompressed secp256k1 pubkey hex',
    `username` VARCHAR(64) DEFAULT NULL,
    `phone` VARCHAR(32) DEFAULT NULL,
    `full_name` VARCHAR(140) NOT NULL DEFAULT 'کاربر کیف پول',
    `password_hash` VARCHAR(255) DEFAULT NULL,
    `totp_secret_enc` TEXT DEFAULT NULL,
    `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `totp_last_slice` BIGINT NOT NULL DEFAULT 0,
    `balance` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `locked_balance` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `total_received` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `total_sent` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `total_fees_paid` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `credit_score` SMALLINT UNSIGNED NOT NULL DEFAULT 650 COMMENT '300..850 borrower reputation score',
    `fee_tier` ENUM('standard', 'vip', 'exempt') NOT NULL DEFAULT 'standard',
    `parent_agent_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Assigned Representative / Lender user_id',
    `bale_user_id` VARCHAR(40) DEFAULT NULL,
    `telegram_user_id` VARCHAR(40) DEFAULT NULL,
    `external_social_id` VARCHAR(80) DEFAULT NULL,
    `bot_state` VARCHAR(80) NOT NULL DEFAULT '',
    `bot_state_data` TEXT DEFAULT NULL,
    `is_frozen` TINYINT(1) NOT NULL DEFAULT 0,
    `freeze_reason` VARCHAR(255) NOT NULL DEFAULT '',
    `tx_nonce` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `daily_chat_earned_date` DATE DEFAULT NULL,
    `daily_chat_earned_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `last_active_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_wallet_code` (`wallet_code`),
    UNIQUE KEY `uk_users_evm_address` (`evm_address`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_bale` (`bale_user_id`),
    UNIQUE KEY `uk_users_telegram` (`telegram_user_id`),
    KEY `idx_users_parent_agent` (`parent_agent_id`),
    KEY `idx_users_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Representatives / Lenders Profile & Credit Pools
CREATE TABLE IF NOT EXISTS `agents` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `agent_code` VARCHAR(24) NOT NULL COMMENT 'Invite code e.g. AG-TEHRAN1',
    `title` VARCHAR(140) NOT NULL DEFAULT 'نمایندگی رسمی',
    `credit_pool_limit` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000 COMMENT 'Credit line granted by Super-Admin for lending',
    `credit_pool_used` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `max_interest_bps` INT UNSIGNED NOT NULL DEFAULT 1200 COMMENT 'Max interest % in basis points (1200 = 12%)',
    `max_loan_per_user` DECIMAL(30,8) NOT NULL DEFAULT 10000.00000000,
    `max_term_days` INT UNSIGNED NOT NULL DEFAULT 180,
    `commission_bps` INT UNSIGNED NOT NULL DEFAULT 2000 COMMENT 'Share of sub-user transfer fees (2000 = 20% of fee)',
    `transfer_fee_discount_bps` INT UNSIGNED NOT NULL DEFAULT 5000 COMMENT 'Discount on agent outgoing transfers (5000 = 50% off)',
    `can_lend_from_wallet` TINYINT(1) NOT NULL DEFAULT 1,
    `can_lend_from_pool` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uk_agents_code` (`agent_code`),
    CONSTRAINT `fk_agents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Hash-Chained Verifiable Token Ledger (Every Mint, Burn, Transfer, Fee, Loan & Reward)
CREATE TABLE IF NOT EXISTS `ledger_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tx_hash` CHAR(66) NOT NULL COMMENT '0x + SHA-256 chain hash linking prev_hash + payload',
    `prev_hash` CHAR(66) NOT NULL,
    `tx_type` ENUM(
        'mint', 'burn', 'transfer', 'agent_transfer',
        'loan_disburse', 'loan_repay', 'fee_collect', 'fee_burn',
        'agent_commission', 'group_tip', 'red_packet_create', 'red_packet_claim', 'red_packet_refund',
        'chat_reward', 'invoice_pay', 'invoice_refund', 'admin_adjust'
    ) NOT NULL,
    `from_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `to_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `gross_amount` DECIMAL(30,8) NOT NULL COMMENT 'Initiated amount',
    `net_amount` DECIMAL(30,8) NOT NULL COMMENT 'Credited to recipient',
    `fee_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `burned_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `agent_commission_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `sender_balance_after` DECIMAL(30,8) DEFAULT NULL,
    `receiver_balance_after` DECIMAL(30,8) DEFAULT NULL,
    `channel` VARCHAR(32) NOT NULL DEFAULT 'web' COMMENT 'web, android, rpc, bale, telegram, group, cron, admin',
    `reference_id` VARCHAR(96) DEFAULT NULL,
    `idempotency_key` VARCHAR(96) DEFAULT NULL,
    `signature_hex` VARCHAR(160) DEFAULT NULL COMMENT 'Optional ECDSA signature proof from non-custodial wallet',
    `memo` VARCHAR(255) NOT NULL DEFAULT '',
    `meta_json` TEXT DEFAULT NULL,
    `created_by_admin_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ledger_tx_hash` (`tx_hash`),
    UNIQUE KEY `uk_ledger_idempotency` (`idempotency_key`),
    KEY `idx_ledger_from` (`from_user_id`, `id`),
    KEY `idx_ledger_to` (`to_user_id`, `id`),
    KEY `idx_ledger_type_created` (`tx_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Representative / Lender Loans & Installment Schedules
CREATE TABLE IF NOT EXISTS `loans` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `loan_code` VARCHAR(28) NOT NULL COMMENT 'e.g. LN-2026-88412',
    `agent_user_id` BIGINT UNSIGNED NOT NULL,
    `borrower_user_id` BIGINT UNSIGNED NOT NULL,
    `funding_source` ENUM('credit_pool', 'agent_wallet') NOT NULL DEFAULT 'credit_pool',
    `principal_amount` DECIMAL(30,8) NOT NULL,
    `interest_bps` INT UNSIGNED NOT NULL DEFAULT 0,
    `interest_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `total_repayable` DECIMAL(30,8) NOT NULL,
    `repaid_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `remaining_amount` DECIMAL(30,8) NOT NULL,
    `penalty_bps_per_late` INT UNSIGNED NOT NULL DEFAULT 0,
    `installments_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `interval_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `auto_deduct` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('requested', 'active', 'repaid', 'overdue', 'defaulted', 'rejected', 'cancelled') NOT NULL DEFAULT 'active',
    `purpose` VARCHAR(255) NOT NULL DEFAULT '',
    `disbursed_tx_hash` CHAR(66) DEFAULT NULL,
    `next_due_at` DATETIME DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_loans_code` (`loan_code`),
    KEY `idx_loans_agent` (`agent_user_id`, `status`),
    KEY `idx_loans_borrower` (`borrower_user_id`, `status`),
    KEY `idx_loans_next_due` (`status`, `next_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loan_installments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `loan_id` BIGINT UNSIGNED NOT NULL,
    `installment_no` SMALLINT UNSIGNED NOT NULL,
    `due_at` DATETIME NOT NULL,
    `amount_due` DECIMAL(30,8) NOT NULL,
    `amount_paid` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `penalty_added` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `status` ENUM('pending', 'paid', 'overdue') NOT NULL DEFAULT 'pending',
    `paid_at` DATETIME DEFAULT NULL,
    `paid_tx_hash` CHAR(66) DEFAULT NULL,
    `reminded_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_loan_inst` (`loan_id`, `installment_no`),
    KEY `idx_inst_due` (`status`, `due_at`),
    CONSTRAINT `fk_inst_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Unified OTP Challenges (Admin, Agent, Web User, API Login, Pairing Codes)
CREATE TABLE IF NOT EXISTS `otp_challenges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope` ENUM('admin_login', 'user_login', 'tx_confirm', 'social_link') NOT NULL DEFAULT 'admin_login',
    `target_id` BIGINT UNSIGNED NOT NULL,
    `token_selector` CHAR(32) NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `delivery_channel` VARCHAR(191) NOT NULL DEFAULT 'bale',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
    `meta_json` TEXT DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_otp_selector` (`token_selector`),
    KEY `idx_otp_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Mobile & API Authentication Nonces + Sessions (Android Wallet ECDSA & Merchant Keys)
CREATE TABLE IF NOT EXISTS `auth_nonces` (
    `nonce` CHAR(48) NOT NULL,
    `evm_address` CHAR(42) NOT NULL,
    `purpose` VARCHAR(32) NOT NULL DEFAULT 'wallet_auth',
    `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`nonce`),
    KEY `idx_nonces_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_prefix` CHAR(12) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `device_name` VARCHAR(120) NOT NULL DEFAULT 'Android Wallet Yar',
    `scopes` VARCHAR(255) NOT NULL DEFAULT 'wallet,transfer,loans,agent',
    `last_used_at` DATETIME DEFAULT NULL,
    `last_ip` VARCHAR(64) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_api_token_hash` (`token_hash`),
    KEY `idx_api_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Connected Social Groups & Channels + Group Red Packets (Token Rain)
CREATE TABLE IF NOT EXISTS `social_groups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `platform` ENUM('bale', 'telegram', 'bridge') NOT NULL,
    `group_chat_id` VARCHAR(64) NOT NULL,
    `title` VARCHAR(180) NOT NULL DEFAULT 'گروه متصل',
    `tipping_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `red_packets_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `chat_reward_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `chat_reward_amount` DECIMAL(30,8) NOT NULL DEFAULT 0.50000000,
    `daily_user_cap` DECIMAL(30,8) NOT NULL DEFAULT 10.00000000,
    `min_msg_length` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
    `welcome_bonus` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `total_tips_volume` DECIMAL(30,8) NOT NULL DEFAULT 0.00000000,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_platform_group` (`platform`, `group_chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `red_packets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `packet_code` VARCHAR(24) NOT NULL,
    `creator_user_id` BIGINT UNSIGNED NOT NULL,
    `platform` VARCHAR(24) NOT NULL DEFAULT 'bale',
    `group_chat_id` VARCHAR(64) NOT NULL DEFAULT '',
    `mode` ENUM('equal', 'lucky') NOT NULL DEFAULT 'equal',
    `total_amount` DECIMAL(30,8) NOT NULL,
    `remaining_amount` DECIMAL(30,8) NOT NULL,
    `max_claims` INT UNSIGNED NOT NULL,
    `claimed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(140) NOT NULL DEFAULT 'پاکت هدیه گروهی',
    `passcode_hash` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('active', 'completed', 'expired', 'refunded') NOT NULL DEFAULT 'active',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_packet_code` (`packet_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `red_packet_claims` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `packet_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(30,8) NOT NULL,
    `tx_hash` CHAR(66) NOT NULL,
    `claimed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_packet_user` (`packet_id`, `user_id`),
    CONSTRAINT `fk_claim_packet` FOREIGN KEY (`packet_id`) REFERENCES `red_packets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Merchant Payment Invoices (/pay Checkout + API)
CREATE TABLE IF NOT EXISTS `merchant_invoices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_code` VARCHAR(32) NOT NULL,
    `merchant_user_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(30,8) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `callback_url` VARCHAR(255) NOT NULL DEFAULT '',
    `return_url` VARCHAR(255) NOT NULL DEFAULT '',
    `external_order_id` VARCHAR(96) NOT NULL DEFAULT '',
    `payer_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `paid_tx_hash` CHAR(66) DEFAULT NULL,
    `refund_tx_hash` CHAR(66) DEFAULT NULL,
    `status` ENUM('pending', 'paid', 'refunded', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
    `expires_at` DATETIME NOT NULL,
    `paid_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_invoice_code` (`invoice_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Security Rate Limits & Immutable Audit Log
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `rl_key` VARCHAR(128) NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 1,
    `reset_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`rl_key`),
    KEY `idx_rl_reset` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_type` ENUM('admin', 'agent', 'user', 'system', 'api') NOT NULL DEFAULT 'system',
    `actor_id` BIGINT UNSIGNED DEFAULT NULL,
    `event` VARCHAR(80) NOT NULL,
    `summary` VARCHAR(255) NOT NULL,
    `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
    `context_json` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
