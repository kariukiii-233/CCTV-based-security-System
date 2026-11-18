-- =====================================================================
--  CCTV-BASED UNIVERSITY SECURITY SYSTEM  -  DATABASE
--  Import this file in phpMyAdmin (Import tab) or run:  mysql -u root < database.sql
--  WARNING: importing this file DROPS and recreates all tables,
--  so any data you added will be lost.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS cctv_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cctv_system;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS incidents;
DROP TABLE IF EXISTS recordings;
DROP TABLE IF EXISTS cameras;
DROP TABLE IF EXISTS zones;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------
-- USERS: everyone who can log in. Role 'admin' = administrator,
--        role 'operator' = security personnel ('management' is a spare read-only role)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id         INT AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(100) NOT NULL,
  username        VARCHAR(50)  NOT NULL UNIQUE,
  email           VARCHAR(100) NULL,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('admin','operator','management') NOT NULL DEFAULT 'operator',
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  last_login      DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ZONES: areas of the campus that cameras cover
-- ---------------------------------------------------------------------
CREATE TABLE zones (
  zone_id    INT AUTO_INCREMENT PRIMARY KEY,
  zone_name  VARCHAR(80) NOT NULL UNIQUE,
  building   VARCHAR(80) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CAMERAS: one row per physical camera
-- ---------------------------------------------------------------------
CREATE TABLE cameras (
  camera_id    INT AUTO_INCREMENT PRIMARY KEY,
  camera_code  VARCHAR(20)  NOT NULL UNIQUE,
  zone_id      INT          NOT NULL,
  location     VARCHAR(120) NOT NULL,
  ip_address   VARCHAR(45)  NULL,
  stream_url   VARCHAR(255) NULL,
  rtsp_url     VARCHAR(255) NULL,
  status       ENUM('online','offline','maintenance') NOT NULL DEFAULT 'online',
  installed_on DATE NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (zone_id) REFERENCES zones(zone_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RECORDINGS: footage log. end_time NULL = still recording
-- ---------------------------------------------------------------------
CREATE TABLE recordings (
  recording_id INT AUTO_INCREMENT PRIMARY KEY,
  camera_id    INT NOT NULL,
  start_time   DATETIME NOT NULL,
  end_time     DATETIME NULL,
  file_path    VARCHAR(255) NULL,
  file_size_mb DECIMAL(10,2) NOT NULL DEFAULT 0,
  started_by   INT NULL,
  FOREIGN KEY (camera_id)  REFERENCES cameras(camera_id) ON DELETE CASCADE,
  FOREIGN KEY (started_by) REFERENCES users(user_id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- INCIDENTS: security events logged by operators / admins
-- ---------------------------------------------------------------------
CREATE TABLE incidents (
  incident_id  INT AUTO_INCREMENT PRIMARY KEY,
  camera_id    INT NULL,
  recording_id INT NULL,
  reported_by  INT NULL,
  title        VARCHAR(150) NOT NULL,
  description  TEXT NULL,
  severity     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  status       ENUM('open','under_review','closed') NOT NULL DEFAULT 'open',
  created_at   DATETIME NOT NULL,
  closed_at    DATETIME NULL,
  FOREIGN KEY (camera_id)    REFERENCES cameras(camera_id)       ON DELETE SET NULL,
  FOREIGN KEY (recording_id) REFERENCES recordings(recording_id) ON DELETE SET NULL,
  FOREIGN KEY (reported_by)  REFERENCES users(user_id)           ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ALERTS: automatic or test alerts raised by cameras
-- ---------------------------------------------------------------------
CREATE TABLE alerts (
  alert_id        INT AUTO_INCREMENT PRIMARY KEY,
  camera_id       INT NOT NULL,
  alert_type      ENUM('motion','offline','tamper','other') NOT NULL,
  message         VARCHAR(255) NOT NULL,
  created_at      DATETIME NOT NULL,
  acknowledged_by INT NULL,
  acknowledged_at DATETIME NULL,
  FOREIGN KEY (camera_id)       REFERENCES cameras(camera_id) ON DELETE CASCADE,
  FOREIGN KEY (acknowledged_by) REFERENCES users(user_id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SETTINGS: system preferences (key / value)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  setting_key   VARCHAR(50)  PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- AUDIT_LOG: who did what, and when
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
  log_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  action     VARCHAR(60)  NOT NULL,
  details    VARCHAR(255) NULL,
  ip_address VARCHAR(45)  NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_incidents_status   ON incidents(status);
CREATE INDEX idx_incidents_created  ON incidents(created_at);
CREATE INDEX idx_recordings_cam     ON recordings(camera_id, start_time);
CREATE INDEX idx_alerts_ack         ON alerts(acknowledged_at);
CREATE INDEX idx_audit_created      ON audit_log(created_at);

-- =====================================================================
--  SAMPLE DATA
--  Two accounts:
--     admin      / admin123       (role admin)    - full access
--     security   / security123    (role operator) - security personnel
--  Change these passwords after your first login (Settings page).
-- =====================================================================

INSERT INTO users (user_id, full_name, username, email, password_hash, role, status, last_login, created_at) VALUES
(1, 'System Administrator', 'admin',    'admin@university.ac.ke',    '$2y$10$Nsf4azBzs69DXByscbCEqu197Skx8S.CbZQ510ckmxJo.RBf0oMGa', 'admin',    'active', NULL, '2026-09-01 08:00:00'),
(2, 'Security Personnel',   'security', 'security@university.ac.ke', '$2y$10$wO.WvGAnHKl81zLKYnkx1urvr902mOMAt9NRXQjDEOJ84Inuuuf8i', 'operator', 'active', NULL, '2026-09-01 08:05:00');

INSERT INTO zones (zone_id, zone_name, building) VALUES
(1, 'Main Gate',      'Gate House'),
(2, 'Library',        'Library Block'),
(3, 'Parking',        'Parking Lot A'),
(4, 'Administration', 'Admin Block'),
(5, 'Hostels',        'Hostel Block B'),
(6, 'Cafeteria',      'Student Centre');

INSERT INTO cameras (camera_id, camera_code, zone_id, location, ip_address, stream_url, rtsp_url, status, installed_on, created_at) VALUES
(1, 'CAM 01', 1, 'Main gate entrance',     '192.168.1.101', 'webcam', NULL, 'online',  '2025-08-12', '2026-09-01 09:00:00'),
(2, 'CAM 02', 2, 'Library entrance',       '192.168.1.102', NULL, NULL, 'offline',  '2025-08-12', '2026-09-01 09:00:00'),
(3, 'CAM 03', 3, 'Parking lot A',          '192.168.1.103', NULL, NULL, 'offline',  '2025-09-03', '2026-09-01 09:00:00'),
(4, 'CAM 04', 4, 'Admin block corridor',   '192.168.1.104', NULL, NULL, 'offline', '2025-09-03', '2026-09-01 09:00:00'),
(5, 'CAM 05', 5, 'Hostel block B fence',   '192.168.1.105', NULL, NULL, 'offline',  '2025-10-20', '2026-09-01 09:00:00'),
(6, 'CAM 06', 6, 'Cafeteria seating area', '192.168.1.106', NULL, NULL, 'offline',  '2025-10-20', '2026-09-01 09:00:00');

-- No sample recordings: real footage appears when a camera is connected and recorder.php is running.

INSERT INTO incidents (incident_id, camera_id, recording_id, reported_by, title, description, severity, status, created_at, closed_at) VALUES
(1, 2, NULL, 2, 'Unattended bag at library entrance', 'Black backpack left beside the main door for over 20 minutes. Security officer dispatched to check.', 'high', 'open', '2026-10-14 10:42:00', NULL),
(2, 4, NULL, 2, 'Motion after hours in admin block', 'Movement detected in the corridor at 02:15. Footage being reviewed.', 'medium', 'under_review', '2026-10-14 02:15:00', NULL),
(3, 1, NULL, 2, 'Tailgating at main gate', 'Two visitors followed a student through the turnstile without scanning cards. Reminder issued to gate staff.', 'low', 'closed', '2026-10-13 08:20:00', '2026-10-13 15:00:00'),
(4, 4, NULL, 1, 'CAM 04 went offline', 'Camera in admin block corridor stopped responding. Technician requested.', 'medium', 'open', '2026-10-13 22:05:00', NULL),
(5, 3, NULL, 2, 'Vehicle parked in restricted bay', 'Unregistered vehicle blocking the fire lane. Owner traced and vehicle moved.', 'low', 'closed', '2026-10-11 13:10:00', '2026-10-11 16:30:00'),
(6, 6, NULL, 2, 'Argument near cafeteria queue', 'Verbal altercation between two students. Dean of students informed.', 'medium', 'closed', '2026-10-09 12:40:00', '2026-10-09 14:00:00'),
(7, 5, NULL, 2, 'Suspicious person near hostel fence', 'Unknown person seen loitering near the Block B fence at night. Patrol sent.', 'high', 'under_review', '2026-10-12 23:30:00', NULL),
(8, 2, NULL, 1, 'Bicycle theft reported at library', 'Student reported a stolen bicycle. Footage reviewed; suspect identified and case handed to security office.', 'high', 'closed', '2026-10-08 17:45:00', '2026-10-10 09:00:00');

INSERT INTO alerts (alert_id, camera_id, alert_type, message, created_at, acknowledged_by, acknowledged_at) VALUES
(1, 2, 'motion',  'Motion detected at library entrance',        '2026-10-14 10:41:00', NULL, NULL),
(2, 4, 'offline', 'CAM 04 went offline',                         '2026-10-13 22:04:00', NULL, NULL),
(3, 5, 'motion',  'Motion detected near hostel fence',           '2026-10-12 23:28:00', 2, '2026-10-12 23:40:00'),
(4, 1, 'motion',  'Motion detected at main gate',                '2026-10-13 07:55:00', 2, '2026-10-13 08:01:00'),
(5, 3, 'tamper',  'Possible lens obstruction on CAM 03',         '2026-10-10 15:12:00', 1, '2026-10-10 15:30:00');

INSERT INTO settings (setting_key, setting_value) VALUES
('org_name',            'Zetech University'),
('storage_capacity_gb', '500'),
('retention_days',      '30'),
('motion_alerts',       '1'),
('auto_archive',        '1'),
('email_summary',       '0');

INSERT INTO audit_log (user_id, action, details, ip_address, created_at) VALUES
(1, 'login',          'Signed in',                '127.0.0.1', '2026-10-13 08:00:00'),
(2, 'incident_add',   'Logged: Tailgating at main gate', '127.0.0.1', '2026-10-13 08:20:00'),
(1, 'camera_status',  'CAM 04 set to offline',    '127.0.0.1', '2026-10-13 22:04:00');
