-- SA 1.3.7: confirmação de e-mail e recuperação de palavra-passe
-- Executar uma única vez na base de dados existente.

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER active;

-- Contas já existentes são consideradas confirmadas para não perder acesso.
UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at)
WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS account_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('verify_email','password_reset') NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_tokens_user_purpose(user_id, purpose, used_at),
    INDEX idx_account_tokens_expiry(expires_at),
    CONSTRAINT fk_account_tokens_user
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
