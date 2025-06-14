<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/pricing_functions.php';

// Functions to check train and wagon availability
function getAvailableTrains($pdo, $date_start) {
    $stmt = $pdo->prepare("
        SELECT t.train_id, t.train_number, t.status
        FROM trains t
        WHERE t.status = 'available'
        AND (t.next_available_date IS NULL OR t.next_available_date <= ?)
        ORDER BY t.train_number
    ");
    $stmt->execute([$date_start]);
    return $stmt->fetchAll();
}

function getAvailableWagons($pdo, $date_start, $count_needed) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as available_count
        FROM wagons w
        WHERE w.status = 'available'
        AND NOT EXISTS (
            SELECT 1 FROM wagon_assignments wa
            WHERE wa.wagon_id = w.wagon_id
            AND wa.status = 'assigned'
            AND wa.assigned_date = ?
        )
    ");
    $stmt->execute([$date_start]);
    $result = $stmt->fetch();
    return $result['available_count'] >= $count_needed;
}

function assignWagonsToTrain($pdo, $train_id, $count_needed, $date) {
    $stmt = $pdo->prepare("
        SELECT w.wagon_id, w.wagon_number
        FROM wagons w
        WHERE w.status = 'available'
        AND NOT EXISTS (
            SELECT 1 FROM wagon_assignments wa
            WHERE wa.wagon_id = w.wagon_id
            AND wa.status = 'assigned'
            AND wa.assigned_date = ?
        )
        LIMIT ?
    ");
    $stmt->execute([$date, $count_needed]);
    $wagons = $stmt->fetchAll();
    
    if (count($wagons) < $count_needed) {
        return false;
    }
    
    // Assign wagons
    $stmt = $pdo->prepare("
        INSERT INTO wagon_assignments (wagon_id, train_id, assigned_date, status)
        VALUES (?, ?, ?, 'assigned')
    ");
    
    foreach ($wagons as $wagon) {
        $stmt->execute([$wagon['wagon_id'], $train_id, $date]);
    }
    
    return array_column($wagons, 'wagon_id');
}

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

// Process approval/denial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Get full request details with sender client info
        $stmt = $pdo->prepare("
            SELECT fr.*, 
                   c.client_id AS client_id, 
                   c.user_id AS client_user_id, 
                   c.company_name, 
                   u.email, 
                   g1.libelle AS origin, 
                   g2.libelle AS destination,
                   m.description AS merchandise
            FROM freight_requests fr
            JOIN clients c ON fr.sender_client_id = c.client_id
            JOIN users u ON c.user_id = u.user_id
            JOIN gares g1 ON fr.gare_depart = g1.id_gare
            JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
            LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
            WHERE fr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();

        if (!$request) {
            throw new Exception("Demande non trouvée.");
        }

        if ($action === 'approve') {
            // Validate train selection and wagon availability
            if (empty($_POST['train_id'])) {
                throw new Exception("Veuillez sélectionner un train.");
            }
            
            $train_id = filter_input(INPUT_POST, 'train_id', FILTER_VALIDATE_INT);
            $wagon_count = filter_input(INPUT_POST, 'wagon_count', FILTER_VALIDATE_INT);
            
            // Verify train is still available
            $available_trains = getAvailableTrains($pdo, $request['date_start']);
            $train_available = false;
            foreach ($available_trains as $train) {
                if ($train['train_id'] == $train_id) {
                    $train_available = true;
                    break;
                }
            }
            if (!$train_available) {
                throw new Exception("Le train sélectionné n'est plus disponible.");
            }
            
            // Verify and assign wagons
            if (!getAvailableWagons($pdo, $request['date_start'], $wagon_count)) {
                throw new Exception("Nombre insuffisant de wagons disponibles.");
            }
            
            // Assign wagons to train
            $assigned_wagons = assignWagonsToTrain($pdo, $train_id, $wagon_count, $request['date_start']);
            if (!$assigned_wagons) {
                throw new Exception("Erreur lors de l'assignation des wagons.");
            }

            $new_status = 'accepted';
            $stmt = $pdo->prepare("
                UPDATE freight_requests 
                SET status = ?, 
                    admin_notes = ?,
                    assigned_train_id = ?,
                    assigned_wagons = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $new_status, 
                $notes, 
                $train_id,
                '{' . implode(',', $assigned_wagons) . '}',
                $request_id
            ]);
            
            // Update train status and next available date
            $stmt = $pdo->prepare("
                UPDATE trains 
                SET status = 'assigned',
                    next_available_date = ?
                WHERE train_id = ?
            ");
            $stmt->execute([$_POST['eta'], $train_id]);

            $metadata = [
                'price' => $_POST['price'],
                'wagon_count' => $wagon_count,
                'eta' => $_POST['eta'],
                'origin' => $request['origin'],
                'destination' => $request['destination'],
                'train_number' => $train['train_number']
            ];
            
            $message = "Votre demande #$request_id a été approuvée. Prix: {$_POST['price']}€, " .
                      "Wagons: $wagon_count, Train: {$train['train_number']}, ETA: {$_POST['eta']}";
            $notification_type = 'request_approved';
            $notification_title = 'Demande Approuvée';
        } else {
            $new_status = 'rejected';
            $stmt = $pdo->prepare("UPDATE freight_requests SET status = ?, admin_notes = ? WHERE id = ?");
            $stmt->execute([$new_status, $notes, $request_id]);
            
            $metadata = ['reason' => $notes];
            $message = "Votre demande #$request_id a été refusée. Raison: $notes";
            $notification_type = 'demande_refusée';
            $notification_title = 'Demande Rejetée';
        }

        // Insert notification for sender
        $stmt = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, type, title, message, related_request_id, metadata) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $request['client_user_id'],
            $notification_type,
            $notification_title,
            $message,
            $request_id,
            json_encode($metadata)
        ]);

        $pdo->commit();
        $_SESSION['success'] = "Demande #$request_id " . ($action === 'approve' ? 'approuvée' : 'rejetée') . " avec succès";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: admin-dashboard.php");
    exit();
}

// Get all pending requests with sender client info
$pending_requests = $pdo->query("
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
    ORDER BY fr.created_at DESC
")->fetchAll();

// Get unread notifications
$notifications = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
    ORDER BY created_at DESC
    LIMIT 5
");
$notifications->execute([$admin_id]);
$notifications = $notifications->fetchAll();

// Get recent activity on requests
$recent_activity = $pdo->query("
    SELECT fr.id AS request_id, fr.status, fr.updated_at, 
           c.company_name, c.account_code,
           g1.libelle AS origin, g2.libelle AS destination,
           n.metadata, n.type as notification_type,
           n.created_at as notification_date
    FROM freight_requests fr
    JOIN clients c ON fr.sender_client_id = c.client_id
    JOIN gares g1 ON fr.gare_depart = g1.id_gare
    JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
    JOIN notifications n ON fr.id = n.related_request_id
    WHERE fr.status IN ('accepted', 'rejected', 'client_confirmed')
    AND n.type IN ('request_approved', 'request_rejected', 'client_confirmed')
    ORDER BY n.created_at DESC
    LIMIT 10
")->fetchAll();

// Replace your confirmed_requests query with this optimized version:
$confirmed_requests = $pdo->query("
    SELECT fr.id AS request_id, fr.*, 
           c.company_name, c.account_code,
           g1.libelle AS origin, g2.libelle AS destination,
           m.description AS merchandise, m.code AS merchandise_code,
           u.email AS client_email,
           (latest_notif.metadata->>'price')::numeric AS price,
           latest_notif.metadata->>'wagon_count' AS wagon_count_from_notif,
           latest_notif.metadata->>'eta' AS eta
    FROM freight_requests fr
    JOIN clients c ON fr.sender_client_id = c.client_id
    JOIN users u ON c.user_id = u.user_id
    JOIN gares g1 ON fr.gare_depart = g1.id_gare
    JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
    LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
    LEFT JOIN (
        SELECT related_request_id, metadata, created_at,
               ROW_NUMBER() OVER (PARTITION BY related_request_id ORDER BY created_at DESC) as rn
        FROM notifications
        WHERE type = 'request_approved'
    ) latest_notif ON fr.id = latest_notif.related_request_id AND latest_notif.rn = 1
    WHERE fr.status = 'client_confirmed'
    ORDER BY fr.updated_at DESC
    LIMIT 5
")->fetchAll();
?>


<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Admin | SNCFT</title>
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
                                $link = "admin-contract-details.php?id=" . (int)$request_id;
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
                        <li><a class="dropdown-item" href="admin-settings.php"><i class="fas fa-cog me-2"></i>Paramètres</a></li>
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

        <!-- Quick Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card bg-primary">
                    <i class="fas fa-clock"></i>
                    <div class="stat-value"><?= count($pending_requests) ?></div>
                    <div class="stat-label">Demandes en attente</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-success">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-value">12</div>
                    <div class="stat-label">Demandes approuvées</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card bg-danger">
                    <i class="fas fa-times-circle"></i>
                    <div class="stat-value">3</div>
                    <div class="stat-label">Demandes rejetées</div>
                </div>
            </div>
        </div>

<!-- Pending Requests Card -->
<div class="card dashboard-card mb-4 animate-fade">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-clock me-2 text-warning"></i>Demandes en Attente</h5>
        <span class="badge bg-primary rounded-pill"><?= count($pending_requests) ?></span>
    </div>
    <div class="card-body">
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
                            <th>Quantité</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_requests as $request): ?>
                            <tr>
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
                                <td>
                                    <?php if ($request['quantity_unit'] === 'wagons'): ?>
                                        <?= $request['wagon_count'] ?> wagons
                                    <?php else: ?>
                                        <?= number_format($request['quantity'], 0, ',', ' ') ?> kg
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($request['date_start'])) ?></td>
                                <td>
                                    <div class="d-flex">
                                        <button class="btn-action btn-outline-primary me-1" data-bs-toggle="modal"
                                            data-bs-target="#detailsModal<?= $request['request_id'] ?>"
                                            title="Voir détails">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-action btn-success me-1" data-bs-toggle="modal"
                                            data-bs-target="#approveModal<?= $request['request_id'] ?>"
                                            title="Approuver">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn-action btn-danger" data-bs-toggle="modal"
                                            data-bs-target="#rejectModal<?= $request['request_id'] ?>"
                                            title="Rejeter">
                                            <i class="fas fa-times"></i>
                                        </button>
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

<!-- Confirmed Requests Card -->
<div class="card dashboard-card mb-4 animate-fade">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-check-circle me-2 text-success"></i>Demandes Confirmées</h5>
        <span class="badge bg-success rounded-pill"><?= count($confirmed_requests) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($confirmed_requests)): ?>
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>Aucune demande confirmée récemment
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
                            <th>Quantité</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($confirmed_requests as $request): 
                            $meta = json_decode($request['metadata'] ?? '{}', true);
                            $quantity = ($request['quantity_unit'] === 'wagons') 
                                ? ($request['wagon_count'] ?? $meta['wagon_count'] ?? null)
                                : ($request['quantity'] ?? $meta['quantity'] ?? null);
                            $unit = $request['quantity_unit'] ?? ($meta['quantity_unit'] ?? 'kg');
                            $price = $meta['price'] ?? $request['price_quoted'] ?? null;
                        ?>
                            <tr>
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
                                <td><?= htmlspecialchars($request['merchandise'] ?? $request['description']) ?></td>
                                <td>
                                    <?php if (!empty($quantity)): ?>
                                        <i class="fas <?= $unit === 'wagons' ? 'fa-train text-primary' : 'fa-weight text-success' ?> me-1"></i>
                                        <?= number_format($quantity, 0, ',', ' ') ?>
                                        <?= $unit === 'wagons' ? 'wagons' : 'kg' ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex">
                                        <a href="admin-request-details.php?id=<?= $request['request_id'] ?>" 
                                           class="btn-action btn-outline-primary me-1"
                                           title="Voir détails">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="create_contract.php?request_id=<?= $request['request_id'] ?>" 
                                           class="btn-action btn-success"
                                           title="Créer contrat">
                                            <i class="fas fa-file-contract"></i>
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

<!-- Recent Activity Card -->
<div class="card dashboard-card animate-fade">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-history me-2 text-info"></i>Activité Récente</h5>
        <a href="admin-activity-log.php" class="btn btn-sm btn-outline-secondary">Voir tout</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent_activity)): ?>
            <div class="alert alert-info m-3">
                <i class="fas fa-info-circle me-2"></i>Aucune activité récente
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($recent_activity as $activity): 
                    $meta = json_decode($activity['metadata'], true);
                    $statusClass = '';
                    $statusIcon = '';
                    $statusText = '';
                    
                    switch($activity['notification_type']) {
                        case 'request_approved':
                            $statusClass = 'bg-success';
                            $statusIcon = 'fa-check-circle';
                            $statusText = 'Approuvée';
                            break;
                        case 'request_rejected':
                            $statusClass = 'bg-danger';
                            $statusIcon = 'fa-times-circle';
                            $statusText = 'Rejetée';
                            break;
                        case 'client_confirmed':
                            $statusClass = 'bg-info';
                            $statusIcon = 'fa-check-double';
                            $statusText = 'Confirmée';
                            break;
                    }
                ?>
                    <div class="list-group-item border-0 py-3 px-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="me-3">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="badge <?= $statusClass ?> me-2">
                                        <i class="fas <?= $statusIcon ?> me-1"></i><?= $statusText ?>
                                    </span>
                                    <strong>Demande #<?= $activity['request_id'] ?></strong>
                                </div>
                                <div class="text-muted small">
                                    <i class="fas fa-building me-1"></i><?= htmlspecialchars($activity['company_name']) ?>
                                    <?php if (!empty($activity['account_code'])): ?>
                                        <span class="ms-2 badge bg-secondary"><?= htmlspecialchars($activity['account_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="fas fa-route me-1"></i>
                                    <?= htmlspecialchars($activity['origin']) ?> → <?= htmlspecialchars($activity['destination']) ?>
                                </div>
                            </div>
                            <small class="text-muted text-nowrap">
                                <?= date('d/m/Y H:i', strtotime($activity['notification_date'])) ?>
                            </small>
                        </div>
                        <?php if ($activity['notification_type'] === 'request_approved' && $meta): ?>
                            <div class="mt-2 d-flex flex-wrap gap-2">
                                <?php if (isset($meta['price'])): ?>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-euro-sign text-success me-1"></i>
                                        <?= number_format($meta['price'], 2, ',', ' ') ?> €
                                    </span>
                                <?php endif; ?>
                                <?php if (isset($meta['wagon_count'])): ?>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-train text-primary me-1"></i>
                                        <?= $meta['wagon_count'] ?> wagons
                                    </span>
                                <?php endif; ?>
                                <?php if (isset($meta['eta'])): ?>
                                    <span class="badge bg-light text-dark">
                                        <i class="fas fa-calendar-check text-info me-1"></i>
                                        <?= htmlspecialchars($meta['eta']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
    </div>

    <!-- Modals for each request -->
    <?php foreach ($pending_requests as $request): ?>
        <!-- Details Modal -->
        <div class="modal fade" id="detailsModal<?= $request['request_id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-info-circle me-2"></i>Détails Demande #<?= $request['request_id'] ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-4">
                            <!-- Client Information Card -->
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-building me-2"></i>Informations Client</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Entreprise</strong></label>
                                            <p class="border-bottom pb-2"><?= !empty($request['company_name']) ? htmlspecialchars($request['company_name']) : '<span class="text-secondary fst-italic">Aucun</span>' ?></p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Contact du client Destinataire</strong></label>
                                            <p class="border-bottom pb-2"><?= !empty($request['recipient_contact']) ? htmlspecialchars($request['recipient_contact']) : '<span class="text-secondary fst-italic">Aucun</span>' ?></p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Email</strong></label>
                                            <p class="border-bottom pb-2">
                                                <?php if (!empty($request['client_email'])): ?>
                                                    <a href="mailto:<?= htmlspecialchars($request['client_email']) ?>" class="text-decoration-none">
                                                        <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($request['client_email']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-secondary fst-italic">Aucun</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Code Compte</strong></label>
                                            <p class="border-bottom pb-2">
                                                <?php if (!empty($request['account_code'])): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($request['account_code']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-secondary fst-italic">Aucun</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Shipping Details Card -->
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-truck me-2"></i>Détails Expédition</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Trajet</strong></label>
                                            <p class="border-bottom pb-2">
                                                <?php if (!empty($request['origin']) && !empty($request['destination'])): ?>
                                                    <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($request['origin']) ?>
                                                    <i class="fas fa-arrow-right mx-2"></i>
                                                    <i class="fas fa-map-marker-alt text-success me-1"></i><?= htmlspecialchars($request['destination']) ?>
                                                <?php else: ?>
                                                    <span class="text-secondary fst-italic">Aucun</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Marchandise</strong></label>
                                            <p class="border-bottom pb-2">
                                                <?php 
                                                $merchandise = !empty($request['merchandise']) ? $request['merchandise'] : (!empty($request['description']) ? $request['description'] : '');
                                                if (!empty($merchandise)): 
                                                ?>
                                                    <i class="fas fa-box me-1"></i><?= htmlspecialchars($merchandise) ?>
                                                    <?php if (!empty($request['merchandise_code'])): ?>
                                                        <br><small class="text-muted">Code: <?= htmlspecialchars($request['merchandise_code']) ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-secondary fst-italic">Aucun</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
<div class="mb-3">
    <label class="form-label text-muted"><strong>Quantité</strong></label>
    <p class="border-bottom pb-2">
        <?php if (!empty($request['quantity_unit'])): ?>
            <?php if ($request['quantity_unit'] === 'wagons' && !empty($request['wagon_count'])): ?>
                <i class="fas fa-train me-1"></i>
                <?= number_format($request['wagon_count'], 0, ',', ' ') ?> wagons
            <?php elseif ($request['quantity_unit'] === 'kg' && !empty($request['quantity'])): ?>
                <i class="fas fa-weight me-1"></i>
                <?= number_format($request['quantity'], 0, ',', ' ') ?> kg
            <?php else: ?>
                <span class="text-secondary fst-italic">Aucun</span>
            <?php endif; ?>
        <?php else: ?>
            <span class="text-secondary fst-italic">Aucun</span>
        <?php endif; ?>
    </p>
</div>

                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Date souhaitée</strong></label>
                                            <p class="border-bottom pb-2">
                                                <?php if (!empty($request['date_start'])): ?>
                                                    <i class="far fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($request['date_start'])) ?>
                                                <?php else: ?>
                                                    <span class="text-secondary fst-italic">Aucun</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-muted"><strong>Mode de paiement</strong></label>
                                            <p class="border-bottom pb-2">
                                                <?php if (!empty($request['mode_paiement'])): ?>
                                                    <i class="fas fa-credit-card me-1"></i><?= htmlspecialchars($request['mode_paiement']) ?>
                                                <?php else: ?>
                                                    <span class="text-secondary fst-italic">Aucun</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Fermer
                        </button>
                    </div>
                </div>
            </div>
        </div>

      <!-- Approve Modal -->
<div class="modal fade" id="approveModal<?= $request['request_id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="approvalForm<?= $request['request_id'] ?>">
                <input type="hidden" name="request_id" value="<?= $request['request_id'] ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" id="client_id_<?= $request['request_id'] ?>" value="<?= $request['sender_client_id'] ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Approuver Demande #<?= $request['request_id'] ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Train Selection -->
                    <div class="mb-4">
                        <h6><i class="fas fa-train me-2"></i>Sélection du Train</h6>
                        <?php 
                        $available_trains = getAvailableTrains($pdo, $request['date_start']);
                        if (empty($available_trains)): 
                        ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Aucun train disponible pour la date demandée.
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Train Disponible <span class="text-danger">*</span></label>
                                <select name="train_id" class="form-select" required>
                                    <option value="">-- Sélectionnez un train --</option>
                                    <?php foreach ($available_trains as $train): ?>
                                        <option value="<?= $train['train_id'] ?>">
                                            Train #<?= htmlspecialchars($train['train_number']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        </div>
                        
                    <!-- Wagon Availability -->
                    <div class="mb-4">
                        <h6><i class="fas fa-boxes me-2"></i>Disponibilité des Wagons</h6>
                        <?php
                        $requested_wagons = $request['quantity_unit'] === 'wagons' 
                            ? $request['wagon_count'] 
                            : ceil($request['quantity'] / 1000); // Assuming 1 wagon = 1000kg
                        
                        $wagons_available = getAvailableWagons($pdo, $request['date_start'], $requested_wagons);
                        ?>
                        
                        <div class="alert <?= $wagons_available ? 'alert-success' : 'alert-warning' ?>">
                            <i class="fas <?= $wagons_available ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i>
                            <?php if ($wagons_available): ?>
                                <?= $requested_wagons ?> wagon(s) disponible(s) pour la date demandée.
                            <?php else: ?>
                                Nombre insuffisant de wagons disponibles (<?= $requested_wagons ?> demandés).
                            <?php endif; ?>
                            </div>

                        <div class="mb-3">
                            <label class="form-label">Nombre de Wagons à Assigner <span class="text-danger">*</span></label>
                            <input type="number" name="wagon_count" id="wagon_count_<?= $request['request_id'] ?>" 
                                class="form-control wagon-input" min="1" 
                                value="<?= $requested_wagons ?>" 
                                max="<?= $wagons_available ? $requested_wagons : 0 ?>"
                                required>
                            <small class="form-text text-muted">
                                Basé sur <?= $request['quantity_unit'] === 'wagons' ? 'la demande directe' : 'le poids total' ?>
                            </small>
                            </div>
                    </div>

                    <!-- Price Calculator -->
                    <div class="price-calculator mb-4">
                        <h6><i class="fas fa-calculator me-2"></i>Calculateur de Prix</h6>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            Le prix est calculé automatiquement en fonction du tarif client et de la distance.
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Distance Estimée (km)</label>
                                <input type="number" class="form-control distance-input" 
                                    id="distance_<?= $request['request_id'] ?>"
                                    data-origin="<?= $request['gare_depart'] ?>" 
                                    data-destination="<?= $request['gare_arrivee'] ?>"
                                    readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tarif Client (€/km)</label>
                                <input type="number" class="form-control tariff-input" 
                                    id="tariff_<?= $request['request_id'] ?>" 
                                    step="0.01" readonly>
</div>
                            <div class="col-md-6">
                                <label class="form-label">Prix Calculé (€)</label>
                                <input type="number" class="form-control calculated-price" 
                                    id="calculated_price_<?= $request['request_id'] ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Prix Final (€) <span class="text-danger">*</span></label>
                                <input type="number" name="price" id="final_price_<?= $request['request_id'] ?>" 
                                    class="form-control price-input" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Date estimée d'arrivée <span class="text-danger">*</span></label>
                        <input type="date" name="eta" class="form-control" 
                            min="<?= date('Y-m-d', strtotime($request['date_start'])) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes (optionnel)</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Annuler
                    </button>
                    <button type="submit" class="btn btn-success" <?= (!$wagons_available || empty($available_trains)) ? 'disabled' : '' ?>>
                        <i class="fas fa-check me-1"></i>Confirmer l'approbation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

        <!-- Reject Modal -->
        <div class="modal fade" id="rejectModal<?= $request['request_id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="request_id" value="<?= $request['request_id'] ?>">
                        <input type="hidden" name="action" value="deny">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-times-circle me-2"></i>Refuser Demande #<?= $request['request_id'] ?>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Cette action ne peut pas être annulée. Le client sera notifié du refus.
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Raison du refus <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="notes" rows="4" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Annuler
                            </button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-ban me-1"></i>Confirmer le refus
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
// Price calculator functionality - Enhanced version
document.querySelectorAll('.price-calculator').forEach(calculator => {
    const requestId = calculator.closest('.modal').id.replace('approveModal', '');
    const clientId = document.getElementById(`client_id_${requestId}`).value;
    const origin = document.getElementById(`distance_${requestId}`).dataset.origin;
    const destination = document.getElementById(`distance_${requestId}`).dataset.destination;
    const wagonInput = document.getElementById(`wagon_count_${requestId}`);
    const priceInput = document.getElementById(`final_price_${requestId}`);
    const calculatedPrice = document.getElementById(`calculated_price_${requestId}`);
    const form = document.getElementById(`approvalForm${requestId}`);
    
    // Function to calculate price via AJAX
    const calculatePrice = async () => {
        const wagonCount = wagonInput.value || 1;
        
        try {
            const response = await fetch(`calculate-price.php?client_id=${clientId}&origin=${origin}&destination=${destination}&wagon_count=${wagonCount}`);
            const data = await response.json();
            
            if (data.success) {
                calculatedPrice.value = data.price.toFixed(2);
                priceInput.value = data.price.toFixed(2);
            } else {
                calculatedPrice.value = '';
                priceInput.value = '';
                alert('Erreur lors du calcul du prix: ' + data.error);
            }
        } catch (error) {
            console.error('Error calculating price:', error);
            alert('Erreur lors du calcul du prix');
        }
    };
    
    // Calculate when wagon count changes
    wagonInput.addEventListener('change', calculatePrice);
    
    // Validate form before submission
    form.addEventListener('submit', function(e) {
        const trainSelect = this.querySelector('select[name="train_id"]');
        const wagonCount = wagonInput.value;
        const price = priceInput.value;
        const eta = this.querySelector('input[name="eta"]').value;
        
        if (!trainSelect.value) {
            e.preventDefault();
            alert('Veuillez sélectionner un train');
            return;
        }
        
        if (!wagonCount || wagonCount < 1) {
            e.preventDefault();
            alert('Le nombre de wagons doit être supérieur à 0');
            return;
        }
        
        if (!price || price <= 0) {
            e.preventDefault();
            alert('Le prix doit être supérieur à 0');
            return;
        }
        
        if (!eta) {
            e.preventDefault();
            alert('Veuillez spécifier une date d\'arrivée estimée');
            return;
        }
    });
    
    // Auto-calculate on modal show
    const modal = calculator.closest('.modal');
    modal.addEventListener('shown.bs.modal', calculatePrice);
});

// Toggle sidebar on mobile
document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
    document.querySelector('.admin-sidebar').classList.toggle('show');
});
    </script>
</body>
</html>

<?php
// Helper function to display time elapsed
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