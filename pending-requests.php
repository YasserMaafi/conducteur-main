<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get admin info
$admin_id = $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT * FROM admins WHERE user_id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

// Get filter parameters
$client_name = $_GET['client_name'] ?? '';
$merchandise_type = $_GET['merchandise_type'] ?? '';
$min_weight = $_GET['min_weight'] ?? '';
$max_weight = $_GET['max_weight'] ?? '';
$gare_depart = $_GET['gare_depart'] ?? '';
$gare_arrivee = $_GET['gare_arrivee'] ?? '';
$highlight_id = $_GET['highlight'] ?? null;

// Build base query
$query = "
    SELECT fr.id AS request_id, fr.*, 
           c.company_name, c.account_code,
           g1.libelle AS origin, g2.libelle AS destination,
           m.description AS merchandise, m.code AS merchandise_code,
           u.email AS client_email
    FROM freight_requests fr
    JOIN clients c ON fr.sender_client_id = c.client_id
    JOIN users u ON c.user_id = u.user_id
    JOIN gares g1 ON fr.gare_depart = g1.id_gare
    JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
    LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
    WHERE fr.status = 'pending'
";

// Add filters to query
$params = [];
$conditions = [];

if (!empty($client_name)) {
    $conditions[] = "c.company_name ILIKE ?";
    $params[] = "%$client_name%";
}

if (!empty($merchandise_type)) {
    $conditions[] = "m.description ILIKE ?";
    $params[] = "%$merchandise_type%";
}

if (!empty($min_weight)) {
    $conditions[] = "fr.quantity >= ?";
    $params[] = $min_weight;
}

if (!empty($max_weight)) {
    $conditions[] = "fr.quantity <= ?";
    $params[] = $max_weight;
}

if (!empty($gare_depart)) {
    $conditions[] = "g1.libelle ILIKE ?";
    $params[] = "%$gare_depart%";
}

if (!empty($gare_arrivee)) {
    $conditions[] = "g2.libelle ILIKE ?";
    $params[] = "%$gare_arrivee%";
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " ORDER BY fr.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$pending_requests = $stmt->fetchAll();

// Get distinct merchandise types for filter dropdown
$merchandise_types = $pdo->query("SELECT DISTINCT description FROM merchandise")->fetchAll();

// Get distinct gares for filter dropdowns
$gares = $pdo->query("SELECT id_gare, libelle FROM gares ORDER BY libelle")->fetchAll();

// Get unread notifications for navbar
$notifications = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE (user_id = ? OR (user_id IS NULL AND metadata->>'target_audience' = 'admins'))
    AND is_read = FALSE
    ORDER BY created_at DESC
    LIMIT 5
");
$notifications->execute([$admin_id]);
$notifications = $notifications->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandes en Attente | SNCFT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <?php require_once 'assets/css/style.css'; ?>

        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 280px;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 56px;
        }

        .admin-navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: 56px;
        }

        .admin-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            position: fixed;
            height: calc(100vh - 56px);
            top: 56px;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 1000;
        }

        .admin-sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            margin: 0.15rem 0;
            border-radius: 0.25rem;
            transition: all 0.2s;
        }

        .admin-sidebar .nav-link:hover, 
        .admin-sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
        }

        .admin-sidebar .nav-link i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all 0.3s;
            min-height: calc(100vh - 56px);
        }

        .dashboard-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-weight: 600;
            padding: 1rem 1.5rem;
        }

        .filter-card {
            background-color: var(--light-color);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .data-table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .data-table thead th {
            background-color: #f8f9fa;
            border: none;
            padding: 12px 15px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .data-table tbody tr {
            transition: all 0.2s;
        }

        .data-table tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }

        .highlight-row {
            background-color: #fffde7 !important;
            animation: highlight 2s ease-out;
        }

        @keyframes highlight {
            from { background-color: #fff9c4; }
            to { background-color: #fffde7; }
        }

        .btn-action {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 50%;
        }

        @media (max-width: 992px) {
            .admin-sidebar {
                margin-left: -100%;
            }
            .admin-sidebar.show {
                margin-left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
        <style>
        <?php require_once 'assets/css/style.css'; ?>

        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 280px;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 56px; /* Space for fixed navbar */
        }

        /* Admin Navigation */
        .admin-navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: 56px;
        }

        .avatar-sm {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Sidebar styling */
        .admin-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            position: fixed;
            height: calc(100vh - 56px);
            top: 56px;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 1000;
        }

        .admin-sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            margin: 0.15rem 0;
            border-radius: 0.25rem;
            transition: all 0.2s;
        }

        .admin-sidebar .nav-link:hover, 
        .admin-sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
        }

        .admin-sidebar .nav-link i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }

        .admin-sidebar .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .admin-profile {
            text-align: center;
            padding: 1.5rem 0;
        }

        .admin-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 2.5rem;
            color: white;
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        /* Main content area */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all 0.3s;
            min-height: calc(100vh - 56px);
        }

        @media (max-width: 992px) {
            .admin-sidebar {
                margin-left: -100%;
            }
            .admin-sidebar.show {
                margin-left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }

        /* Card styling */
        .dashboard-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-weight: 600;
            padding: 1rem 1.5rem;
        }

        /* Stats cards */
        .stat-card {
            border-radius: 10px;
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
            margin-bottom: 1rem;
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-card .stat-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Table styling */
        .data-table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .data-table thead th {
            background-color: #f8f9fa;
            border: none;
            padding: 12px 15px;
            font-weight: 600;
            color: var(--secondary-color);
        }

        .data-table tbody tr {
            transition: all 0.2s;
        }

        .data-table tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }

        .data-table tbody td {
            padding: 12px 15px;
            vertical-align: middle;
            border-top: 1px solid #f1f1f1;
        }

        /* Badges */
        .badge {
            padding: 0.35em 0.65em;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        /* Buttons */
        .btn-action {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 50%;
        }

        /* Price calculator */
        .price-calculator {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 15px;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade {
            animation: fadeIn 0.3s ease-out forwards;
        }
    </style>
</head>
<body>
    <!-- Admin Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark admin-navbar fixed-top">
        <div class="container-fluid px-3">
            <div class="d-flex align-items-center">
                <button class="btn btn-link me-2 d-lg-none text-white" id="mobileSidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="navbar-brand fw-bold" href="admin-dashboard.php">
                    <i class="fas fa-train me-2"></i>SNCFT Admin
                </a>
            </div>

            <div class="d-flex align-items-center">
                <!-- Notification dropdown -->
                <div class="dropdown me-3">
                    <a class="nav-link dropdown-toggle position-relative p-2" href="#" id="notifDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= count($notifications) ?>
                                <span class="visually-hidden">unread notifications</span>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow" aria-labelledby="notifDropdown" style="width: 350px;">
                        <li class="dropdown-header bg-light py-2 px-3 d-flex justify-content-between align-items-center border-bottom">
                            <strong class="text-primary">Notifications</strong>
                            <a href="admin-notifications.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
                        </li>
                        <?php if (empty($notifications)): ?>
                            <li class="py-4 px-3 text-center text-muted">
                                <i class="fas fa-bell-slash fa-2x mb-2 opacity-50"></i>
                                <div>Aucune nouvelle notification</div>
                            </li>
                        <?php else: ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($notifications as $notif): ?>
                                    <?php
                                        // Default link for notifications with no specific action
                                        $link = 'javascript:void(0);';
                                        $request_id = $notif['related_request_id'] ?? null;
                                        
                                        // Set links based on notification type
                                        switch ($notif['type']) {
                                            case 'client_confirmed':
                                            case 'client_rejected':
                                            case 'request_rejected':
                                            case 'request_approved':
                                            case 'request_accepted':
                                            case 'nouvelle_demande':
                                                $link = "admin-request-details.php?id=" . (int)$request_id;
                                                break;
                                            case 'contract_draft':
                                            case 'new_contract_draft':
                                                $link = "create_contract.php?request_id=" . (int)$request_id;
                                                break;
                                            case 'contract_completed':
                                                $link = "contract_details.php?id=" . (int)$request_id;
                                                break;
                                        }
                                        
                                        $timeAgo = time_elapsed_string($notif['created_at']);
                                        $metadata = isset($notif['metadata']) ? json_decode($notif['metadata'], true) : [];
                                    ?>
                                    <li>
                                        <a class="dropdown-item p-3 border-bottom <?= $notif['is_read'] ? '' : 'bg-light' ?>" 
                                           href="<?= htmlspecialchars($link) ?>"
                                           <?= ($request_id && $request_id > 0) ? '' : 'onclick="return false;"' ?>>
                                            <div class="d-flex">
                                                <div class="flex-shrink-0 me-3">
                                                    <?php switch($notif['type']) {
                                                        case 'nouvelle_demande': ?>
                                                            <i class="fas fa-file-alt text-primary"></i>
                                                            <?php break;
                                                        case 'client_confirmed':
                                                        case 'request_approved':
                                                        case 'request_accepted': ?>
                                                            <i class="fas fa-check-circle text-success"></i>
                                                            <?php break;
                                                        case 'client_rejected':
                                                        case 'request_rejected': ?>
                                                            <i class="fas fa-times-circle text-danger"></i>
                                                            <?php break;
                                                        case 'contract_draft':
                                                        case 'new_contract_draft': ?>
                                                            <i class="fas fa-file-contract text-info"></i>
                                                            <?php break;
                                                        case 'contract_completed': ?>
                                                            <i class="fas fa-file-signature text-success"></i>
                                                            <?php break;
                                                        default: ?>
                                                            <i class="fas fa-bell text-warning"></i>
                                                    <?php } ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between">
                                                        <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                                        <small class="text-muted"><?= htmlspecialchars($timeAgo) ?></small>
                                                    </div>
                                                    <div class="text-muted small"><?= htmlspecialchars($notif['message']) ?></div>
                                                    <?php if (!empty($metadata)): ?>
                                                        <div class="mt-1">
                                                            <?php if (isset($metadata['price'])): ?>
                                                                <small class="text-success"><?= htmlspecialchars($metadata['price']) ?> €</small>
                                                            <?php endif; ?>
                                                            <?php if (isset($metadata['wagon_count'])): ?>
                                                                <small class="text-primary ms-2"><?= htmlspecialchars($metadata['wagon_count']) ?> wagons</small>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <li class="dropdown-footer bg-light py-2 px-3 text-center border-top">
                            <small class="text-muted"><?= count($notifications) ?> notification(s) non lue(s)</small>
                        </li>
                    </ul>
                </div>

                <!-- User dropdown -->
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="me-2 d-none d-sm-block text-end">
                            <div class="fw-semibold text-white"><?= htmlspecialchars($admin['full_name'] ?? 'Administrateur') ?></div>
                            <small class="text-white-50"><?= htmlspecialchars($admin['department'] ?? 'Admin') ?></small>
                        </div>
                        <div class="avatar-sm bg-white text-primary rounded-circle d-flex align-items-center justify-content-center">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                        <li><h6 class="dropdown-header">Compte Administrateur</h6></li>
                        <li><a class="dropdown-item" href="admin-profile.php"><i class="fas fa-user-cog me-2"></i>Profil</a></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

 <!-- Sidebar Navigation -->
    <div class="admin-sidebar">
        <div class="sidebar-header">
            <h5 class="mb-0">Administration</h5>
        </div>
        <div class="admin-profile">
            <div class="admin-avatar">
                <i class="fas fa-user-shield"></i>
            </div>
            <h6 class="mt-3 mb-1"><?= htmlspecialchars($admin['department']) ?></h6>
            <small class="text-white-50">Administrateur</small>
        </div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'admin-dashboard.php' ? 'active' : '' ?>" 
                   href="admin-dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Tableau de bord
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'admin-requests.php' ? 'active' : '' ?>" 
                   href="pending-requests.php">
                    <i class="fas fa-tasks"></i> Demandes de fret
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'admin-notifications.php' ? 'active' : '' ?>" 
                   href="admin-notifications.php">
                    <i class="fas fa-bell"></i> Notifications
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-clients.php' ? 'active' : '' ?>" 
                   href="manage-clients.php">
                    <i class="fas fa-users"></i> Clients
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-agents.php' ? 'active' : '' ?>" 
                   href="manage-agents.php">
                    <i class="fas fa-user-tie"></i> Agents
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-trains.php' ? 'active' : '' ?>" 
                   href="manage-trains.php">
                    <i class="fas fa-train"></i> Trains
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-wagons.php' ? 'active' : '' ?>" 
                   href="manage-wagons.php">
                    <i class="fas fa-box"></i> Wagons
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-tariffs.php' ? 'active' : '' ?>" 
                   href="manage-tariffs.php">
                    <i class="fas fa-money-bill-wave"></i> Tarifs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-gares.php' ? 'active' : '' ?>" 
                   href="manage-gares.php">
                    <i class="fas fa-map-marker-alt"></i> Gares
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manage-merchandise.php' ? 'active' : '' ?>" 
                   href="manage-merchandise.php">
                    <i class="fas fa-boxes"></i> Marchandises
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'admin-contracts.php' ? 'active' : '' ?>" 
                   href="admin-contracts.php">
                    <i class="fas fa-chart-bar"></i> Contrats
                </a> 
            </li>
            <li class="nav-item mt-3">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Déconnexion
                </a>
            </li>
        </ul>
    </div>


    <!-- Main Content -->
    <div class="main-content">
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="card dashboard-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-clock me-2 text-warning"></i>Demandes en Attente</h5>
                <span class="badge bg-primary rounded-pill"><?= count($pending_requests) ?></span>
            </div>
            
            <div class="card-body">
                <!-- Filter Section -->
                <div class="filter-card mb-4">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Client</label>
                            <input type="text" name="client_name" class="form-control" value="<?= htmlspecialchars($client_name) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Type Marchandise</label>
                            <select name="merchandise_type" class="form-select">
                                <option value="">Tous</option>
                                <?php foreach ($merchandise_types as $type): ?>
                                    <option value="<?= htmlspecialchars($type['description']) ?>" <?= $merchandise_type == $type['description'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($type['description']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Poids Min (kg)</label>
                            <input type="number" name="min_weight" class="form-control" value="<?= htmlspecialchars($min_weight) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Poids Max (kg)</label>
                            <input type="number" name="max_weight" class="form-control" value="<?= htmlspecialchars($max_weight) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gare Départ</label>
                            <select name="gare_depart" class="form-select">
                                <option value="">Toutes</option>
                                <?php foreach ($gares as $gare): ?>
                                    <option value="<?= htmlspecialchars($gare['libelle']) ?>" <?= $gare_depart == $gare['libelle'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gare['libelle']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gare Arrivée</label>
                            <select name="gare_arrivee" class="form-select">
                                <option value="">Toutes</option>
                                <?php foreach ($gares as $gare): ?>
                                    <option value="<?= htmlspecialchars($gare['libelle']) ?>" <?= $gare_arrivee == $gare['libelle'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gare['libelle']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-1"></i> Filtrer
                            </button>
                            <a href="pending-requests.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-1"></i> Réinitialiser
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Requests Table -->
                <?php if (empty($pending_requests)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>Aucune demande en attente
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Client</th>
                                    <th>Trajet</th>
                                    <th>Marchandise</th>
                                    <th>Poids (kg)</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $request): ?>
                                <tr class="<?= $highlight_id == $request['request_id'] ? 'highlight-row' : '' ?>" id="request-<?= $request['request_id'] ?>">
                                    <td><span class="badge bg-light text-dark">FR-<?= $request['request_id'] ?></span></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($request['company_name']) ?></div>
                                        <small class="text-muted"><?= $request['client_email'] ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="text-danger me-2"><i class="fas fa-map-marker-alt"></i></span>
                                            <div>
                                                <div><?= htmlspecialchars($request['origin']) ?></div>
                                                <div class="text-muted small">à</div>
                                                <div><?= htmlspecialchars($request['destination']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($request['merchandise'] ?? $request['description']) ?>
                                        <?php if (!empty($request['merchandise_code'])): ?>
                                            <br><small class="text-muted">Code: <?= $request['merchandise_code'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= number_format($request['quantity'], 0, ',', ' ') ?> kg</td>
                                    <td><?= date('d/m/Y', strtotime($request['date_start'])) ?></td>
                                    <td>
                                        <div class="d-flex">
                                            <a href="admin-request-details.php?id=<?= $request['request_id'] ?>" 
                                               class="btn-action btn-outline-primary me-1"
                                               title="Voir détails">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="approve_request.php?id=<?= $request['request_id'] ?>" 
                                               class="btn-action btn-success me-1"
                                               title="Approuver">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <a href="reject_request.php?id=<?= $request['request_id'] ?>" 
                                               class="btn-action btn-danger"
                                               title="Rejeter">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.querySelector('.admin-sidebar').classList.toggle('show');
        });

        // Scroll to highlighted row
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($highlight_id): ?>
                const highlightedRow = document.getElementById('request-<?= $highlight_id ?>');
                if (highlightedRow) {
                    highlightedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>

<?php
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $string = array(
        'y' => 'an',
        'm' => 'mois',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    );
    
    foreach ($string as $k => $v) {
        if ($diff->$k) {
            $parts[] = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        }
    }

    if (!$full) $parts = array_slice($parts, 0, 1);
    return $parts ? implode(', ', $parts) . ' il y a' : 'à l\'instant';
}
?>