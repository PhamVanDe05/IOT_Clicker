-- Teacher profile (filled after sign-up, also for Google accounts) and classes created without a student file.
CREATE TABLE IF NOT EXISTS teacher_profile (
    teacher_id INT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    gender ENUM('nam','nu','khac') NOT NULL DEFAULT 'khac',
    school VARCHAR(160) NOT NULL DEFAULT '',
    subject VARCHAR(120) NOT NULL DEFAULT '',
    grades VARCHAR(120) NOT NULL DEFAULT '',
    phone VARCHAR(20) NOT NULL DEFAULT '',
    bio VARCHAR(300) NOT NULL DEFAULT '',
    avatar MEDIUMBLOB NULL,
    avatar_type VARCHAR(20) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY(teacher_id) REFERENCES teacher_account(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- A class that exists before any student: students join with the room code and give their name + MSSV,
-- the teacher approves them, and the list is numbered by MSSV.
CREATE TABLE IF NOT EXISTS teacher_class (
    teacher_id INT NOT NULL,
    class_id VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(teacher_id, class_id),
    FOREIGN KEY(teacher_id) REFERENCES teacher_account(id) ON DELETE CASCADE
) ENGINE=InnoDB;
