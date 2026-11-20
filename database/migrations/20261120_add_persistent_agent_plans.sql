-- Fase 2: planos persistentes e etapas retomáveis.
-- Compatível com as tabelas tasks e task_dependencies existentes.
CREATE TABLE IF NOT EXISTS agent_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    goal VARCHAR(4000) NOT NULL,
    status ENUM('pending','running','completed','failed','paused','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_agent_plans_user_status(user_id,status,updated_at),
    CONSTRAINT fk_agent_plans_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_plan_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    UNIQUE KEY uq_plan_step_index(plan_id,step_index),
    UNIQUE KEY uq_plan_task(task_id),
    INDEX idx_plan_steps_status(plan_id,status,step_index),
    CONSTRAINT fk_plan_step_plan FOREIGN KEY(plan_id) REFERENCES agent_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_step_task FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
