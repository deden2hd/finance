<?php
session_start();
require_once 'koneksi.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Process transaction form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_transaction'])) {
    $type = $_POST['type'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    $category = !empty($_POST['category']) ? $_POST['category'] : null;

    $stmt = $conn->prepare("INSERT INTO transactions (user_id, type, amount, description, date, category) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isdsss", $user_id, $type, $amount, $description, $date, $category);

    if ($stmt->execute()) {
        $success_message = "Transaksi berhasil ditambahkan!";
    } else {
        $error_message = "Gagal menambahkan transaksi: " . $conn->error;
    }

    $stmt->close();
}

// Process transaction deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_transaction'])) {
    $transaction_id = $_POST['transaction_id'];

    $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $transaction_id, $user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $success_message = "Transaksi berhasil dihapus!";
    } else {
        $error_message = "Gagal menghapus transaksi.";
    }

    $stmt->close();
}

// Get filter parameters
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';

// Build the query based on filters
$query = "SELECT * FROM transactions WHERE user_id = ?";
$params = array($user_id);
$types = "i";

if ($filter_type !== 'all') {
    $query .= " AND type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($filter_category !== 'all') {
    if ($filter_category === 'uncategorized') {
        $query .= " AND (category IS NULL OR category = '')";
    } else {
        $query .= " AND category = ?";
        $params[] = $filter_category;
        $types .= "s";
    }
}

if ($filter_month && $filter_year) {
    $query .= " AND MONTH(date) = ? AND YEAR(date) = ?";
    $params[] = $filter_month;
    $params[] = $filter_year;
    $types .= "ii";
}

$query .= " ORDER BY date DESC";

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();

// Calculate summary
$total_income = 0;
$total_expense = 0;

$summary_query = "SELECT type, SUM(amount) as total FROM transactions WHERE user_id = ?";
$summary_params = array($user_id);
$summary_types = "i";

if ($filter_month && $filter_year) {
    $summary_query .= " AND MONTH(date) = ? AND YEAR(date) = ?";
    $summary_params[] = $filter_month;
    $summary_params[] = $filter_year;
    $summary_types .= "ii";
}

$summary_query .= " GROUP BY type";

$summary_stmt = $conn->prepare($summary_query);
$summary_stmt->bind_param($summary_types, ...$summary_params);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();

while ($row = $summary_result->fetch_assoc()) {
    if ($row['type'] === 'income') {
        $total_income = $row['total'];
    } else {
        $total_expense = $row['total'];
    }
}

$balance = $total_income - $total_expense;

// Check if categories table exists
$table_exists = false;
$result = $conn->query("SHOW TABLES LIKE 'categories'");
if ($result->num_rows > 0) {
    $table_exists = true;
}

// If categories table doesn't exist, create it
if (!$table_exists) {
    $sql = "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY unique_category (user_id, name)
    )";
    $conn->query($sql);
}

// Get categories for dropdown
$categories = [];
try {
    $categories_query = "SELECT DISTINCT name FROM categories WHERE user_id = ? ORDER BY name";
    $stmt = $conn->prepare($categories_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $categories_result = $stmt->get_result();
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['name'];
    }
} catch (Exception $e) {
    // Handle error silently
}

// Check if category column exists in transactions table
$result = $conn->query("SHOW COLUMNS FROM transactions LIKE 'category'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE transactions ADD COLUMN category VARCHAR(50) NULL";
    $conn->query($sql);
}

// After ensuring the column exists, then run the query to get categories
$transaction_categories_query = "SELECT DISTINCT category FROM transactions WHERE user_id = ? AND category IS NOT NULL AND category != '' ORDER BY category";
$stmt = $conn->prepare($transaction_categories_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$transaction_categories_result = $stmt->get_result();
$transaction_categories = [];
while ($row = $transaction_categories_result->fetch_assoc()) {
    $transaction_categories[] = $row['category'];
}

// Merge and deduplicate categories
$all_categories = array_unique(array_merge($categories, $transaction_categories));
sort($all_categories);

// Get recent activity
$recent_activity_query = "SELECT * FROM transactions WHERE user_id = ? ORDER BY date DESC, id DESC LIMIT 5";
$stmt = $conn->prepare($recent_activity_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_activity = $stmt->get_result();

// Get monthly summary for chart
$monthly_summary_query = "SELECT 
                            DATE_FORMAT(date, '%Y-%m') as month,
                            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
                            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
                          FROM transactions 
                          WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                          GROUP BY DATE_FORMAT(date, '%Y-%m')
                          ORDER BY month ASC";

$stmt = $conn->prepare($monthly_summary_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$monthly_summary = $stmt->get_result();

$chart_labels = [];
$income_data = [];
$expense_data = [];

while ($row = $monthly_summary->fetch_assoc()) {
    $chart_labels[] = date('M Y', strtotime($row['month'] . '-01'));
    $income_data[] = $row['income'];
    $expense_data[] = $row['expense'];
}

// Get category breakdown for pie chart
$category_breakdown_query = "SELECT 
                              IFNULL(category, 'Tidak Berkategori') as category, 
                              SUM(amount) as total 
                            FROM transactions 
                            WHERE user_id = ? AND type = 'expense' AND MONTH(date) = ? AND YEAR(date) = ?
                            GROUP BY category
                            ORDER BY total DESC";

$stmt = $conn->prepare($category_breakdown_query);
$stmt->bind_param("iii", $user_id, $filter_month, $filter_year);
$stmt->execute();
$category_breakdown = $stmt->get_result();

$pie_labels = [];
$pie_data = [];
$pie_colors = [
    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
    '#5a5c69', '#6610f2', '#6f42c1', '#fd7e14', '#20c9a6'
];

$color_index = 0;
while ($row = $category_breakdown->fetch_assoc()) {
    $pie_labels[] = $row['category'];
    $pie_data[] = $row['total'];
    $color_index++;
}

// Check if category column exists in transactions table
$result = $conn->query("SHOW COLUMNS FROM transactions LIKE 'category'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE transactions ADD COLUMN category VARCHAR(50) NULL";
    $conn->query($sql);
}

// Check if categories table exists and create it if it doesn't
$result = $conn->query("SHOW TABLES LIKE 'categories'");
if ($result->num_rows == 0) {
    $sql = "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY unique_category (user_id, name)
    )";
    $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistem Manajemen Keuangan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 56px;
        }
        .navbar-brand {
            font-weight: 700;
        }
        .sidebar {
            position: fixed;
            top: 56px;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 48px 0 0;
            box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
            background-color: #4e73df;
        }
        .sidebar-sticky {
            position: relative;
            top: 0;
            height: calc(100vh - 48px);
            padding-top: .5rem;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .sidebar .nav-link {
            font-weight: 500;
            color: white;
            padding: 0.75rem 1rem;
            margin: 0.2rem 0.5rem;
            border-radius: 0.5rem;
        }
        .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.2);
        }
        .sidebar .nav-link i {
            margin-right: 0.5rem;
        }
        .card {
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 1.5rem;
        }
        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
            padding: 1rem 1.25rem;
        }
        .summary-card {
            border-left: 0.25rem solid;
        }
        .summary-income {
            border-left-color: #1cc88a;
        }
        .summary-expense {
            border-left-color: #e74a3b;
        }
        .summary-balance {
            border-left-color: #4e73df;
        }
        .text-income {
            color: #1cc88a;
        }
        .text-expense {
            color: #e74a3b;
        }
        .text-balance {
            color: #4e73df;
        }
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2e59d9;
        }
        .btn-success {
            background-color: #1cc88a;
            border-color: #1cc88a;
        }
        .btn-success:hover {
            background-color: #17a673;
            border-color: #17a673;
        }
        .btn-danger {
            background-color: #e74a3b;
            border-color: #e74a3b;
        }
        .btn-danger:hover {
            background-color: #be2617;
            border-color: #be2617;
        }
        .badge-income {
            background-color: #1cc88a;
        }
        .badge-expense {
            background-color: #e74a3b;
        }
        .main-content {
            margin-left: 225px;
            padding: 1.5rem;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .activity-timeline {
            position: relative;
            padding-left: 45px;
        }
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: #e3e6f0;
        }
        .activity-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        .activity-item:last-child {
            padding-bottom: 0;
        }
        .activity-badge {
            position: absolute;
            left: -45px;
            top: 0;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        .activity-content {
            background-color: white;
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.05);
        }
        @media (max-width: 768px) {
            .sidebar {
                top: 0;
                padding-top: 106px;
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-wallet me-2"></i>FinTrack
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($username); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                        <li><a class="dropdown-item" href="pengaturan.php"><i class="fas fa-user-cog me-2"></i>Pengaturan</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
    <div class="position-sticky sidebar-sticky">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="laporan.php">
                    <i class="fas fa-chart-pie"></i>
                    Laporan
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="pengaturan.php">
                    <i class="fas fa-cog"></i>
                    Pengaturan
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- Main Content -->
<main class="main-content">
    <div class="container-fluid">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="fas fa-plus me-1"></i> Tambah Transaksi
            </button>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row">
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card summary-card summary-income h-100">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-xs font-weight-bold text-uppercase mb-1">Total Pemasukan</div>
                                <div class="h5 mb-0 font-weight-bold text-income">Rp <?php echo number_format($total_income, 0, ',', '.'); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-arrow-up fa-2x text-income"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card summary-card summary-expense h-100">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-xs font-weight-bold text-uppercase mb-1">Total Pengeluaran</div>
                                <div class="h5 mb-0 font-weight-bold text-expense">Rp <?php echo number_format($total_expense, 0, ',', '.'); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-arrow-down fa-2x text-expense"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card summary-card summary-balance h-100">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-xs font-weight-bold text-uppercase mb-1">Saldo</div>
                                <div class="h5 mb-0 font-weight-bold text-balance">Rp <?php echo number_format($balance, 0, ',', '.'); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-wallet fa-2x text-balance"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row">
            <!-- Monthly Income vs Expense Chart -->
            <div class="col-xl-8 col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Tren Pemasukan & Pengeluaran</h6>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="chartOptions" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="chartOptions">
                                <li><a class="dropdown-item" href="#" id="viewBarChart">Tampilan Batang</a></li>
                                <li><a class="dropdown-item" href="#" id="viewLineChart">Tampilan Garis</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="laporan.php">Lihat Laporan Lengkap</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Expense Categories Pie Chart -->
            <div class="col-xl-4 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">Distribusi Pengeluaran</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="categoryPieChart"></canvas>
                        </div>
                        <?php if (empty($pie_data)): ?>
                            <div class="text-center mt-4 text-muted">
                                <p>Tidak ada data pengeluaran untuk periode ini</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity and Transactions -->
        <div class="row">
            <!-- Recent Activity -->
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">Aktivitas Terbaru</h6>
                    </div>
                    <div class="card-body">
                        <div class="activity-timeline">
                            <?php if ($recent_activity->num_rows > 0): ?>
                                <?php while ($activity = $recent_activity->fetch_assoc()): ?>
                                    <div class="activity-item">
                                        <div class="activity-badge" style="background-color: <?php echo ($activity['type'] == 'income') ? '#1cc88a' : '#e74a3b'; ?>">
                                            <i class="fas <?php echo ($activity['type'] == 'income') ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></h6>
                                                    <p class="small text-muted mb-0">
                                                        <?php echo date('d M Y', strtotime($activity['date'])); ?>
                                                        <?php if (!empty($activity['category'])): ?>
                                                            <span class="badge bg-primary"><?php echo htmlspecialchars($activity['category']); ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="<?php echo ($activity['type'] == 'income') ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo ($activity['type'] == 'income') ? '+' : '-'; ?>
                                                    Rp <?php echo number_format($activity['amount'], 0, ',', '.'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                    <p>Belum ada aktivitas transaksi</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">Daftar Transaksi</h6>

                        <!-- Filter Form -->
                        <form method="get" class="row g-2 align-items-center">
                            <div class="col-auto">
                                <select name="month" class="form-select form-select-sm">
                                    <option value="">Semua Bulan</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($filter_month == $i) ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <select name="year" class="form-select form-select-sm">
                                    <?php
                                    $current_year = date('Y');
                                    for ($i = $current_year; $i >= $current_year - 5; $i--):
                                        ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($filter_year == $i) ? 'selected' : ''; ?>>
                                            <?php echo $i; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <select name="type" class="form-select form-select-sm">
                                    <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>Semua Tipe</option>
                                    <option value="income" <?php echo ($filter_type == 'income') ? 'selected' : ''; ?>>Pemasukan</option>
                                    <option value="expense" <?php echo ($filter_type == 'expense') ? 'selected' : ''; ?>>Pengeluaran</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <select name="category" class="form-select form-select-sm">
                                    <option value="all" <?php echo ($filter_category == 'all') ? 'selected' : ''; ?>>Semua Kategori</option>
                                    <option value="uncategorized" <?php echo ($filter_category == 'uncategorized') ? 'selected' : ''; ?>>Tidak Berkategori</option>
                                    <?php foreach ($all_categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo ($filter_category == $category) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                            </div>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="transactionsTable">
                                <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Tipe</th>
                                    <th>Kategori</th>
                                    <th>Deskripsi</th>
                                    <th>Jumlah</th>
                                    <th>Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($transactions->num_rows > 0): ?>
                                    <?php while ($row = $transactions->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                                            <td>
                                                <?php if ($row['type'] == 'income'): ?>
                                                    <span class="badge bg-success">Pemasukan</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Pengeluaran</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['category'])): ?>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($row['category']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td class="<?php echo ($row['type'] == 'income') ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo ($row['type'] == 'income') ? '+' : '-'; ?>
                                                Rp <?php echo number_format($row['amount'], 0, ',', '.'); ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary me-1 edit-transaction"
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-type="<?php echo $row['type']; ?>"
                                                        data-amount="<?php echo $row['amount']; ?>"
                                                        data-description="<?php echo htmlspecialchars($row['description']); ?>"
                                                        data-date="<?php echo $row['date']; ?>"
                                                        data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                                                        data-bs-toggle="modal" data-bs-target="#editTransactionModal">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus transaksi ini?');">
                                                    <input type="hidden" name="transaction_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="delete_transaction" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">Tidak ada transaksi yang ditemukan.</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTransactionModalLabel">Tambah Transaksi Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="dashboard.php" method="post">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="type" class="form-label">Tipe Transaksi</label>
                        <select class="form-select" id="type" name="type" required>
                            <option value="income">Pemasukan</option>
                            <option value="expense">Pengeluaran</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Jumlah (Rp)</label>
                        <input type="number" class="form-control" id="amount" name="amount" min="0" step="1000" required>
                    </div>
                    <div class="mb-3">
                        <label for="category" class="form-label">Kategori</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Deskripsi</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="date" class="form-label">Tanggal</label>
                        <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="add_transaction" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Transaction Modal -->
<div class="modal fade" id="editTransactionModal" tabindex="-1" aria-labelledby="editTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTransactionModalLabel">Edit Transaksi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="edit_transaction.php" method="post">
                <input type="hidden" name="transaction_id" id="edit_transaction_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">Tipe Transaksi</label>
                        <select class="form-select" id="edit_type" name="type" required>
                            <option value="income">Pemasukan</option>
                            <option value="expense">Pengeluaran</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_amount" class="form-label">Jumlah (Rp)</label>
                        <input type="number" class="form-control" id="edit_amount" name="amount" min="0" step="1000" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_category" class="form-label">Kategori</label>
                        <select class="form-select" id="edit_category" name="category">
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Deskripsi</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_date" class="form-label">Tanggal</label>
                        <input type="date" class="form-control" id="edit_date" name="date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit_transaction" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#transactionsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json'
            },
            pageLength: 10,
            ordering: true,
            responsive: true
        });

        // Monthly Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        let monthlyChart;

        function initMonthlyChart(type = 'bar') {
            if (monthlyChart) {
                monthlyChart.destroy();
            }

            monthlyChart = new Chart(monthlyCtx, {
                type: type,
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [
                        {
                            label: 'Pemasukan',
                            data: <?php echo json_encode($income_data); ?>,
                            backgroundColor: 'rgba(28, 200, 138, 0.2)',
                            borderColor: 'rgba(28, 200, 138, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        },
                        {
                            label: 'Pengeluaran',
                            data: <?php echo json_encode($expense_data); ?>,
                            backgroundColor: 'rgba(231, 74, 59, 0.2)',
                            borderColor: 'rgba(231, 74, 59, 1)',
                            borderWidth: 2,
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value, index, values) {
                                    return 'Rp ' + value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                                }
                            }
                        }
                    }
                }
            });
        }

        // Initialize monthly chart
        initMonthlyChart('bar');

        // Chart type toggle
        document.getElementById('viewBarChart').addEventListener('click', function(e) {
            e.preventDefault();
            initMonthlyChart('bar');
        });

        document.getElementById('viewLineChart').addEventListener('click', function(e) {
            e.preventDefault();
            initMonthlyChart('line');
        });

        // Category Pie Chart
        const categoryCtx = document.getElementById('categoryPieChart').getContext('2d');
        const pieData = <?php echo json_encode($pie_data); ?>;

        if (pieData.length > 0) {
            const categoryPieChart = new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($pie_labels); ?>,
                    datasets: [{
                        data: pieData,
                        backgroundColor: <?php echo json_encode($pie_colors); ?>,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(context.parsed);
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Edit transaction modal
        $('.edit-transaction').click(function() {
            const id = $(this).data('id');
            const type = $(this).data('type');
            const amount = $(this).data('amount');
            const description = $(this).data('description');
            const date = $(this).data('date');
            const category = $(this).data('category');

            $('#edit_transaction_id').val(id);
            $('#edit_type').val(type);
            $('#edit_amount').val(amount);
            $('#edit_description').val(description);
            $('#edit_date').val(date);
            $('#edit_category').val(category);
        });
    });
</script>
</body>
</html>
