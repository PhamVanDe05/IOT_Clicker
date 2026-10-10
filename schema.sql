-- Clicker database: 11 tables in 4 groups (diagram: HE_THONG.md section 5).
--   1 Account: teacher_account       2 Teacher data: student, exam, question
--   3 Rooms: classroom_session, session_student, session_question, student_response
--   4 System: device, request_limit, app_state
-- New database: load this file. Existing database: php tools/upgrade_time_score.php
-- Older 16-table database: php tools/upgrade_db.php first.

-- 1 Account
CREATE TABLE IF NOT EXISTS teacher_account (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2 Teacher data. A class is the students sharing a class_id; it has no table of its own.
CREATE TABLE IF NOT EXISTS student (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    student_no INT NOT NULL,
    student_name VARCHAR(120) NOT NULL,
    class_id VARCHAR(60) NOT NULL,
    UNIQUE(teacher_id, student_id),
    KEY(teacher_id, class_id),
    FOREIGN KEY(teacher_id) REFERENCES teacher_account(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS exam (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    title VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(id, teacher_id),
    FOREIGN KEY(teacher_id) REFERENCES teacher_account(id)
) ENGINE=InnoDB;
-- The correct answer never leaves the server towards a device.
CREATE TABLE IF NOT EXISTS question (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_no INT NOT NULL,
    question_text TEXT NOT NULL,
    answer_A TEXT NOT NULL, answer_B TEXT NOT NULL,
    answer_C TEXT NOT NULL, answer_D TEXT NOT NULL,
    correct_answer CHAR(1) NOT NULL CHECK(correct_answer IN ('A','B','C','D')),
    UNIQUE(exam_id, question_no),
    FOREIGN KEY(exam_id) REFERENCES exam(id)
) ENGINE=InnoDB;

-- 4 System. Every ESP32, phone, nRF tag and bridge that called the server; kind/key_hash only for
-- ESP32 boards and bridges registered with tools/device.php (revoked_at set = blocked).
CREATE TABLE IF NOT EXISTS device (
    device_uid VARCHAR(64) PRIMARY KEY,
    last_seen DATETIME(3) NULL,
    kind ENUM('esp','bridge') NULL,
    key_hash CHAR(64) NULL UNIQUE,
    revoked_at DATETIME NULL,
    registered_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS request_limit (
    bucket VARCHAR(32) NOT NULL,
    actor_hash CHAR(64) NOT NULL,
    window_id BIGINT NOT NULL,
    hits INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(bucket, actor_hash)
) ENGINE=InnoDB;

-- 3 Rooms. A room holds its state flags; last_activity is the last teacher command (idle rooms end by themselves).
CREATE TABLE IF NOT EXISTS classroom_session (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    title VARCHAR(120) NOT NULL DEFAULT '',
    class_id VARCHAR(60) NOT NULL,
    exam_id INT NOT NULL,
    state ENUM('CREATED','LOGIN_OPEN','READY','QUESTION_ACTIVE','FINISHED') NOT NULL DEFAULT 'CREATED',
    current_question_id INT NULL,
    auto_mode TINYINT(1) NOT NULL DEFAULT 0,
    auto_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 30 CHECK(auto_seconds BETWEEN 1 AND 3600),
    auto_deadline DATETIME(3) NULL,
    last_activity DATETIME(3) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(teacher_id) REFERENCES teacher_account(id),
    FOREIGN KEY(exam_id, teacher_id) REFERENCES exam(id, teacher_id),
    FOREIGN KEY(current_question_id) REFERENCES question(id)
) ENGINE=InnoDB;
-- The class list copied when the room is created, plus the device each student logged in with
-- (NULL = not logged in; one device per student and one student per device in a room).
CREATE TABLE IF NOT EXISTS session_student (
    session_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    student_no INT NOT NULL,
    student_name VARCHAR(120) NOT NULL,
    device_uid VARCHAR(64) NULL,
    PRIMARY KEY(session_id, student_id),
    UNIQUE KEY session_device (session_id, device_uid),
    FOREIGN KEY(session_id) REFERENCES classroom_session(id),
    FOREIGN KEY(device_uid) REFERENCES device(device_uid)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS session_question (
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    opened_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    closed_at DATETIME(3) NULL,
    PRIMARY KEY(session_id, question_id),
    FOREIGN KEY(session_id) REFERENCES classroom_session(id),
    FOREIGN KEY(question_id) REFERENCES question(id)
) ENGINE=InnoDB;
-- One answer per student per question, only from the device that student logged in with and only for a
-- question opened in the room: the foreign keys refuse anything else.
CREATE TABLE IF NOT EXISTS student_response (
    session_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    question_id INT NOT NULL,
    selected_answer CHAR(1) NOT NULL CHECK(selected_answer IN ('A','B','C','D')),
    device_uid VARCHAR(64) NOT NULL,
    saved_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY(session_id, student_id, question_id),
    FOREIGN KEY(session_id, student_id) REFERENCES session_student(session_id, student_id),
    FOREIGN KEY(session_id, device_uid) REFERENCES session_student(session_id, device_uid),
    FOREIGN KEY(session_id, question_id) REFERENCES session_question(session_id, question_id)
) ENGINE=InnoDB;

-- 4 System. One row: the running room every device follows, and the device mode
-- (wifi = ESP32 and phones, ble = nRF52840 tags through bridge/ble_bridge.py).
CREATE TABLE IF NOT EXISTS app_state (
    id INT PRIMARY KEY,
    session_id INT NULL,
    mode ENUM('wifi','ble') NOT NULL DEFAULT 'wifi',
    FOREIGN KEY(session_id) REFERENCES classroom_session(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO app_state(id, session_id) VALUES(1, NULL);
