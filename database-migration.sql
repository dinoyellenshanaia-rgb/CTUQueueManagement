-- Upgrade the legacy CTU Queue schema to the schema used by the current PHP app.
-- Back up the database first and run this file once, before deploying the new app.
-- Run the password-finishing CLI step described in README.md after this import.
USE ctu_queue_management;

-- Preflight: these queries should each return no rows before the migration begins.
SELECT user_id, office_id, queue_date, COUNT(*) AS duplicate_active_tickets
FROM queue_tickets
WHERE status IN ('waiting', 'serving')
GROUP BY user_id, office_id, queue_date
HAVING COUNT(*) > 1;
SELECT office_id, service_name, COUNT(*) AS duplicate_services
FROM services
GROUP BY office_id, service_name
HAVING COUNT(*) > 1;

ALTER TABLE offices
    CHANGE COLUMN office_name name VARCHAR(100) NOT NULL,
    ADD COLUMN accepting_queue TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE users
    MODIFY COLUMN full_name VARCHAR(120) NOT NULL,
    MODIFY COLUMN email VARCHAR(190) NOT NULL,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER email,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD INDEX idx_users_role_active (role, is_active);

ALTER TABLE services
    CHANGE COLUMN service_name name VARCHAR(120) NOT NULL,
    ADD UNIQUE KEY uq_service_office (office_id, name);

ALTER TABLE queue_tickets
    CHANGE COLUMN ticket_number legacy_ticket_number VARCHAR(30) NULL,
    CHANGE COLUMN queue_date service_date DATE NOT NULL,
    ADD COLUMN queue_number INT UNSIGNED NOT NULL DEFAULT 0 AFTER service_date,
    MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'waiting';

UPDATE queue_tickets
SET status = CASE status
    WHEN 'waiting' THEN 'Waiting'
    WHEN 'serving' THEN 'Serving'
    WHEN 'completed' THEN 'Completed'
    WHEN 'skipped' THEN 'No-show'
    WHEN 'cancelled' THEN 'Cancelled'
    ELSE status
END;

CREATE TEMPORARY TABLE queue_ticket_migration_numbers (
    id INT NOT NULL PRIMARY KEY,
    queue_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO queue_ticket_migration_numbers (id, queue_number)
SELECT id, ROW_NUMBER() OVER (PARTITION BY office_id, service_date ORDER BY id)
FROM queue_tickets;

UPDATE queue_tickets AS ticket
JOIN queue_ticket_migration_numbers AS migrated ON migrated.id = ticket.id
SET ticket.queue_number = migrated.queue_number;

DROP TEMPORARY TABLE queue_ticket_migration_numbers;

ALTER TABLE queue_tickets
    MODIFY COLUMN status ENUM('Waiting', 'Called', 'Serving', 'Completed', 'Cancelled', 'No-show')
        NOT NULL DEFAULT 'Waiting',
    ADD COLUMN active_office_id INT
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('Waiting', 'Called', 'Serving') THEN office_id ELSE NULL END
        ) STORED,
    ADD COLUMN active_service_date DATE
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('Waiting', 'Called', 'Serving') THEN service_date ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_ticket_office_date_number (office_id, service_date, queue_number),
    ADD UNIQUE KEY uq_user_active_office_day (user_id, active_office_id, active_service_date),
    ADD INDEX idx_ticket_queue (office_id, service_date, status, queue_number),
    ADD INDEX idx_ticket_user (user_id, created_at);

CREATE TABLE queue_counters (
    office_id INT NOT NULL,
    service_date DATE NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (office_id, service_date),
    CONSTRAINT fk_counter_office FOREIGN KEY (office_id) REFERENCES offices(id)
) ENGINE=InnoDB;

INSERT INTO queue_counters (office_id, service_date, last_number)
SELECT office_id, service_date, MAX(queue_number)
FROM queue_tickets
GROUP BY office_id, service_date;

ALTER TABLE office_schedules
    ADD COLUMN weekday_number TINYINT UNSIGNED NULL AFTER office_id;

UPDATE office_schedules
SET weekday_number = CASE day_of_week
    WHEN 'Sunday' THEN 0
    WHEN 'Monday' THEN 1
    WHEN 'Tuesday' THEN 2
    WHEN 'Wednesday' THEN 3
    WHEN 'Thursday' THEN 4
    WHEN 'Friday' THEN 5
    WHEN 'Saturday' THEN 6
END;

ALTER TABLE office_schedules
    DROP INDEX office_id,
    DROP COLUMN day_of_week,
    CHANGE COLUMN weekday_number weekday TINYINT UNSIGNED NOT NULL,
    CHANGE COLUMN opening_time opens_at TIME NULL,
    CHANGE COLUMN closing_time closes_at TIME NULL,
    ADD UNIQUE KEY uq_office_weekday (office_id, weekday);

ALTER TABLE activity_logs
    ADD COLUMN details VARCHAR(500) NULL AFTER action;

CREATE TABLE setup_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    admin_setup_complete TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO setup_state (id, admin_setup_complete) VALUES
(1, IF((SELECT COUNT(*) FROM users WHERE role = 'admin') > 0, 1, 0));
