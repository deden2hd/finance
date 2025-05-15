<?php
session_start();
require_once 'koneksi.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Process edit transaction form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_transaction'])) {
    $transaction_id = $_POST['transaction_id'];
    $type = $_POST['type'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    $category = !empty($_POST['category']) ? $_POST['category'] : null;

    // Verify that the transaction belongs to the current user
    $check_stmt = $conn->prepare("SELECT id FROM transactions WHERE id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $transaction_id, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows === 1) {
        // Update the transaction
        $stmt = $conn->prepare("UPDATE transactions SET type = ?, amount = ?, description = ?, date = ?, category = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sdssii", $type, $amount, $description, $date, $category, $transaction_id, $user_id);

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Transaksi berhasil diperbarui!";
        } else {
            $_SESSION['error_message'] = "Gagal memperbarui transaksi: " . $conn->error;
        }

        $stmt->close();
    } else {
        $_SESSION['error_message'] = "Transaksi tidak ditemukan atau Anda tidak memiliki izin untuk mengeditnya.";
    }

    $check_stmt->close();
}

// Redirect back to dashboard
header("Location: dashboard.php");
exit;
?>
