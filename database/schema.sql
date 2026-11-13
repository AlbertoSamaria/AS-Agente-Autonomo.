CREATE DATABASE IF NOT EXISTS sa_agent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sa_agent;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','operator','viewer') NOT NULL DEFAULT 'viewer',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles_permissions (
    role VARCHAR(40) NOT NULL,
    permission VARCHAR(80) NOT NULL,
    PRIMARY KEY(role, permission)
);

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id BIGINT UNSIGNED NOT NULL,
    permission VARCHAR(80) NOT NULL,
    granted TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY(user_id, permission),
    CONSTRAINT fk_user_permission_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    target_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    status ENUM('success','failed','warning') NOT NULL DEFAULT 'success',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    details_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user(user_id, created_at),
    INDEX idx_audit_target(target_user_id, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_target_user FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE SET NULL
);

INSERT IGNORE INTO roles_permissions(role,permission) VALUES
('admin','*'),
('manager','chat'),('manager','tasks.read'),('manager','tasks.write'),('manager','memory.read'),('manager','memory.write'),
('manager','rag.read'),('manager','rag.write'),('manager','tools.web'),('manager','tools.files'),('manager','tools.sql.read'),
('manager','approvals.read'),('manager','approvals.approve'),('manager','notifications.read'),
('operator','chat'),('operator','tasks.read'),('operator','tasks.write'),('operator','memory.read'),('operator','memory.write'),
('operator','rag.read'),('operator','tools.web'),('operator','tools.files'),('operator','tools.sql.read'),('operator','approvals.read'),
('viewer','chat'),('viewer','tasks.read'),('viewer','memory.read'),('viewer','rag.read'),('viewer','notifications.read');

CREATE TABLE IF NOT EXISTS sessions (
    id CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sessions_user(user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL DEFAULT 'Nova conversa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conv_user(user_id),
    CONSTRAINT fk_conv_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('system','user','assistant','tool') NOT NULL,
    content LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_conversation (conversation_id,id),
    CONSTRAINT fk_messages_conversation FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('pending','in_progress','completed','cancelled','blocked') NOT NULL DEFAULT 'pending',
    priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    due_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tasks_user_status(user_id,status),
    INDEX idx_tasks_parent(parent_id),
    CONSTRAINT fk_tasks_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_parent FOREIGN KEY(parent_id) REFERENCES tasks(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS task_dependencies (
    task_id BIGINT UNSIGNED NOT NULL,
    depends_on_task_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(task_id,depends_on_task_id),
    CONSTRAINT fk_dep_task FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_dep_on FOREIGN KEY(depends_on_task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS memories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    type ENUM('fact','preference','project','episode','note') NOT NULL DEFAULT 'fact',
    content TEXT NOT NULL,
    embedding_json LONGTEXT NULL,
    importance TINYINT UNSIGNED NOT NULL DEFAULT 5,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT KEY ft_memory_content(content),
    INDEX idx_memory_user(user_id),
    CONSTRAINT fk_memory_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending','processing','ready','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_docs_user(user_id),
    CONSTRAINT fk_docs_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS document_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    chunk_index INT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    embedding_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chunks_document(document_id),
    CONSTRAINT fk_chunks_document FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS agent_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    conversation_id BIGINT UNSIGNED NULL,
    user_input TEXT NOT NULL,
    plan JSON NULL,
    result LONGTEXT NULL,
    status ENUM('running','completed','failed','waiting_approval') NOT NULL DEFAULT 'running',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_agent_runs_status(status),
    CONSTRAINT fk_runs_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_runs_conv FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    agent_run_id BIGINT UNSIGNED NULL,
    action_type VARCHAR(80) NOT NULL,
    tool_name VARCHAR(80) NOT NULL,
    arguments_json JSON NOT NULL,
    reason TEXT NULL,
    status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_approvals_status(status),
    CONSTRAINT fk_approval_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_approval_run FOREIGN KEY(agent_run_id) REFERENCES agent_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_approval_decider FOREIGN KEY(decided_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    cron_expression VARCHAR(100) NOT NULL,
    action_type VARCHAR(80) NOT NULL,
    payload_json JSON NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schedule_next(enabled,next_run_at),
    CONSTRAINT fk_schedule_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL DEFAULT 'info',
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user(user_id,read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    tool_name VARCHAR(100) NULL,
    input_json JSON NULL,
    result_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user(user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS model_registry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL DEFAULT 'ollama',
    model_name VARCHAR(120) NOT NULL,
    purpose ENUM('general','reasoning','code','embedding') NOT NULL DEFAULT 'general',
    priority INT NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS request_limits (
    bucket_key CHAR(64) NOT NULL,
    window_start BIGINT UNSIGNED NOT NULL,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(bucket_key,window_start),
    INDEX idx_request_limits_window(window_start)
);

INSERT IGNORE INTO model_registry(provider,model_name,purpose,priority) VALUES
('ollama','qwen2.5:1.5b','general',10);

-- Primeiro utilizador admin:
-- gere o hash com:
-- php -r "echo password_hash('SUA_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
-- e depois:
-- INSERT INTO users(name,email,password_hash,role) VALUES ('Administrador','admin@local', 'HASH', 'admin');
