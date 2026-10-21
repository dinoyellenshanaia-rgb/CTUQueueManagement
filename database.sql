CREATE DATABASE IF NOT EXISTS ctu_queue_management
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ctu_queue_management;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    student_id VARCHAR(50) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student','staff','admin') NOT NULL DEFAULT 'student',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

CREATE TABLE offices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    accepting_queue TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    office_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_office (office_id, name),
    CONSTRAINT fk_services_office FOREIGN KEY (office_id) REFERENCES offices(id)
) ENGINE=InnoDB;

CREATE TABLE staff_offices (
    user_id BIGINT UNSIGNED NOT NULL,
    office_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, office_id),
    CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_staff_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE queue_counters (
    office_id BIGINT UNSIGNED NOT NULL,
    service_date DATE NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (office_id, service_date),
    CONSTRAINT fk_counter_office FOREIGN KEY (office_id) REFERENCES offices(id)
) ENGINE=InnoDB;

CREATE TABLE queue_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    office_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    service_date DATE NOT NULL,
    queue_number INT UNSIGNED NOT NULL,
    status ENUM('Waiting','Called','Serving','Completed','Cancelled','No-show') NOT NULL DEFAULT 'Waiting',
    active_office_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN status IN ('Waiting','Called','Serving') THEN office_id ELSE NULL END
    ) STORED,
    active_service_date DATE GENERATED ALWAYS AS (
        CASE WHEN status IN ('Waiting','Called','Serving') THEN service_date ELSE NULL END
    ) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    called_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_ticket_number (office_id, service_date, queue_number),
    UNIQUE KEY uq_user_active_office_day (user_id, active_office_id, active_service_date),
    INDEX idx_ticket_queue (office_id, service_date, status, queue_number),
    INDEX idx_ticket_user (user_id, created_at),
    CONSTRAINT fk_ticket_office FOREIGN KEY (office_id) REFERENCES offices(id),
    CONSTRAINT fk_ticket_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id, is_read, created_at),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_ticket FOREIGN KEY (ticket_id) REFERENCES queue_tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE office_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    office_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL COMMENT '0=Sunday through 6=Saturday',
    opens_at TIME NULL,
    closes_at TIME NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_office_weekday (office_id, weekday),
    CONSTRAINT fk_schedule_office FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    details VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE setup_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    admin_setup_complete TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO setup_state (id, admin_setup_complete) VALUES (1, 0);

INSERT INTO offices (name, description) VALUES
('Clinic', 'Campus health services'),
('Registrar', 'Student records and registration services'),
('MIS', 'Information systems and technical assistance'),
('SAO', 'Student affairs services'),
('Cashier', 'Payments and cashier services');

INSERT INTO services (office_id, name)
SELECT id, CASE name
  WHEN 'Clinic' THEN 'Consultation'
  WHEN 'Registrar' THEN 'Document request'
  WHEN 'MIS' THEN 'Account assistance'
  WHEN 'SAO' THEN 'Student assistance'
  WHEN 'Cashier' THEN 'Payment'
END FROM offices;

INSERT INTO services (office_id, name)
SELECT id, CASE name
  WHEN 'Clinic' THEN 'Medical certificate'
  WHEN 'Registrar' THEN 'Enrollment assistance'
  WHEN 'MIS' THEN 'Technical support'
  WHEN 'SAO' THEN 'Other inquiry'
  WHEN 'Cashier' THEN 'Other transaction'
END FROM offices;
