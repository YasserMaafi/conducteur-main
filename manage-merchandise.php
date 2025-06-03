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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    $stmt = $pdo->prepare("
                        INSERT INTO merchandise (code, description, category, hazardous, volume_class, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['description'],
                        $_POST['category'],
                        isset($_POST['hazardous']) ? true : false,
                        $_POST['volume_class']
                    ]);
                    $_SESSION['success'] = "Marchandise ajoutée avec succès";
                    break;

                case 'edit':
                    $stmt = $pdo->prepare("
                        UPDATE merchandise 
                        SET code = ?, description = ?, category = ?, 
                            hazardous = ?, volume_class = ?, updated_at = NOW()
                        WHERE merchandise_id = ?
                    ");
                    $stmt->execute([
                        $_POST['code'],
                        $_POST['description'],
                        $_POST['category'],
                        isset($_POST['hazardous']) ? true : false,
                        $_POST['volume_class'],
                        $_POST['merchandise_id']
                    ]);
                    $_SESSION['success'] = "Marchandise mise à jour avec succès";
                    break;

                case 'delete':
                    // Check if merchandise is in use
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM freight_requests 
                        WHERE merchandise_id = ?
                    ");
                    $stmt->execute([$_POST['merchandise_id']]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception("Cette marchandise est utilisée dans des demandes de fret et ne peut pas être supprimée");
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM merchandise WHERE merchandise_id = ?");
                    $stmt->execute([$_POST['merchandise_id']]);
                    $_SESSION['success'] = "Marchandise supprimée avec succès";
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    header("Location: manage-merchandise.php");
    exit();
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$hazardous = $_GET['hazardous'] ?? '';

// Build WHERE clause based on filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(code ILIKE ? OR description ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "category = ?";
    $params[] = $category;
}

if ($hazardous !== '') {
    $where_conditions[] = "hazardous = ?";
    $params[] = $hazardous === 'true' ? true : false;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get all merchandise
$stmt = $pdo->prepare("
    SELECT * FROM merchandise 
    $where_clause
    ORDER BY code
");
$stmt->execute($params);
$merchandise = $stmt->fetchAll();

// Get unique categories for filter
$categories = $pdo->query("
    SELECT DISTINCT category 
    FROM merchandise 
    WHERE category IS NOT NULL 
    ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

// Get unread notifications for navbar
$notifStmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE (user_id = ? OR (user_id IS NULL AND metadata->>'target_audience' = 'admins'))
    AND is_read = FALSE
    ORDER BY created_at DESC
    LIMIT 5
");
$notifStmt->execute([$admin_id]);
$notifications = $notifStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Marchandises | SNCFT Admin</title>
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

        .merchandise-card {
            transition: transform 0.2s;
        }
        
        .merchandise-card:hover {
            transform: translateY(-5px);
        }
        
        .hazardous-badge {
            font-size: 0.9rem;
            padding: 0.4rem 0.8rem;
        }
    </style>
</head>
<body>
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
                <?php require_once 'includes/admin_notifications.php'; ?>

                <!-- User dropdown -->
                <div class="dropdown me-3">
                    <a class="nav-link dropdown-toggle position-relative p-2" href="#" id="userDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user fa-lg"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="#">Profile</a></li>
                        <li><a class="dropdown-item" href="#">Settings</a></li>
                        <li><a class="dropdown-item" href="logout.php">Déconnexion</a></li>
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
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'pending-requests.php' ? 'active' : '' ?>" 
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
            <li class="nav-item">
                <a class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'admin-settings.php' ? 'active' : '' ?>" 
                   href="admin-settings.php">
                    <i class="fas fa-cog"></i> Paramètres
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
                <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-box me-2"></i>Gestion des Marchandises</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMerchandiseModal">
                <i class="fas fa-plus me-2"></i>Nouvelle Marchandise
            </button>
        </div>

        <!-- Search and Filter Form -->
        <form method="GET" class="mb-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Rechercher par code ou description..." 
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" 
                                    <?= $category === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="hazardous" class="form-select">
                        <option value="">Tous les types</option>
                        <option value="true" <?= $hazardous === 'true' ? 'selected' : '' ?>>Dangereux</option>
                        <option value="false" <?= $hazardous === 'false' ? 'selected' : '' ?>>Non dangereux</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1"></i>Filtrer
                    </button>
                </div>
            </div>
        </form>

        <!-- Merchandise Grid -->
        <div class="row g-4">
            <?php foreach ($merchandise as $item): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card merchandise-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-1"><?= htmlspecialchars($item['description']) ?></h5>
                                    <small class="text-muted">Code: <?= htmlspecialchars($item['code']) ?></small>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-link text-dark" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <button class="dropdown-item" data-bs-toggle="modal" 
                                                    data-bs-target="#editMerchandiseModal<?= $item['merchandise_id'] ?>">
                                                <i class="fas fa-edit me-2"></i>Modifier
                                            </button>
                                        </li>
                                        <li>
                                            <button class="dropdown-item text-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteMerchandiseModal<?= $item['merchandise_id'] ?>">
                                                <i class="fas fa-trash me-2"></i>Supprimer
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            <div class="mb-3">
                                <span class="badge bg-<?= $item['hazardous'] ? 'danger' : 'success' ?> hazardous-badge">
                                    <?= $item['hazardous'] ? 'Dangereux' : 'Non dangereux' ?>
                                </span>
                                <?php if ($item['category']): ?>
                                    <span class="badge bg-info hazardous-badge">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($item['volume_class']): ?>
                                    <span class="badge bg-secondary hazardous-badge">
                                        <?= htmlspecialchars($item['volume_class']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-calendar me-1"></i>
                                    Créé le <?= date('d/m/Y', strtotime($item['created_at'])) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Modal -->
                <div class="modal fade" id="editMerchandiseModal<?= $item['merchandise_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="merchandise_id" value="<?= $item['merchandise_id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Modifier Marchandise</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Code</label>
                                        <input type="text" name="code" class="form-control" 
                                               value="<?= htmlspecialchars($item['code']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <input type="text" name="description" class="form-control" 
                                               value="<?= htmlspecialchars($item['description']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Catégorie</label>
                                        <input type="text" name="category" class="form-control" 
                                               value="<?= htmlspecialchars($item['category'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Classe de Volume</label>
                                        <input type="text" name="volume_class" class="form-control" 
                                               value="<?= htmlspecialchars($item['volume_class'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" name="hazardous" class="form-check-input" 
                                                   id="hazardous<?= $item['merchandise_id'] ?>" 
                                                   <?= $item['hazardous'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="hazardous<?= $item['merchandise_id'] ?>">
                                                Marchandise dangereuse
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Modal -->
                <div class="modal fade" id="deleteMerchandiseModal<?= $item['merchandise_id'] ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="merchandise_id" value="<?= $item['merchandise_id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title">Supprimer Marchandise</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p>Êtes-vous sûr de vouloir supprimer la marchandise "<?= htmlspecialchars($item['description']) ?>" ?</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" class="btn btn-danger">Supprimer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Add Merchandise Modal -->
    <div class="modal fade" id="addMerchandiseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvelle Marchandise</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Code</label>
                            <input type="text" name="code" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Catégorie</label>
                            <input type="text" name="category" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Classe de Volume</label>
                            <input type="text" name="volume_class" class="form-control">
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="hazardous" class="form-check-input" id="hazardous">
                                <label class="form-check-label" for="hazardous">
                                    Marchandise dangereuse
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile sidebar toggle
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.querySelector('.admin-sidebar').classList.toggle('show');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.admin-sidebar');
            const toggle = document.getElementById('mobileSidebarToggle');
            
            if (window.innerWidth < 992 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target) && 
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    </script>
</body>
</html> 