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

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t'); // Last day of current month
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';

// Get categories for filter dropdown
$categories_query = "SELECT DISTINCT category FROM transactions WHERE user_id = ? ORDER BY category";
$stmt = $conn->prepare($categories_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categories_result = $stmt->get_result();
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    if (!empty($row['category'])) {
        $categories[] = $row['category'];
    }
}

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
    $query .= " AND category = ?";
    $params[] = $filter_category;
    $types .= "s";
}

$query .= " AND date BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;
$types .= "ss";

$query .= " ORDER BY date DESC";

// Prepare and execute the query
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result();

// Calculate summary
$summary_query = "SELECT 
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense
                  FROM transactions 
                  WHERE user_id = ? AND date BETWEEN ? AND ?";

$summary_stmt = $conn->prepare($summary_query);
$summary_stmt->bind_param("iss", $user_id, $start_date, $end_date);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
$summary = $summary_result->fetch_assoc();

$total_income = $summary['total_income'] ?? 0;
$total_expense = $summary['total_expense'] ?? 0;
$balance = $total_income - $total_expense;

// Get monthly data for charts (last 6 months)
$chart_data_query = "SELECT 
                        DATE_FORMAT(date, '%Y-%m') as month,
                        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
                        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
                     FROM transactions 
                     WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                     GROUP BY DATE_FORMAT(date, '%Y-%m')
                     ORDER BY month ASC";

$chart_stmt = $conn->prepare($chart_data_query);
$chart_stmt->bind_param("i", $user_id);
$chart_stmt->execute();
$chart_result = $chart_stmt->get_result();

$months = [];
$income_data = [];
$expense_data = [];

while ($row = $chart_result->fetch_assoc()) {
    $month_name = date('M Y', strtotime($row['month'] . '-01'));
    $months[] = $month_name;
    $income_data[] = $row['income'];
    $expense_data[] = $row['expense'];
}

// Get category breakdown for pie chart
$category_query = "SELECT 
                    category, 
                    SUM(amount) as total 
                   FROM transactions 
                   WHERE user_id = ? AND type = 'expense' AND date BETWEEN ? AND ?
                   GROUP BY category
                   ORDER BY total DESC";

$category_stmt = $conn->prepare($category_query);
$category_stmt->bind_param("iss", $user_id, $start_date, $end_date);
$category_stmt->execute();
$category_result = $category_stmt->get_result();

$category_labels = [];
$category_data = [];
$category_colors = [];

// Predefined colors for categories
$colors = [
    '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
    '#5a5c69', '#6610f2', '#6f42c1', '#fd7e14', '#20c9a6'
];

$color_index = 0;
while ($row = $category_result->fetch_assoc()) {
    if (!empty($row['category'])) {
        $category_labels[] = $row['category'];
        $category_data[] = $row['total'];
        $category_colors[] = $colors[$color_index % count($colors)];
        $color_index++;
    } else {
        $category_labels[] = 'Tidak Berkategori';
        $category_data[] = $row['total'];
        $category_colors[] = '#858796';
    }
}

// Get daily spending trend for the selected period
$daily_trend_query = "SELECT 
                        date, 
                        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
                      FROM transactions 
                      WHERE user_id = ? AND date BETWEEN ? AND ?
                      GROUP BY date
                      ORDER BY date ASC";

$daily_stmt = $conn->prepare($daily_trend_query);
$daily_stmt->bind_param("iss", $user_id, $start_date, $end_date);
$daily_stmt->execute();
$daily_result = $daily_stmt->get_result();

$daily_dates = [];
$daily_expenses = [];

while ($row = $daily_result->fetch_assoc()) {
    $daily_dates[] = date('d M', strtotime($row['date']));
    $daily_expenses[] = $row['expense'];
}

// Calculate budget status if budgets are set
$budget_query = "SELECT 
                    category, 
                    budget_amount,
                    (SELECT SUM(amount) FROM transactions WHERE user_id = ? AND type = 'expense' AND category = b.category AND MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())) as spent
                 FROM budgets b
                 WHERE user_id = ?";

$budget_stmt = $conn->prepare($budget_query);
$budget_stmt->bind_param("ii", $user_id, $user_id);
$budget_stmt->execute();
$budget_result = $budget_stmt->get_result();

$budget_categories = [];
$budget_amounts = [];
$budget_spent = [];
$budget_remaining = [];

while ($row = $budget_result->fetch_assoc()) {
    $budget_categories[] = $row['category'];
    $budget_amounts[] = $row['budget_amount'];
    $spent = $row['spent'] ?? 0;
    $budget_spent[] = $spent;
    $budget_remaining[] = max(0, $row['budget_amount'] - $spent);
}

// Get top 5 expenses
$top_expenses_query = "SELECT 
                        description, 
                        amount, 
                        date,
                        category
                       FROM transactions 
                       WHERE user_id = ? AND type = 'expense' AND date BETWEEN ? AND ?
                       ORDER BY amount DESC
                       LIMIT 5";

$top_stmt = $conn->prepare($top_expenses_query);
$top_stmt->bind_param("iss", $user_id, $start_date, $end_date);
$top_stmt->execute();
$top_expenses = $top_stmt->get_result();

// Calculate savings rate
$savings_rate = ($total_income > 0) ? (($total_income - $total_expense) / $total_income) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan - Sistem Manajemen Keuangan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
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
        .summary-savings {
            border-left-color: #f6c23e;
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
        .text-savings {
            color: #f6c23e;
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
        .main-content {
            margin-left: 225px;
            padding: 1.5rem;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .progress {
            height: 0.8rem;
            margin-bottom: 0.5rem;
        }
        .daterangepicker td.active {
            background-color: #4e73df;
        }
        .daterangepicker td.active:hover {
            background-color: #2e59d9;
        }
        .budget-progress .progress-bar {
            transition: width 1s ease-in-out;
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
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="laporan.php">
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
            <h1 class="h3 mb-0 text-gray-800">Laporan Keuangan</h1>
            <div class="btn-group">
                <button type="button" class="btn btn-primary" id="exportPDF">
                    <i class="fas fa-file-pdf me-1"></i> Ekspor PDF
                </button>
                <button type="button" class="btn btn-success" id="exportExcel">
                    <i class="fas fa-file-excel me-1"></i> Ekspor Excel
                </button>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Laporan</h5>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <label for="daterange" class="form-label">Rentang Tanggal</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            <input type="text" class="form-control" id="daterange" name="daterange" value="<?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>">
                            <input type="hidden" name="start_date" id="start_date" value="<?php echo $start_date; ?>">
                            <input type="hidden" name="end_date" id="end_date" value="<?php echo $end_date; ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="type" class="form-label">Tipe Transaksi</label>
                        <select name="type" id="type" class="form-select">
                            <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>Semua Tipe</option>
                            <option value="income" <?php echo ($filter_type == 'income') ? 'selected' : ''; ?>>Pemasukan</option>
                            <option value="expense" <?php echo ($filter_type == 'expense') ? 'selected' : ''; ?>>Pengeluaran</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="category" class="form-label">Kategori</label>
                        <select name="category" id="category" class="form-select">
                            <option value="all" <?php echo ($filter_category == 'all') ? 'selected' : ''; ?>>Semua Kategori</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" <?php echo ($filter_category == $category) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i> Terapkan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row">
            <div class="col-xl-3 col-md-6 mb-4">
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

            <div class="col-xl-3 col-md-6 mb-4">
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

            <div class="col-xl-3 col-md-6 mb-4">
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

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card summary-card summary-savings h-100">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-xs font-weight-bold text-uppercase mb-1">Tingkat Tabungan</div>
                                <div class="h5 mb-0 font-weight-bold text-savings"><?php echo number_format($savings_rate, 1); ?>%</div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-piggy-bank fa-2x text-savings"></i>
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
                        <h6 class="m-0 font-weight-bold text-primary">Tren Pemasukan & Pengeluaran (6 Bulan Terakhir)</h6>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="chartOptions" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="chartOptions">
                                <li><a class="dropdown-item" href="#" id="viewBarChart">Tampilan Batang</a></li>
                                <li><a class="dropdown-item" href="#" id="viewLineChart">Tampilan Garis</a></li>
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
                        <h6 class="m-0 font-weight-bold text-primary">Distribusi Pengeluaran per Kategori</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="categoryPieChart"></canvas>
                        </div>
                        <?php if (empty($category_data)): ?>
                            <div class="text-center mt-4 text-muted">
                                <p>Tidak ada data pengeluaran untuk periode ini</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Spending Trend -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">Tren Pengeluaran Harian</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="dailyTrendChart"></canvas>
                        </div>
                        <?php if (empty($daily_expenses)): ?>
                            <div class="text-center mt-4 text-muted">
                                <p>Tidak ada data pengeluaran harian untuk periode ini</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Budget Status and Top Expenses -->
        <div class="row">
            <!-- Budget Status -->
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">Status Anggaran Bulan Ini</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($budget_categories)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                <p>Anda belum mengatur anggaran. Atur anggaran di halaman Pengaturan.</p>
                                <a href="pengaturan.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-cog me-1"></i> Atur Anggaran
                                </a>
                            </div>
                        <?php else: ?>
                            <?php for ($i = 0; $i < count($budget_categories); $i++): ?>
                                <?php
                                $percentage = ($budget_amounts[$i] > 0) ? ($budget_spent[$i] / $budget_amounts[$i]) * 100 : 0;
                                $progress_class = ($percentage < 70) ? 'bg-success' : (($percentage < 90) ? 'bg-warning' : 'bg-danger');
                                ?>
                                <div class="mb-3 budget-progress">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span><?php echo htmlspecialchars($budget_categories[$i]); ?></span>
                                        <span>Rp <?php echo number_format($budget_spent[$i], 0, ',', '.'); ?> / Rp <?php echo number_format($budget_amounts[$i], 0, ',', '.'); ?></span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar <?php echo $progress_class; ?>" role="progressbar" style="width: <?php echo min(100, $percentage); ?>%" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted"><?php echo number_format($percentage, 1); ?>% terpakai</small>
                                </div>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Expenses -->
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold text-primary">5 Pengeluaran Terbesar</h6>
                    </div>
                    <div class="card-body">
                        <?php if ($top_expenses->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Deskripsi</th>
                                        <th>Kategori</th>
                                        <th>Tanggal</th>
                                        <th>Jumlah</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php while ($row = $top_expenses->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td>
                                                <?php if (!empty($row['category'])): ?>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($row['category']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Tidak Berkategori</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                                            <td class="text-danger">Rp <?php echo number_format($row['amount'], 0, ',', '.'); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                <p>Tidak ada data pengeluaran untuk periode ini</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Daftar Transaksi</h6>
                <span class="badge bg-primary"><?php echo $transactions->num_rows; ?> transaksi</span>
            </div>
            <div class="card-body">
                <?php if ($transactions->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="transactionsTable">
                            <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Tipe</th>
                                <th>Kategori</th>
                                <th>Deskripsi</th>
                                <th>Jumlah</th>
                            </tr>
                            </thead>
                            <tbody>
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
                                        Rp <?php echo number_format($row['amount'], 0, ',', '.'); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <p>Tidak ada transaksi yang ditemukan untuk filter yang dipilih</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
    // Date Range Picker
    $(function() {
        $('#daterange').daterangepicker({
            opens: 'left',
            locale: {
                format: 'DD/MM/YYYY'
            },
            ranges: {
                'Hari Ini': [moment(), moment()],
                'Kemarin': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                '7 Hari Terakhir': [moment().subtract(6, 'days'), moment()],
                '30 Hari Terakhir': [moment().subtract(29, 'days'), moment()],
                'Bulan Ini': [moment().startOf('month'), moment().endOf('month')],
                'Bulan Lalu': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                'Tahun Ini': [moment().startOf('year'), moment().endOf('year')]
            }
        }, function(start, end, label) {
            $('#start_date').val(start.format('YYYY-MM-DD'));
            $('#end_date').val(end.format('YYYY-MM-DD'));
        });
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
                labels: <?php echo json_encode($months); ?>,
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

    // Category Pie Chart
    const categoryCtx = document.getElementById('categoryPieChart').getContext('2d');
    const categoryPieChart = new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($category_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($category_data); ?>,
                backgroundColor: <?php echo json_encode($category_colors); ?>,
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

    // Daily Trend Chart
    const dailyCtx = document.getElementById('dailyTrendChart').getContext('2d');
    const dailyTrendChart = new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($daily_dates); ?>,
            datasets: [{
                label: 'Pengeluaran Harian',
                data: <?php echo json_encode($daily_expenses); ?>,
                backgroundColor: 'rgba(78, 115, 223, 0.2)',
                borderColor: 'rgba(78, 115, 223, 1)',
                borderWidth: 2,
                pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: 'rgba(78, 115, 223, 1)',
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
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

    // Export to PDF
    document.getElementById('exportPDF').addEventListener('click', function() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');

        // Add title
        doc.setFontSize(18);
        doc.text('Laporan Keuangan', 105, 15, { align: 'center' });

        // Add period
        doc.setFontSize(12);
        doc.text('Periode: ' + '<?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>', 105, 25, { align: 'center' });

        // Add summary
        doc.setFontSize(14);
        doc.text('Ringkasan Keuangan', 14, 40);

        doc.setFontSize(10);
        doc.text('Total Pemasukan: Rp ' + '<?php echo number_format($total_income, 0, ',', '.'); ?>', 14, 50);
        doc.text('Total Pengeluaran: Rp ' + '<?php echo number_format($total_expense, 0, ',', '.'); ?>', 14, 57);
        doc.text('Saldo: Rp ' + '<?php echo number_format($balance, 0, ',', '.'); ?>', 14, 64);
        doc.text('Tingkat Tabungan: ' + '<?php echo number_format($savings_rate, 1); ?>%', 14, 71);

        // Add transactions table
        doc.setFontSize(14);
        doc.text('Daftar Transaksi', 14, 85);

        // Convert table data
        const tableData = [];
        const tableHeaders = [['Tanggal', 'Tipe', 'Kategori', 'Deskripsi', 'Jumlah']];

        <?php
        // Reset the result pointer
        $transactions->data_seek(0);
        while ($row = $transactions->fetch_assoc()):
        ?>
        tableData.push([
            '<?php echo date('d/m/Y', strtotime($row['date'])); ?>',
            '<?php echo ($row['type'] == 'income') ? 'Pemasukan' : 'Pengeluaran'; ?>',
            '<?php echo !empty($row['category']) ? htmlspecialchars($row['category']) : '-'; ?>',
            '<?php echo htmlspecialchars($row['description']); ?>',
            'Rp <?php echo number_format($row['amount'], 0, ',', '.'); ?>'
        ]);
        <?php endwhile; ?>

        // Generate table
        doc.autoTable({
            head: tableHeaders,
            body: tableData,
            startY: 90,
            theme: 'grid',
            styles: { fontSize: 8 },
            headStyles: { fillColor: [78, 115, 223] }
        });

        // Save PDF
        doc.save('Laporan_Keuangan_<?php echo date('d-m-Y'); ?>.pdf');
    });

    // Export to Excel
    document.getElementById('exportExcel').addEventListener('click', function() {
        // Prepare data
        const data = [
            ['Laporan Keuangan'],
            ['Periode: <?php echo date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)); ?>'],
            [],
            ['Ringkasan Keuangan'],
            ['Total Pemasukan', 'Rp <?php echo number_format($total_income, 0, ',', '.'); ?>'],
            ['Total Pengeluaran', 'Rp <?php echo number_format($total_expense, 0, ',', '.'); ?>'],
            ['Saldo', 'Rp <?php echo number_format($balance, 0, ',', '.'); ?>'],
            ['Tingkat Tabungan', '<?php echo number_format($savings_rate, 1); ?>%'],
            [],
            ['Daftar Transaksi'],
            ['Tanggal', 'Tipe', 'Kategori', 'Deskripsi', 'Jumlah']
        ];

        <?php
        // Reset the result pointer
        $transactions->data_seek(0);
        while ($row = $transactions->fetch_assoc()):
        ?>
        data.push([
            '<?php echo date('d/m/Y', strtotime($row['date'])); ?>',
            '<?php echo ($row['type'] == 'income') ? 'Pemasukan' : 'Pengeluaran'; ?>',
            '<?php echo !empty($row['category']) ? htmlspecialchars($row['category']) : '-'; ?>',
            '<?php echo htmlspecialchars($row['description']); ?>',
            'Rp <?php echo number_format($row['amount'], 0, ',', '.'); ?>'
        ]);
        <?php endwhile; ?>

        // Create workbook
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);

        // Add worksheet to workbook
        XLSX.utils.book_append_sheet(wb, ws, 'Laporan Keuangan');

        // Save Excel file
        XLSX.writeFile(wb, 'Laporan_Keuangan_<?php echo date('d-m-Y'); ?>.xlsx');
    });
</script>
</body>
</html>
