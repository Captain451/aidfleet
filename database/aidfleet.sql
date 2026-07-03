-- AidFleet database schema

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";





-- Core tables
CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  -- Phone verification flag (0 = unverified, 1 = verified via SMS OTP)
  phone_verified TINYINT(1) NOT NULL DEFAULT 0,
  -- Profile and ratings
  profile_image VARCHAR(255) NULL,
  avg_rating DECIMAL(3,1) NOT NULL DEFAULT 5.0,
  total_ratings INT NOT NULL DEFAULT 0,
  -- Account status
  account_status VARCHAR(20) NOT NULL DEFAULT 'active',
  account_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Drivers table (ambulance operators and vehicle details)
CREATE TABLE IF NOT EXISTS drivers (
  driver_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  -- Phone verification flag (0 = unverified, 1 = verified via SMS OTP)
  phone_verified TINYINT(1) NOT NULL DEFAULT 0,
  license_number VARCHAR(60) NOT NULL,
  ambulance_registration VARCHAR(60) NOT NULL,
  ambulance_type VARCHAR(100) NOT NULL,
  availability_status ENUM('offline','available','on_route') NOT NULL DEFAULT 'offline',
  verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  verification_note TEXT NULL,
  last_lat DECIMAL(10,7) NULL,
  last_lng DECIMAL(10,7) NULL,
  -- Driving license document
  documents_path VARCHAR(255) NULL,
  documents_original_name VARCHAR(255) NULL,
  documents_mime VARCHAR(100) NULL,
  documents_uploaded_at DATETIME NULL,
  -- Medical certification document
  medical_doc_path VARCHAR(255) NULL,
  medical_doc_original_name VARCHAR(255) NULL,
  medical_doc_mime VARCHAR(100) NULL,
  medical_doc_uploaded_at DATETIME NULL,
  -- Profile and ratings
  profile_image VARCHAR(255) NULL,
  avg_rating DECIMAL(3,1) NOT NULL DEFAULT 5.0,
  total_ratings INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Administrators
CREATE TABLE IF NOT EXISTS administrators (
  admin_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  profile_image VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Admin Login Rate Limiting
CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  lockout_until DATETIME NULL,
  last_attempt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_admin_email (email)
) ENGINE=InnoDB;

-- User Login Rate Limiting
CREATE TABLE IF NOT EXISTS login_attempts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  ip_address    VARCHAR(45) NOT NULL,
  attempts      INT NOT NULL DEFAULT 0,
  lockout_until DATETIME NULL,
  last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_email_ip (email, ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dispatch tables

-- Emergency requests submitted by users
CREATE TABLE IF NOT EXISTS emergency_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  emergency_type VARCHAR(80) NOT NULL,
  location VARCHAR(255) NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  description TEXT NOT NULL,
  request_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  request_status ENUM('pending','driver_selected','accepted','rejected','completed','cancelled') NOT NULL DEFAULT 'pending',
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_emergency_requests_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Dispatch records linking requests to drivers
CREATE TABLE IF NOT EXISTS dispatch_records (
  dispatch_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  driver_id INT NOT NULL,
  dispatch_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dispatch_status ENUM('selected','accepted','rejected','arrived','enroute_hospital','completed') NOT NULL DEFAULT 'selected',
  driver_response_time DATETIME NULL,
  completion_time DATETIME NULL,
  reject_reason VARCHAR(255) NULL,
  -- Trip ratings and feedback
  rating_by_requester INT NULL,
  comment_by_requester TEXT NULL,
  rating_by_driver INT NULL,
  comment_by_driver TEXT NULL,
  CONSTRAINT fk_dispatch_records_request
    FOREIGN KEY (request_id) REFERENCES emergency_requests(request_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dispatch_records_driver
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- System and audit

-- System audit logs
CREATE TABLE IF NOT EXISTS system_logs (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('requester','driver','admin','system') NOT NULL DEFAULT 'system',
  actor_id INT NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- OTP codes for password reset (email-based)
CREATE TABLE IF NOT EXISTS password_reset_otps (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  otp_code   VARCHAR(10) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used       TINYINT(1) NOT NULL DEFAULT 0,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  UNIQUE KEY unique_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_attempts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  ip_address    VARCHAR(45) NOT NULL,
  attempts      INT NOT NULL DEFAULT 0,
  lockout_until DATETIME NULL,
  last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_email_ip (email, ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phone OTP

-- OTPs are bcrypt-hashed and expire after 5 minutes.
-- A maximum of 5 verification attempts is allowed before a 60-second lockout.
CREATE TABLE IF NOT EXISTS phone_otps (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  phone       VARCHAR(30)  NOT NULL,
  otp_hash    VARCHAR(255) NOT NULL,
  attempts    TINYINT      NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME     NOT NULL,
  used        TINYINT(1)   NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  UNIQUE KEY unique_phone (phone)
) ENGINE=InnoDB;

-- Prevents SMS bombing by limiting to 5 sends per phone per 3-minute window.
CREATE TABLE IF NOT EXISTS sms_rate_limits (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  phone    VARCHAR(30)  NOT NULL,
  sent_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone_sent (phone, sent_at)
) ENGINE=InnoDB;
