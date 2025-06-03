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


// Get unread notifications
$notifications = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
    ORDER BY created_at DESC
    LIMIT 5
");
$notifications->execute([$admin_id]);
$notifications = $notifications->fetchAll();

// Handle client deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    
    try {
        $pdo->beginTransaction();
        
        // First get user_id to delete from users table
        $stmt = $pdo->prepare("SELECT user_id FROM clients WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch();
        
        if ($client) {
            // Delete client's tariffs first
            $stmt = $pdo->prepare("DELETE FROM tariffs WHERE client_id = ?");
            $stmt->execute([$client_id]);
            
            // Delete from clients table
            $stmt = $pdo->prepare("DELETE FROM clients WHERE client_id = ?");
            $stmt->execute([$client_id]);
            
            // Delete from users table
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$client['user_id']]);
            
            $pdo->commit();
            $_SESSION['success'] = "Client supprimé avec succès";
        } else {
            throw new Exception("Client non trouvé");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: manage-clients.php");
    exit();
}

// Handle client updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_client'])) {
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    $company_name = filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_STRING);
    $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_STRING);
    $account_code = filter_input(INPUT_POST, 'account_code', FILTER_SANITIZE_STRING);
    $adresse = filter_input(INPUT_POST, 'adresse', FILTER_SANITIZE_STRING);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE clients 
            SET company_name = ?, phone_number = ?, account_code = ?, adresse = ?, updated_at = NOW()
            WHERE client_id = ?
        ");
        $stmt->execute([$company_name, $phone_number, $account_code, $adresse, $client_id]);
        
        $_SESSION['success'] = "Client mis à jour avec succès";
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: manage-clients.php");
    exit();
}

// Handle tariff updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tariff'])) {
    $client_id = filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT);
    $base_rate_per_km = filter_input(INPUT_POST, 'base_rate_per_km', FILTER_VALIDATE_FLOAT);
    
    try {
        // Check if tariff exists
        $stmt = $pdo->prepare("SELECT tariff_id FROM tariffs WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $tariff_exists = $stmt->fetch();
        
        if ($tariff_exists) {
            // Update existing tariff
            $stmt = $pdo->prepare("UPDATE tariffs SET base_rate_per_km = ? WHERE client_id = ?");
            $stmt->execute([$base_rate_per_km, $client_id]);
        } else {
            // Insert new tariff
            $stmt = $pdo->prepare("INSERT INTO tariffs (client_id, base_rate_per_km) VALUES (?, ?)");
            $stmt->execute([$client_id, $base_rate_per_km]);
        }
        
        $_SESSION['success'] = "Tarif mis à jour avec succès";
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: manage-clients.php");
    exit();
}

// Handle new client creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_client'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $password = password_hash(filter_input(INPUT_POST, 'password'), PASSWORD_DEFAULT);
    $company_name = filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_STRING);
    $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_STRING);
    $account_code = filter_input(INPUT_POST, 'account_code', FILTER_SANITIZE_STRING);
    $adresse = filter_input(INPUT_POST, 'adresse', FILTER_SANITIZE_STRING);
    $base_rate_per_km = filter_input(INPUT_POST, 'base_rate_per_km', FILTER_VALIDATE_FLOAT);
    
    try {
        $pdo->beginTransaction();
        
        // First create user
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, email, role, created_at) 
            VALUES (?, ?, ?, 'client', NOW())
        ");
        $stmt->execute([$username, $password, $email]);
        $user_id = $pdo->lastInsertId();
        
        // Then create client
        $stmt = $pdo->prepare("
            INSERT INTO clients 
            (user_id, company_name, phone_number, account_code, adresse, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $company_name, $phone_number, $account_code, $adresse]);
        $client_id = $pdo->lastInsertId();
        
        // Add tariff if provided
        if ($base_rate_per_km !== false) {
            $stmt = $pdo->prepare("
                INSERT INTO tariffs (client_id, base_rate_per_km) 
                VALUES (?, ?)
            ");
            $stmt->execute([$client_id, $base_rate_per_km]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Nouveau client créé avec succès";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: manage-clients.php");
    exit();
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'user_created_at'; // Changed default to user_created_at
$order = isset($_GET['order']) && in_array(strtoupper($_GET['order']), ['ASC', 'DESC']) 
    ? strtoupper($_GET['order']) 
    : 'DESC';

// Build base query with tariff information
$query = "
    SELECT c.*, u.email, u.created_at as user_created_at, t.base_rate_per_km
    FROM clients c
    JOIN users u ON c.user_id = u.user_id
    LEFT JOIN tariffs t ON c.client_id = t.client_id
    WHERE u.role = 'client'
";

// Add search filter if provided
$params = [];
if (!empty($search)) {
    $query .= " AND (c.company_name LIKE ? OR u.email LIKE ? OR c.account_code LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Add sorting - specify exact table for created_at
switch ($sort) {
    case 'company_name':
        $query .= " ORDER BY c.company_name $order";
        break;
    case 'base_rate_per_km':
        $query .= " ORDER BY t.base_rate_per_km $order";
        break;
    case 'user_created_at':
    default:
        $query .= " ORDER BY u.created_at $order";
        break;
}

// Get all clients with their tariffs
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$clients = $stmt->fetchAll();

// Get unread notifications
$notifications = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
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
    <title>Gestion Clients | SNCFT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <?php require_once 'assets/css/style.css'; ?>
        /* All CSS from admin-dashboard.php */
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

        /* ... (include all other CSS from admin-dashboard.php) ... */

        /* Additional styles for this page */
        .client-actions {
            white-space: nowrap;
        }
        
        .sortable-header {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .sortable-header:hover {
            background-color: rgba(0,0,0,0.05);
        }
        
        .active-sort {
            background-color: rgba(13, 110, 253, 0.1);
        }
        
        .client-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-weight: bold;
        }
        
        .tariff-badge {
            font-size: 0.85rem;
            padding: 0.35em 0.65em;
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
    <!-- Admin Navigation - Same as admin-dashboard.php -->
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
                <?php require_once 'includes/admin_notifications.php'; ?>

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
        <div class="row">
            <div class="col-12">
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
            </div>
        </div>

        <!-- Client Management Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-users me-2"></i>Gestion des Clients</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
                <i class="fas fa-plus me-1"></i> Nouveau Client
            </button>
        </div>

        <!-- Filter and Search Bar -->
        <div class="card dashboard-card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Rechercher par nom, email ou code compte..." 
                                   value="<?= htmlspecialchars($search) ?>">
                            <button class="btn btn-primary" type="submit">Rechercher</button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="input-group">
                            <span class="input-group-text">Trier par</span>
                            <select class="form-select" name="sort" onchange="this.form.submit()">
                                <option value="company_name" <?= $sort === 'company_name' ? 'selected' : '' ?>>Nom</option>
                                <option value="user_created_at" <?= $sort === 'user_created_at' ? 'selected' : '' ?>>Date de création</option>
                                <option value="base_rate_per_km" <?= $sort === 'base_rate_per_km' ? 'selected' : '' ?>>Tarif</option>
                            </select>
                            <select class="form-select" name="order" onchange="this.form.submit()">
                                <option value="ASC" <?= $order === 'ASC' ? 'selected' : '' ?>>Croissant</option>
                                <option value="DESC" <?= $order === 'DESC' ? 'selected' : '' ?>>Décroissant</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Clients Table -->
        <div class="card dashboard-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Liste des Clients</h5>
                <span class="badge bg-primary rounded-pill"><?= count($clients) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($clients)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>Aucun client trouvé
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th class="sortable-header <?= $sort === 'company_name' ? 'active-sort' : '' ?>">
                                        <a href="?search=<?= urlencode($search) ?>&sort=company_name&order=<?= $sort === 'company_name' && $order === 'ASC' ? 'DESC' : 'ASC' ?>">
                                            Nom Société
                                            <?php if ($sort === 'company_name'): ?>
                                                <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ms-1"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>Email</th>
                                    <th>Téléphone</th>
                                    <th>Code Compte</th>
                                    <th class="sortable-header <?= $sort === 'base_rate_per_km' ? 'active-sort' : '' ?>">
                                        <a href="?search=<?= urlencode($search) ?>&sort=base_rate_per_km&order=<?= $sort === 'base_rate_per_km' && $order === 'ASC' ? 'DESC' : 'ASC' ?>">
                                            Tarif (€/km)
                                            <?php if ($sort === 'base_rate_per_km'): ?>
                                                <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ms-1"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>Adresse</th>
                                    <th class="sortable-header <?= $sort === 'user_created_at' ? 'active-sort' : '' ?>">
                                        <a href="?search=<?= urlencode($search) ?>&sort=user_created_at&order=<?= $sort === 'user_created_at' && $order === 'ASC' ? 'DESC' : 'ASC' ?>">
                                            Date Création
                                            <?php if ($sort === 'user_created_at'): ?>
                                                <i class="fas fa-sort-<?= $order === 'ASC' ? 'up' : 'down' ?> ms-1"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                    <tr>
                                        <td>
                                            <div class="client-avatar">
                                                <?= strtoupper(substr($client['company_name'], 0, 1)) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($client['company_name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($client['email']) ?></td>
                                        <td><?= htmlspecialchars($client['phone_number']) ?></td>
                                        <td>
                                            <?php if (!empty($client['account_code'])): ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($client['account_code']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($client['base_rate_per_km'])): ?>
                                                <span class="badge bg-success tariff-badge">
                                                    <?= number_format($client['base_rate_per_km'], 2) ?> €/km
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning tariff-badge">Non défini</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= nl2br(htmlspecialchars($client['adresse'])) ?></small>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($client['user_created_at'])) ?></td>
                                        <td class="text-end client-actions">
                                            <button class="btn btn-sm btn-outline-primary me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editClientModal<?= $client['client_id'] ?>"
                                                    title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-info me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editTariffModal<?= $client['client_id'] ?>"
                                                    title="Modifier tarif">
                                                <i class="fas fa-euro-sign"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce client?');">
                                                <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                                <button type="submit" name="delete_client" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    
                                    <!-- Edit Client Modal -->
                                    <div class="modal fade" id="editClientModal<?= $client['client_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-edit me-2"></i>Modifier Client
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nom Société <span class="text-danger">*</span></label>
                                                            <input type="text" class="form-control" name="company_name" 
                                                                   value="<?= htmlspecialchars($client['company_name']) ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Téléphone</label>
                                                            <input type="text" class="form-control" name="phone_number" 
                                                                   value="<?= htmlspecialchars($client['phone_number']) ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Code Compte</label>
                                                            <input type="text" class="form-control" name="account_code" 
                                                                   value="<?= htmlspecialchars($client['account_code']) ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Adresse</label>
                                                            <textarea class="form-control" name="adresse" rows="3"><?= 
                                                                htmlspecialchars($client['adresse']) ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="fas fa-times me-1"></i>Annuler
                                                        </button>
                                                        <button type="submit" name="update_client" class="btn btn-primary">
                                                            <i class="fas fa-save me-1"></i>Enregistrer
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Edit Tariff Modal -->
                                    <div class="modal fade" id="editTariffModal<?= $client['client_id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                                    <div class="modal-header bg-info text-white">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-euro-sign me-2"></i>Tarif pour <?= htmlspecialchars($client['company_name']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="alert alert-info">
                                                            <i class="fas fa-info-circle me-2"></i>
                                                            Ce tarif sera utilisé pour calculer les prix des demandes de fret.
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Tarif de base (€/km) <span class="text-danger">*</span></label>
                                                            <input type="number" class="form-control" name="base_rate_per_km" 
                                                                   value="<?= $client['base_rate_per_km'] ?? '' ?>" step="0.01" min="0" required>
                                                            <small class="text-muted">Prix par kilomètre pour le transport</small>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="fas fa-times me-1"></i>Annuler
                                                        </button>
                                                        <button type="submit" name="update_tariff" class="btn btn-info text-white">
                                                            <i class="fas fa-save me-1"></i>Enregistrer Tarif
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Client Modal -->
    <div class="modal fade" id="addClientModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-plus-circle me-2"></i>Nouveau Client
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nom d'utilisateur <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mot de passe <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nom Société <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="company_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Téléphone</label>
                            <input type="text" class="form-control" name="phone_number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Code Compte</label>
                            <input type="text" class="form-control" name="account_code">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adresse</label>
                            <textarea class="form-control" name="adresse" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tarif de base (€/km)</label>
                            <input type="number" class="form-control" name="base_rate_per_km" step="0.01" min="0">
                            <small class="text-muted">Peut être défini plus tard</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Annuler
                        </button>
                        <button type="submit" name="add_client" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Créer Client
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.querySelector('.admin-sidebar').classList.toggle('show');
        });
        
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>