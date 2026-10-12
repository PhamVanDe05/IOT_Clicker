-- Students who typed an unknown MSSV on the phone page and asked to join the room; the teacher approves (✓) or
-- rejects (✗). One request per phone and per MSSV in a room. Approving moves the row into student + session_student.
CREATE TABLE IF NOT EXISTS join_request (
    session_id INT NOT NULL,
    device_uid VARCHAR(64) NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    student_name VARCHAR(120) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY(session_id, device_uid),
    UNIQUE KEY join_student (session_id, student_id),
    FOREIGN KEY(session_id) REFERENCES classroom_session(id),
    FOREIGN KEY(device_uid) REFERENCES device(device_uid)
) ENGINE=InnoDB;
