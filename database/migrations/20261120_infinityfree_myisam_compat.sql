-- SA 1.3.7 — migração de produção para InfinityFree (MyISAM)
-- Executar UMA ÚNICA VEZ na base de dados existente do SA.
-- Esta versão não usa FOREIGN KEY porque o alojamento actual usa MyISAM.
-- As relações entre registos são mantidas pela aplicação através dos IDs e JOINs.
-- Não elimina nem recria utilizadores, tarefas ou quaisquer tabelas existentes.

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER active;

-- Preserva o acesso às contas já existentes: são consideradas verificadas.
UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at)
WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS account_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('verify_email','password_reset') NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_tokens_hash (token_hash),
    KEY idx_account_tokens_user_purpose (user_id, purpose, used_at),
    KEY idx_account_tokens_expiry (expires_at)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    goal VARCHAR(4000) NOT NULL,
    status ENUM('pending','running','completed','failed','paused','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agent_plans_user_status (user_id, status, updated_at)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_plan_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NOT NULL,
    step_index SMALLINT UNSIGNED NOT NULL,
    status ENUM('pending','running','completed','failed','waiting_approval','cancelled') NOT NULL DEFAULT 'pending',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    output LONGTEXT NULL,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plan_step_index (plan_id, step_index),
    UNIQUE KEY uq_plan_task (task_id),
    KEY idx_plan_steps_status (plan_id, status, step_index)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
