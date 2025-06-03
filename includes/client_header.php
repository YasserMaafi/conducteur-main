<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify client role and session
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../index.php');
    exit();
}

// Ensure client_id in session
if (!isset($_SESSION['user']['client_id'])) {
    $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $c = $stmt->fetch();
    if (!$c) {
        die("Error: Client account not properly configured");
    }
    $_SESSION['user']['client_id'] = $c['client_id'];
}
$client_id = $_SESSION['user']['client_id'];

// Fetch client info
$stmt = $pdo->prepare("
    SELECT c.*, u.email 
      FROM clients c 
      JOIN users u ON c.user_id = u.user_id 
     WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

// Get current page for active menu item
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $page_title ?? 'SNCFT Client' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .quick-action-card { transition: transform .2s; cursor: pointer; }
        .quick-action-card:hover { transform: translateY(-5px); box-shadow:0 10px 20px rgba(0,0,0,.1); }
        .notification-item.unread { background-color: #f8f9fa; border-left:4px solid #0d6efd; }
        .shipment-status { display:inline-block; padding:3px 8px; border-radius:20px; font-size:.8rem; font-weight:500; }
        .status-pending { background:#fff3cd; color:#856404; }
        .status-approved { background:#d4edda; color:#155724; }
        .status-in_transit { background:#cce5ff; color:#004085; }
        .status-delivered { background:#e2e3e5; color:#383d41; }
        .status-rejected { background:#f8d7da; color:#721c24; }
        .contract-status { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: .8rem; font-weight: 500; }
        .status-en_cours { background: #fff3cd; color: #856404; }
        .status-in_transit { background: #cce5ff; color: #004085; }
        .status-validé { background: #d4edda; color: #155724; }
        .status-completed { background: #e2e3e5; color: #383d41; }
        .payment-status { font-size: 0.75rem; padding: 0.5em 0.75em; border-radius: 50rem; }
        .payment-status.completed { background-color: rgba(25, 135, 84, 0.1); color: #198754; }
        .payment-status.pending { background-color: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .payment-status.failed { background-color: rgba(220, 53, 69, 0.1); color: #dc3545; }
    </style>
</head>
<body class="bg-light">
    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="client-dashboard.php">
                <i class="fas fa-train me-2"></i>SNCFT Client
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="fas fa-building me-1"></i><?= htmlspecialchars($client['company_name']) ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- SIDEBAR -->
            <div class="col-lg-3 mb-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-body text-center">
                        <div class="avatar bg-primary text-white rounded-circle p-3 mx-auto" style="width:80px;height:80px">
                            <i class="fas fa-building fa-2x"></i>
                        </div>
                        <h5 class="mt-3"><?= htmlspecialchars($client['company_name']) ?></h5>
                        <small class="text-muted">Client</small>
                    </div>
                </div>

                <div class="list-group mb-4">
                    <a href="client-dashboard.php" class="list-group-item list-group-item-action <?= $current_page === 'client-dashboard.php' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
                    </a>
                    <a href="new_request.php" class="list-group-item list-group-item-action <?= $current_page === 'new_request.php' ? 'active' : '' ?>">
                        <i class="fas fa-plus-circle me-2"></i>Nouvelle demande
                    </a>
                    <a href="interface.php" class="list-group-item list-group-item-action <?= $current_page === 'interface.php' ? 'active' : '' ?>">
                        <i class="fas fa-truck me-2"></i>Mes expéditions
                    </a>
                    <a href="client-payments.php" class="list-group-item list-group-item-action <?= $current_page === 'client-payments.php' ? 'active' : '' ?>">
                        <i class="fas fa-money-bill-wave me-2"></i>Paiements
                    </a>
                    <a href="client-contracts.php" class="list-group-item list-group-item-action <?= $current_page === 'client-contracts.php' ? 'active' : '' ?>">
                        <i class="fas fa-file-contract me-2"></i>Contrats
                    </a>
                    <a href="notifications.php" class="list-group-item list-group-item-action <?= $current_page === 'notifications.php' ? 'active' : '' ?>">
                        <i class="fas fa-bell me-2"></i>Notifications
                    </a>
                    <a href="requests.php" class="list-group-item list-group-item-action <?= $current_page === 'requests.php' ? 'active' : '' ?>">
                        <i class="fas fa-file-alt me-2"></i>Mes demandes
                    </a>
                    <a href="profile.php" class="list-group-item list-group-item-action <?= $current_page === 'profile.php' ? 'active' : '' ?>">
                        <i class="fas fa-user-cog me-2"></i>Profil
                    </a>
                </div>
            </div>

            <!-- MAIN CONTENT -->
            <div class="col-lg-9"> 