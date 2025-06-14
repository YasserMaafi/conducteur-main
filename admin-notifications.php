<?php

// Require database connection and auth functions
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify admin is logged in
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get admin info
$admin_id = $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT * FROM admins WHERE user_id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

// Get unread notifications
$notifications = [];
$stmt = $pdo->prepare("
    SELECT * FROM notifications
    WHERE (user_id = ? OR metadata->>'target_audience' = 'admins')
    AND is_read = FALSE
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$admin_id]);
$notifications = $stmt->fetchAll();

// Helper function for time display
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


// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get current admin user ID
$current_admin_id = $_SESSION['user']['id'];

// Get filter parameters
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Base query (restricted to current admin)
// In the filter section, modify the query:
// Replace the existing query with this:
$query = "
    SELECT n.id, n.type, n.title, n.message, n.created_at, n.is_read,
           COALESCE(u.username, 'System') AS sender,
           fr.id AS request_id,
           c.company_name AS client_name
    FROM notifications n
    LEFT JOIN users u ON n.user_id = u.user_id
    LEFT JOIN freight_requests fr ON n.related_request_id = fr.id
    LEFT JOIN clients c ON fr.sender_client_id = c.client_id
    WHERE (n.user_id = ? OR (n.user_id IS NULL AND metadata->>'target_audience' = 'admins'))
";

// Keep all your existing filters but remove the recipient reference
if (!empty($type_filter)) {
    $query .= " AND n.type = ?";
    $params[] = $type_filter;
}
// ... rest of your existing filters ...

$params = [$current_admin_id];

// Add filters
if (!empty($type_filter)) {
    $query .= " AND n.type = ?";
    $params[] = $type_filter;
}
if (!empty($status_filter)) {
    if ($status_filter === 'read') {
        $query .= " AND n.is_read = TRUE";
    } elseif ($status_filter === 'unread') {
        $query .= " AND n.is_read = FALSE";
    }
}
if (!empty($date_from)) {
    $query .= " AND n.created_at >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $query .= " AND n.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$query .= " ORDER BY n.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Get distinct notification types
$notification_types = $pdo->prepare("
    SELECT DISTINCT type 
    FROM notifications 
    WHERE user_id = ?
    ORDER BY type
");
$notification_types->execute([$current_admin_id]);
$notification_types = $notification_types->fetchAll(PDO::FETCH_COLUMN);

// Get admin info for sidebar
$admin = $_SESSION['user'] ?? [
    'full_name' => 'Administrateur',
    'department' => 'Admin',
    'access_level' => 1
];

// Fetch unread notifications for the navbar
// Fetch unread notifications for the navbar
$unread_notifications = [];
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC");
$stmt->execute([$current_admin_id]);
$unread_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Notifications | SNCFT Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            border-bottom: none;
            font-weight: 600;
            padding: 1.25rem 1.5rem;
        }

        /* Filter card */
        .filter-card {
            background-color: var(--light-color);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Notification items */
        .notification-item {
            border-left: 4px solid transparent;
            transition: all 0.2s;
        }

        .notification-item.unread {
            background-color: rgba(13, 110, 253, 0.05);
            border-left: 4px solid var(--primary-color);
        }

        .notification-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .notification-type {
            font-weight: 600;
            color: var(--dark-color);
            text-transform: capitalize;
        }

        /* Badges */
        .badge-unread {
            background-color: var(--danger-color);
        }

        .badge-read {
            background-color: var(--secondary-color);
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
<!-- Admin Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark admin-navbar fixed-top">
    <div class="container-fluid px-3">
        <!-- Brand with sidebar toggle for mobile -->
        <div class="d-flex align-items-center">
            <button class="btn btn-link me-2 d-lg-none text-white" id="mobileSidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand fw-bold" href="admin-dashboard.php">
                <i class="fas fa-train me-2"></i>SNCFT Admin
            </a>
        </div>

        <!-- Right side navigation items -->
        <div class="d-flex align-items-center">
            <!-- Notification dropdown -->
<div class="dropdown me-3">
    <a class="nav-link dropdown-toggle position-relative p-2" href="#" id="notifDropdown" 
       role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-bell fa-lg"></i>
        <?php if (count($notifications) > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                <?= htmlspecialchars(count($notifications)) ?>
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
                        // Default link if no valid related_request_id
                        $link = 'javascript:void(0);';
                        $request_id = $notif['related_request_id'] ?? null;
                        
                        // Only set links for notifications with valid request IDs
                        if ($request_id && $request_id > 0) {
                            // Update notification icons and links
switch ($notif['type']) {
    case 'nouvelle_demande':
        $icon = 'fa-file-alt text-primary';
        $link = "admin-request-details.php?id=".$notif['related_request_id'];
        break;
    case 'request_approved':
        $icon = 'fa-check-circle text-success';
        $link = "contract_draft.php?id=".$notif['related_request_id'];
        break;
    case 'demande_refusée':
        $icon = 'fa-times-circle text-danger';
        $link = "admin-request-details.php?id=".$notif['related_request_id'];
        break;
    case 'client_confirmed':
        $icon = 'fa-check-double text-success';
        $link = "create_contract.php?id=".$notif['related_request_id'];
        break;
}
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
                                    <?php if ($notif['type'] === 'nouvelle_demande'): ?>
                                        <i class="fas fa-file-alt text-primary"></i>
                                    <?php elseif ($notif['type'] === 'client_confirmed'): ?>
                                        <i class="fas fa-check-circle text-success"></i>
                                    <?php elseif ($notif['type'] === 'client_refused' || $notif['type'] === 'request_rejected'): ?>
                                        <i class="fas fa-times-circle text-danger"></i>
                                    <?php elseif ($notif['type'] === 'contract_draft'): ?>
                                        <i class="fas fa-file-contract text-info"></i>
                                    <?php else: ?>
                                        <i class="fas fa-bell text-warning"></i>
                                    <?php endif; ?>
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
            <small class="text-muted"><?= htmlspecialchars(count($notifications)) ?> notification(s) non lue(s)</small>
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
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
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
            <h6 class="mt-3 mb-1"> FRET</h6>
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
            <div class="alert alert-success alert-dismissible fade show animate-fade">
                <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate-fade">
                <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-12">
                <div class="card dashboard-card animate-fade">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Gestion des Notifications</h5>
                    </div>
                    <div class="card-body">
                        <!-- Filters -->
                        <div class="filter-card mb-4">
                            <form method="GET" action="admin-notifications.php">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Type de Notification</label>
                                        <select name="type" class="form-select">
                                            <option value="">Tous les types</option>
                                            <?php foreach ($notification_types as $type): ?>
                                                <option value="<?= htmlspecialchars($type) ?>" <?= $type_filter === $type ? 'selected' : '' ?>>
                                                    <?= ucfirst(str_replace('_', ' ', htmlspecialchars($type))) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Statut</label>
                                        <select name="status" class="form-select">
                                            <option value="">Tous</option>
                                            <option value="unread" <?= $status_filter === 'unread' ? 'selected' : '' ?>>Non lues</option>
                                            <option value="read" <?= $status_filter === 'read' ? 'selected' : '' ?>>Lues</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date de début</label>
                                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date de fin</label>
                                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="fas fa-filter me-1"></i> Filtrer
                                        </button>
                                        <a href="admin-notifications.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i> Réinitialiser
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Notifications -->
                        <div class="list-group">
                            <?php if (empty($notifications)): ?>
                                <div class="alert alert-info">Aucune notification trouvée</div>
                            <?php else: ?>
<?php foreach ($notifications as $notification): ?>
    <div class="list-group-item notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
        <div class="d-flex justify-content-between align-items-start">
            <div class="me-3">
                <div class="d-flex align-items-center mb-1">
                    <span class="badge <?= $notification['type'] === 'request_approved' ? 'bg-success' : 'bg-danger' ?> me-2">
                        <?= ucfirst(str_replace('_', ' ', $notification['type'])) ?>
                    </span>
                    <strong><?= htmlspecialchars($notification['title']) ?></strong>
                </div>
                <div class="text-muted small">
                    <?= htmlspecialchars($notification['message']) ?>
                    <?php if (!empty($notification['client_name'])): ?>
                        <br>Client: <?= htmlspecialchars($notification['client_name']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <small class="text-muted text-nowrap">
                <?= date('d/m/Y H:i', strtotime($notification['created_at'])) ?>
            </small>
        </div>
        <div class="mt-2">
            <a href="mark_notification_read.php?id=<?= $notification['id'] ?>" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-check me-1"></i> Marquer comme lu
            </a>
            <?php if ($notification['request_id']): ?>
                <a href="admin-request-details.php?id=<?= $notification['request_id'] ?>" class="btn btn-sm btn-outline-secondary ms-1">
                    <i class="fas fa-eye me-1"></i> Voir demande
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.querySelector('.admin-sidebar').classList.toggle('show');
        });

        function getNotificationIcon($type) {
    $icons = [
        'request_approved' => 'fa-check-circle text-success',
        'demande_refusée' => 'fa-times-circle text-danger',
        'client_confirmed' => 'fa-check-double text-primary',
        'nouvelle_demande' => 'fa-file-alt text-info',
        'contract_draft' => 'fa-file-contract text-warning',
        'contract_completed' => 'fa-file-signature text-success'
    ];
    return $icons[$type] ?? 'fa-bell text-secondary';
}
    </script>
    
</body>
</html>