<?php
$host = "localhost";
$username = "root";
$password = "";
$database = "keuangan";

// Create connection
$conn = new mysqli($host, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS keuangan";
if ($conn->query($sql) !== TRUE) {
    die("Error creating database: " . $conn->error);
}

// Select database
$conn->select_db($database);

// Create tables
$sql = "CREATE TABLE IF NOT EXISTS users(
    id         INT AUTO_INCREMENT NOT NULL PRIMARY KEY,
    username   VARCHAR(50)        NOT NULL UNIQUE,
    password   VARCHAR(255)       NOT NULL,
    email      VARCHAR(255)       NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    die("Error creating users table: " . $conn->error);
}

$sql = "CREATE TABLE IF NOT EXISTS transactions(
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT                        NOT NULL,
    type        ENUM ('income', 'expense') NOT NULL,
    amount      DECIMAL(12, 2)             NOT NULL,
    description TEXT,
    `date`      DATE                       NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id)
)";

if ($conn->query($sql) !== TRUE) {
    die("Error creating transactions table: " . $conn->error);
}

// Setelah pembuatan tabel transactions, tambahkan kode berikut:

$sql = "CREATE TABLE IF NOT EXISTS categories(
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_category (user_id, name)
)";

if ($conn->query($sql) !== TRUE) {
    die("Error creating categories table: " . $conn->error);
}

$sql = "CREATE TABLE IF NOT EXISTS budgets(
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    budget_amount DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_budget (user_id, category)
)";

if ($conn->query($sql) !== TRUE) {
    die("Error creating budgets table: " . $conn->error);
}

// Periksa apakah kolom category sudah ada di tabel transactions
$result = $conn->query("SHOW COLUMNS FROM transactions LIKE 'category'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE transactions ADD COLUMN category VARCHAR(50) NULL";
    if ($conn->query($sql) !== TRUE) {
        die("Error adding category column to transactions table: " . $conn->error);
    }
}
?>
