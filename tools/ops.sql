-- Admin tools (03/10/2026): feature switches, activity log, forgotten-password requests, device names.
CREATE TABLE IF NOT EXISTS app_setting (
    name VARCHAR(40) PRIMARY KEY,
    value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actor_id INT NULL,
    actor_name VARCHAR(120) NULL,
    action VARCHAR(40) NOT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    ip VARCHAR(64) NOT NULL DEFAULT '',
    KEY(at), KEY(actor_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS password_request (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(64) NOT NULL DEFAULT '',
    handled_at DATETIME NULL,
    KEY(teacher_id),
    FOREIGN KEY(teacher_id) REFERENCES teacher_account(id) ON DELETE CASCADE
) ENGINE=InnoDB;
