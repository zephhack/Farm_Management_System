-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS ifms_db;
USE ifms_db;

--Equipment Management
-- Equipment Table (This table is for the actual equipment listings)
CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    serial_number VARCHAR(100) UNIQUE NOT NULL,
    purchase_date DATE NOT NULL,
    purchase_cost DECIMAL(10, 2) NOT NULL,
    lifespan_years INT NOT NULL,
    salvage_value DECIMAL(10, 2) NOT NULL,
    total_operating_hours DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Equipment Allocations Table (Tracking Tasks and Operating Hours)
CREATE TABLE IF NOT EXISTS equipment_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    task_name VARCHAR(150) NOT NULL,
    operator_name VARCHAR(100) NOT NULL,
    hours_logged DECIMAL(6, 2) NOT NULL,
    allocation_date DATE NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
);

-- Maintenance Logs Table (Routine Services, Repairs and Reminders)
CREATE TABLE IF NOT EXISTS maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    service_type ENUM('Routine', 'Repair') NOT NULL,
    description TEXT NOT NULL,
    cost DECIMAL(10, 2) NOT NULL,
    service_date DATE NOT NULL,
    next_due_hours DECIMAL(10, 2) DEFAULT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
);

-- Fuel Logs Table (Fuel Consumption Tracking)
CREATE TABLE IF NOT EXISTS fuel_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    litres_consumed DECIMAL(8, 2) NOT NULL,
    total_cost DECIMAL(10, 2) NOT NULL,
    log_date DATE NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
);

--Employee Management
-- Employees / Farm-Worker Registration & Roles
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    role VARCHAR(100) NOT NULL, -- e.g., Supervisor, Field Worker, Machinery Operator
    phone VARCHAR(30) DEFAULT NULL,
    id_number VARCHAR(50) DEFAULT NULL,
    employment_type ENUM('Permanent', 'Contract', 'Casual') NOT NULL DEFAULT 'Casual',
    hourly_rate DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    daily_rate DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('Active', 'On Leave', 'Terminated') NOT NULL DEFAULT 'Active',
    date_joined DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Attendance Tracking
CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    log_date DATE NOT NULL,
    clock_in TIME DEFAULT NULL,
    clock_out TIME DEFAULT NULL,
    status ENUM('Present', 'Absent', 'Half-Day', 'On Leave') NOT NULL DEFAULT 'Present',
    hours_worked DECIMAL(5, 2) DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Daily Task Management & Work Allocation
CREATE TABLE IF NOT EXISTS farm_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(150) NOT NULL,
    assigned_employee_id INT NOT NULL,
    field_location VARCHAR(100) DEFAULT NULL,
    task_date DATE NOT NULL,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
    status ENUM('Pending', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    target_units DECIMAL(10,2) DEFAULT 0.00, -- e.g., 50 bags harvested
    completed_units DECIMAL(10,2) DEFAULT 0.00,
    unit_type VARCHAR(50) DEFAULT 'units', -- e.g., bags, hectares, rows
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (assigned_employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Payroll Integration & Labour Costs
CREATE TABLE IF NOT EXISTS payroll_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    base_pay DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    overtime_pay DECIMAL(10, 2) DEFAULT 0.00,
    deductions DECIMAL(10, 2) DEFAULT 0.00,
    net_pay DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('Pending', 'Paid', 'Cancelled') NOT NULL DEFAULT 'Pending',
    payment_date DATE DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Performance Monitoring
CREATE TABLE IF NOT EXISTS performance_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    eval_date DATE NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    efficiency_percentage DECIMAL(5,2) DEFAULT 0.00,
    reviewer_comments TEXT DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);