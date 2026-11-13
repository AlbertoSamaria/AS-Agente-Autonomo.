CREATE TABLE IF NOT EXISTS request_limits (
    bucket_key CHAR(64) NOT NULL,
    window_start BIGINT UNSIGNED NOT NULL,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY(bucket_key,window_start),
    INDEX idx_request_limits_window(window_start)
);
