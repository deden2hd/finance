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

// Get user data
$stmt = $conn->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Process profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();

    if (password_verify($current_password, $user_data['password'])) {
        // Check if username already exists (if changed)
        if ($new_username !== $username) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $new_username, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $profile_error = "Username sudah digunakan oleh pengguna lain.";
            }
        }

        // Check if email already exists (if changed)
        if (!isset($profile_error) && $new_email !== $user['email']) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $new_email, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $profile_error = "Email sudah digunakan oleh pengguna lain.";
            }
        }

        // Update profile if no errors
        if (!isset($profile_error)) {
            // If password is being changed
            if (!empty($new_password)) {
                if ($new_password === $confirm_password) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $new_username, $new_email, $hashed_password, $user_id);
                } else {
                    $profile_error = "Password baru dan konfirmasi password tidak cocok.";
                }
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $new_username, $new_email, $user_id);
            }

            if (!isset($profile_error) && $stmt->execute()) {
                $_SESSION['username'] = $new_username;
                $profile_success = "Profil berhasil diperbarui.";

                // Refresh user data
                $stmt = $conn->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
            } else {
                $profile_error = "Gagal memperbarui profil: " . $conn->error;
            }
        }
    } else {
        $profile_error = "Password saat ini tidak valid.";
    }
}

// Process category management
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);

    if (!empty($category_name)) {
        // Check if category already exists
        $stmt = $conn->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ?");
        $stmt->bind_param("is", $user_id, $category_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $category_error = "Kategori dengan nama yang sama sudah ada.";
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (user_id, name) VALUES (?, ?)");
            $stmt->bind_param("is", $user_id, $category_name);

            if ($stmt->execute()) {
                $category_success = "Kategori berhasil ditambahkan.";
            } else {
                $category_error = "Gagal menambahkan kategori: " . $conn->error;
            }
        }
    } else {
        $category_error = "Nama kategori tidak boleh kosong.";
    }
}

// Process category deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_category'])) {
    $category_id = $_POST['category_id'];

    // First update transactions to remove this category
    $stmt = $conn->prepare("UPDATE transactions SET category = NULL WHERE user_id = ? AND category = (SELECT name FROM categories WHERE id = ? AND user_id = ?)");
    $stmt->bind_param("iii", $user_id, $category_id, $user_id);
    $stmt->execute();

    // Then delete the category
    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $category_id, $user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $category_success = "Kategori berhasil dihapus.";
    } else {
        $category_error = "Gagal menghapus kategori.";
    }
}

// Process budget management
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_budget'])) {
    $budget_category = trim($_POST['budget_category']);
    $budget_amount = floatval($_POST['budget_amount']);

    if (!empty($budget_category) && $budget_amount > 0) {
        // Check if budget already exists for this category
        $stmt = $conn->prepare("SELECT id FROM budgets WHERE user_id = ? AND category = ?");
        $stmt->bind_param("is", $user_id, $budget_category);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            // Update existing budget
            $stmt = $conn->prepare("UPDATE budgets SET budget_amount = ? WHERE user_id = ? AND category = ?");
            $stmt->bind_param("dis", $budget_amount, $user_id, $budget_category);
        } else {
            // Insert new budget
            $stmt = $conn->prepare("INSERT INTO budgets (user_id, category, budget_amount) VALUES (?, ?, ?)");
            $stmt->bind_param("isd", $user_id, $budget_category, $budget_amount);
        }

        if ($stmt->execute()) {
            $budget_success = "Anggaran berhasil disimpan.";
        } else {
            $budget_error = "Gagal menyimpan anggaran: " . $conn->error;
        }
    } else {
        $budget_error = "Kategori dan jumlah anggaran harus diisi dengan benar.";
    }
}

// Process budget deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_budget'])) {
    $budget_id = $_POST['budget_id'];

    $stmt = $conn->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $budget_id, $user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $budget_success = "Anggaran berhasil dihapus.";
    } else {
        $budget_error = "Gagal menghapus anggaran.";
    }
}

// Get user categories
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? ORDER BY name");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$categories_result = $stmt->get_result();

// Get user budgets
$stmt = $conn->prepare("SELECT id, category, budget_amount FROM budgets WHERE user_id = ? ORDER BY category");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$budgets_result = $stmt->get_result();

// Create categories table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_category (user_id, name)
)";
$conn->query($sql);

// Create budgets table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    budget_amount DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_budget (user_id, category)
)";
$conn->query($sql);

// Add category column to transactions table if it doesn't exist
$result = $conn->query("SHOW COLUMNS FROM transactions LIKE 'category'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE transactions ADD COLUMN category VARCHAR(50) NULL";
    $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan - Sistem Manajemen Keuangan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2e59d9;
        }
        .main-content {
            margin-left: 225px;
            padding: 1.5rem;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #5a5c69;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #4e73df;
            border-bottom: 2px solid #4e73df;
            background-color: transparent;
        }
        .form-control:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }
        .table-hover tbody tr:hover {
            background-color: rgba(78, 115, 223, 0.05);
        }
        .badge-budget {
            background-color: #1cc88a;
            color: white;
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
                <a class="nav-link" href="laporan.php">
                    <i class="fas fa-chart-pie"></i>
                    Laporan
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="pengaturan.php">
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
            <h1 class="h3 mb-0 text-gray-800">Pengaturan</h1>
        </div>

        <!-- Settings Tabs -->
        <div class="card">
            <div class="card-header p-0">
                <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">
                            <i class="fas fa-user me-2"></i>Profil
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab" aria-controls="categories" aria-selected="false">
                            <i class="fas fa-tags me-2"></i>Kategori
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="budget-tab" data-bs-toggle="tab" data-bs-target="#budget" type="button" role="tab" aria-controls="budget" aria-selected="false">
                            <i class="fas fa-money-bill-wave me-2"></i>Anggaran
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" data-bs-target="#appearance" type="button" role="tab" aria-controls="appearance" aria-selected="false">
                            <i class="fas fa-paint-brush me-2"></i>Tampilan
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="settingsTabsContent">
                    <!-- Profile Tab -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                        <h5 class="mb-4">Informasi Profil</h5>

                        <?php if (isset($profile_success)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?php echo $profile_success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($profile_error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo $profile_error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="needs-validation" novalidate>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                    <div class="invalid-feedback">
                                        Username harus diisi.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    <div class="invalid-feedback">
                                        Email harus diisi dengan benar.
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label for="current_password" class="form-label">Password Saat Ini</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    <div class="invalid-feedback">
                                        Password saat ini harus diisi.
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="new_password" class="form-label">Password Baru (kosongkan jika tidak ingin mengubah)</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                </div>
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label">Konfirmasi Password Baru</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                    <div class="invalid-feedback">
                                        Konfirmasi password tidak cocok.
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <p class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Akun dibuat pada: <?php echo date('d F Y H:i', strtotime($user['created_at'])); ?>
                                    </p>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Categories Tab -->
                    <div class="tab-pane fade" id="categories" role="tabpanel" aria-labelledby="categories-tab">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-4">Kategori Transaksi</h5>

                                <?php if (isset($category_success)): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        <i class="fas fa-check-circle me-2"></i><?php echo $category_success; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($category_error)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $category_error; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>

                                <form method="post" class="mb-4">
                                    <div class="input-group mb-3">
                                        <input type="text" class="form-control" placeholder="Nama Kategori Baru" name="category_name" required>
                                        <button class="btn btn-primary" type="submit" name="add_category">
                                            <i class="fas fa-plus me-1"></i> Tambah
                                        </button>
                                    </div>
                                </form>

                                <?php if ($categories_result->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                            <tr>
                                                <th>Nama Kategori</th>
                                                <th class="text-end">Aksi</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php while ($category = $categories_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($category['name']); ?></td>
                                                    <td class="text-end">
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus kategori ini?');">
                                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                            <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>Belum ada kategori yang ditambahkan.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h5 class="card-title">
                                            <i class="fas fa-lightbulb text-warning me-2"></i>Tips Penggunaan Kategori
                                        </h5>
                                        <p class="card-text">Kategori membantu Anda mengorganisir transaksi dan melihat pola pengeluaran.</p>
                                        <ul class="list-group list-group-flush mb-3">
                                            <li class="list-group-item bg-transparent">Buat kategori untuk jenis pengeluaran utama (Makanan, Transportasi, dll)</li>
                                            <li class="list-group-item bg-transparent">Gunakan kategori yang konsisten untuk analisis yang lebih baik</li>
                                            <li class="list-group-item bg-transparent">Sesuaikan kategori dengan kebutuhan dan gaya hidup Anda</li>
                                        </ul>
                                        <p class="card-text">Contoh kategori yang umum digunakan:</p>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-primary">Makanan</span>
                                            <span class="badge bg-primary">Transportasi</span>
                                            <span class="badge bg-primary">Belanja</span>
                                            <span class="badge bg-primary">Hiburan</span>
                                            <span class="badge bg-primary">Kesehatan</span>
                                            <span class="badge bg-primary">Pendidikan</span>
                                            <span class="badge bg-primary">Tagihan</span>
                                            <span class="badge bg-primary">Investasi</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Budget Tab -->
                    <div class="tab-pane fade" id="budget" role="tabpanel" aria-labelledby="budget-tab">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-4">Pengaturan Anggaran</h5>

                                <?php if (isset($budget_success)): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        <i class="fas fa-check-circle me-2"></i><?php echo $budget_success; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($budget_error)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $budget_error; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>

                                <form method="post" class="mb-4">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="budget_category" class="form-label">Kategori</label>
                                            <select class="form-select" id="budget_category" name="budget_category" required>
                                                <option value="" selected disabled>Pilih Kategori</option>
                                                <?php
                                                $categories_result->data_seek(0);
                                                while ($category = $categories_result->fetch_assoc()):
                                                    ?>
                                                    <option value="<?php echo htmlspecialchars($category['name']); ?>">
                                                        <?php echo htmlspecialchars($category['name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="budget_amount" class="form-label">Jumlah Anggaran (Rp)</label>
                                            <input type="number" class="form-control" id="budget_amount" name="budget_amount" min="0" step="1000" required>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" name="add_budget" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i> Simpan Anggaran
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <?php if ($budgets_result->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                            <tr>
                                                <th>Kategori</th>
                                                <th>Jumlah Anggaran</th>
                                                <th class="text-end">Aksi</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php while ($budget = $budgets_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($budget['category']); ?></td>
                                                    <td>Rp <?php echo number_format($budget['budget_amount'], 0, ',', '.'); ?></td>
                                                    <td class="text-end">
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus anggaran ini?');">
                                                            <input type="hidden" name="budget_id" value="<?php echo $budget['id']; ?>">
                                                            <button type="submit" name="delete_budget" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>Belum ada anggaran yang ditambahkan.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h5 class="card-title">
                                            <i class="fas fa-piggy-bank text-success me-2"></i>Manfaat Mengatur Anggaran
                                        </h5>
                                        <p class="card-text">Anggaran membantu Anda mengontrol pengeluaran dan mencapai tujuan keuangan.</p>
                                        <ul class="list-group list-group-flush mb-3">
                                            <li class="list-group-item bg-transparent">Tetapkan batas pengeluaran untuk setiap kategori</li>
                                            <li class="list-group-item bg-transparent">Pantau pengeluaran Anda terhadap anggaran</li>
                                            <li class="list-group-item bg-transparent">Identifikasi area di mana Anda bisa menghemat</li>
                                            <li class="list-group-item bg-transparent">Rencanakan keuangan Anda dengan lebih baik</li>
                                        </ul>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Tips:</strong> Mulailah dengan anggaran yang realistis berdasarkan pola pengeluaran Anda sebelumnya.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Appearance Tab -->
                    <div class="tab-pane fade" id="appearance" role="tabpanel" aria-labelledby="appearance-tab">
                        <h5 class="mb-4">Pengaturan Tampilan</h5>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h6 class="mb-0">Mode Tampilan</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" id="darkModeSwitch">
                                            <label class="form-check-label" for="darkModeSwitch">Mode Gelap</label>
                                        </div>
                                        <p class="text-muted small">Mode gelap mengurangi ketegangan mata saat menggunakan aplikasi di lingkungan dengan cahaya rendah.</p>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Format Tampilan</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="currencyFormat" class="form-label">Format Mata Uang</label>
                                            <select class="form-select" id="currencyFormat">
                                                <option value="idr" selected>Rupiah (Rp 10.000)</option>
                                                <option value="usd">Dollar ($ 10,000)</option>
                                                <option value="eur">Euro (€ 10.000)</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="dateFormat" class="form-label">Format Tanggal</label>
                                            <select class="form-select" id="dateFormat">
                                                <option value="dmy" selected>DD/MM/YYYY</option>
                                                <option value="mdy">MM/DD/YYYY</option>
                                                <option value="ymd">YYYY/MM/DD</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Tema Warna</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button class="btn btn-primary color-theme-btn" data-theme="primary" style="background-color: #4e73df; border-color: #4e73df;"></button>
                                            <button class="btn btn-success color-theme-btn" data-theme="success" style="background-color: #1cc88a; border-color: #1cc88a;"></button>
                                            <button class="btn btn-info color-theme-btn" data-theme="info" style="background-color: #36b9cc; border-color: #36b9cc;"></button>
                                            <button class="btn btn-warning color-theme-btn" data-theme="warning" style="background-color: #f6c23e; border-color: #f6c23e;"></button>
                                            <button class="btn btn-danger color-theme-btn" data-theme="danger" style="background-color: #e74a3b; border-color: #e74a3b;"></button>
                                            <button class="btn btn-dark color-theme-btn" data-theme="dark" style="background-color: #5a5c69; border-color: #5a5c69;"></button>
                                        </div>
                                        <p class="text-muted small">Pilih tema warna yang sesuai dengan preferensi Anda.</p>

                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            Pengaturan tampilan disimpan di browser Anda dan tidak akan terlihat di perangkat lain.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Form validation
    (function () {
        'use strict'

        // Fetch all forms to apply validation
        var forms = document.querySelectorAll('.needs-validation')

        // Loop over them and prevent submission
        Array.prototype.slice.call(forms)
            .forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }

                    // Check if passwords match
                    var newPassword = document.getElementById('new_password')
                    var confirmPassword = document.getElementById('confirm_password')

                    if (newPassword.value !== '' && newPassword.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match')
                        event.preventDefault()
                        event.stopPropagation()
                    } else {
                        confirmPassword.setCustomValidity('')
                    }

                    form.classList.add('was-validated')
                }, false)
            })
    })()

    // Dark mode toggle
    const darkModeSwitch = document.getElementById('darkModeSwitch');

    // Check for saved theme preference or respect OS preference
    if (localStorage.getItem('darkMode') === 'enabled' ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches &&
            !localStorage.getItem('darkMode'))) {
        document.body.classList.add('dark-mode');
        darkModeSwitch.checked = true;
    }

    // Listen for toggle changes
    darkModeSwitch.addEventListener('change', function() {
        if (this.checked) {
            document.body.classList.add('dark-mode');
            localStorage.setItem('darkMode', 'enabled');
        } else {
            document.body.classList.remove('dark-mode');
            localStorage.setItem('darkMode', 'disabled');
        }
    });

    // Theme color buttons
    const colorThemeButtons = document.querySelectorAll('.color-theme-btn');

    // Check for saved color theme
    const savedTheme = localStorage.getItem('colorTheme');
    if (savedTheme) {
        document.documentElement.setAttribute('data-theme', savedTheme);
        colorThemeButtons.forEach(button => {
            if (button.dataset.theme === savedTheme) {
                button.classList.add('active');
            }
        });
    }

    // Listen for color theme changes
    colorThemeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const theme = this.dataset.theme;
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('colorTheme', theme);

            // Remove active class from all buttons
            colorThemeButtons.forEach(btn => btn.classList.remove('active'));

            // Add active class to clicked button
            this.classList.add('active');
        });
    });

    // Format settings
    const currencyFormat = document.getElementById('currencyFormat');
    const dateFormat = document.getElementById('dateFormat');

    // Check for saved format preferences
    if (localStorage.getItem('currencyFormat')) {
        currencyFormat.value = localStorage.getItem('currencyFormat');
    }

    if (localStorage.getItem('dateFormat')) {
        dateFormat.value = localStorage.getItem('dateFormat');
    }

    // Listen for format changes
    currencyFormat.addEventListener('change', function() {
        localStorage.setItem('currencyFormat', this.value);
    });

    dateFormat.addEventListener('change', function() {
        localStorage.setItem('dateFormat', this.value);
    });
</script>
</body>
</html>
