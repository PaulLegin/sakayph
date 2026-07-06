-- SakayPH Database Schema Setup
-- Database: sakayph_db
-- Create this database in phpMyAdmin first: CREATE DATABASE sakayph_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'driver', 'client') NOT NULL,
    status ENUM('pending_verification', 'verified', 'rejected') DEFAULT 'verified',
    wallet_balance DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL UNIQUE,
    brand VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    plate_number VARCHAR(20) NOT NULL,
    capacity INT NOT NULL,
    or_cr_photo VARCHAR(255) NOT NULL,
    ocr_vehicle_text TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS driver_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL UNIQUE,
    license_number VARCHAR(50) NOT NULL,
    license_photo VARCHAR(255) NOT NULL,
    ocr_license_text TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    origin_lat DECIMAL(10, 8) DEFAULT NULL,
    origin_lng DECIMAL(11, 8) DEFAULT NULL,
    destination_lat DECIMAL(10, 8) DEFAULT NULL,
    destination_lng DECIMAL(11, 8) DEFAULT NULL,
    departure_time DATETIME NOT NULL,
    estimated_hours INT NOT NULL DEFAULT 3,
    price_total DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'booked', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_id INT NOT NULL UNIQUE,
    client_id INT NOT NULL,
    paymongo_session_id VARCHAR(100) DEFAULT NULL,
    paymongo_payment_id VARCHAR(100) DEFAULT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    admin_commission DECIMAL(10,2) NOT NULL,
    driver_earnings DECIMAL(10,2) NOT NULL,
    status ENUM('pending_payment', 'confirmed', 'cancelled') DEFAULT 'pending_payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    gcash_number VARCHAR(20) NOT NULL,
    gcash_name VARCHAR(100) NOT NULL,
    status ENUM('pending', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Default Settings
INSERT INTO system_settings (setting_key, setting_value) VALUES 
('commission_rate', '10'),
('min_payout_amount', '1000')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Seed Default Admin
-- Email: admin@sakayph.com | Password: admin123
INSERT INTO users (name, email, phone, password, role, status) VALUES 
('Admin SakayPH', 'admin@sakayph.com', '09170000000', '$2y$10$JsVIW6NBiPZJeKmHCoLAsun1lbQGn1iIs016FT7u/bPLGeYnUMvya', 'admin', 'verified')
ON DUPLICATE KEY UPDATE name = VALUES(name);
