<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';
require_once 'includes/pricing_functions.php';

if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    redirect('/index.php');
}

// Fetch admin info from session or database
if (!isset($admin)) {
    $admin = $_SESSION['user'] ?? [
        'full_name' => 'Administrateur',
        'department' => 'Admin',
        'access_level' => 1
    ];
}

// Fetch unread notifications for the current user
$userId = $_SESSION['user']['user_id'] ?? null;
$notifications = [];

if ($userId) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE recipient_id = ? AND is_read = 0 ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);

if (!$requestId) {
    $_SESSION['error'] = "ID de demande invalide";
    redirect('admin-dashboard.php');
}

// Fetch the request
$stmt = $pdo->prepare("
    SELECT fr.*, 
           c.company_name, c.account_code, c.client_id,
           g1.libelle AS origin, g1.code_gare AS origin_code, 
           g2.libelle AS destination, g2.code_gare AS destination_code,
           m.description AS merchandise, m.code AS merchandise_code
    FROM freight_requests fr
    JOIN clients c ON fr.sender_client_id = c.client_id
    JOIN gares g1 ON fr.gare_depart = g1.id_gare
    JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
    LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
    WHERE fr.id = ? AND fr.status = 'client_confirmed'
    LIMIT 1
");
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    $_SESSION['error'] = "Demande non trouvée ou non confirmée";
    redirect('admin-dashboard.php');
}

// Fetch the notification with title 'Demande Approuvée' for this request
$notifStmt = $pdo->prepare("
    SELECT metadata
    FROM notifications
    WHERE related_request_id = ?
      AND title = 'Demande Approuvée'
    ORDER BY created_at DESC
    LIMIT 1
");
$notifStmt->execute([$requestId]);
$notif = $notifStmt->fetch();
$metaData = [];
if ($notif && !empty($notif['metadata'])) {
    $metaData = json_decode($notif['metadata'], true) ?? [];
}

// Prefill values from metadata
$defaultShipmentDate = $metaData['eta'] ?? date('Y-m-d', strtotime($request['date_start']));
$defaultTrainId = $request['assigned_train_id'] ?? null;
$defaultTrainNumber = $metaData['train_number'] ?? null;
$defaultWagonCount = $metaData['wagon_count'] ?? ($request['wagon_count'] ?? 1);
$defaultPrice = $metaData['price'] ?? null;
$defaultWeight = $metaData['weight'] ?? $request['quantity'];
$defaultCurrency = $metaData['currency'] ?? 'EUR';

// Get available agents
$stmt = $pdo->prepare("
    SELECT a.agent_id, a.badge_number, u.username, u.user_id, g.libelle AS station, g.id_gare
    FROM agents a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN gares g ON a.id_gare = g.id_gare
    WHERE u.is_active = TRUE
    AND (a.id_gare IS NULL OR a.id_gare = ?)
    ORDER BY g.libelle, u.username
");
$stmt->execute([$request['gare_depart']]);
$agents = $stmt->fetchAll();

// Group agents by station
$agentsByStation = [];
foreach ($agents as $agent) {
    $stationName = $agent['station'] ?? 'Non assigné';
    if (!isset($agentsByStation[$stationName])) {
        $agentsByStation[$stationName] = [];
    }
    $agentsByStation[$stationName][] = $agent;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $requiredFields = [
            'transaction_type' => 'Type de transaction',
            'shipment_date' => 'Date d\'expédition',
            'price_quoted' => 'Prix proposé',
            'wagon_count' => 'Nombre de wagons',
            'agent_id' => 'Agent assigné'
        ];
        
        foreach ($requiredFields as $field => $name) {
            if (empty($_POST[$field])) {
                throw new Exception("Le champ '$name' est requis");
            }
        }

        $pdo->beginTransaction();
        
        // Prepare contract data
        $contractData = [
            'transaction_type' => $_POST['transaction_type'],
            'gare_expéditrice' => $request['gare_depart'],
            'gare_destinataire' => $request['gare_arrivee'],
            'source_branch' => $request['origin'],
            'destination_branch' => $request['destination'],
            'sender_client' => $request['sender_client_id'],
            'recipient_client' => $request['recipient_client_id'] ?? null,
            'merchandise_description' => $request['merchandise'] ?? $request['description'],
            'shipment_weight' => $defaultWeight,
            'payment_mode' => $request['mode_paiement'],
            'shipment_date' => $_POST['shipment_date'],
            'total_port_due' => $_POST['price_quoted'],
            'wagon_count' => $_POST['wagon_count'],
            'agent_id' => $_POST['agent_id'],
            'train_id' => $request['assigned_train_id'],
            'status' => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
            'freight_request_id' => $requestId
        ];
        
        // Insert contract
        $insertFields = implode(', ', array_keys($contractData));
        $placeholders = implode(', ', array_fill(0, count($contractData), '?'));
        
        $stmt = $pdo->prepare("
            INSERT INTO contracts ($insertFields)
            VALUES ($placeholders)
        ");
        $stmt->execute(array_values($contractData));
        $contractId = $pdo->lastInsertId();

        // Get agent's user_id
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.username
            FROM agents a
            JOIN users u ON a.user_id = u.user_id
            WHERE a.agent_id = ?
        ");
        $stmt->execute([$_POST['agent_id']]);
        $agent = $stmt->fetch();
        
        if (!$agent) {
            throw new Exception("Agent introuvable");
        }
        
        // Create contract draft notification with metadata
        $notificationData = [
            'contract_id' => $contractId,
            'request_id' => $requestId,
            'client_name' => $request['company_name'],
            'origin' => $request['origin'],
            'destination' => $request['destination'],
            'merchandise' => $request['merchandise'] ?? $request['description'],
            'quantity' => $defaultWeight,
            'price_quoted' => $_POST['price_quoted'],
            'wagon_count' => $_POST['wagon_count'],
            'transaction_type' => $_POST['transaction_type'],
            'shipment_date' => $_POST['shipment_date']
        ];
        
        createNotification(
            $agent['user_id'],
            'contract_draft',
            'Nouveau Contrat à Compléter',
            "Un nouveau projet de contrat #$contractId a été créé pour la demande #$requestId. Veuillez compléter les détails et finaliser le contrat.",
            $requestId,
            $notificationData
        );
        
        $pdo->commit();
        $_SESSION['success'] = "Projet de contrat créé avec succès et envoyé à l'agent " . $agent['username'];
        redirect('admin-dashboard.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Creation du contract | SNCFT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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

        .avatar-sm {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
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

        .form-section {
            border-left: 4px solid var(--primary-color);
            padding-left: 15px;
            margin-bottom: 30px;
        }

        .form-section h5 {
            color: var(--primary-color);
            font-weight: 600;
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-color);
        }

        .locked-field {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade {
            animation: fadeIn 0.3s ease-out forwards;
        }

        @media (max-width: 768px) {
            .form-section {
                padding-left: 10px;
            }
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
                                        $link = '#';
                                        switch ($notif['type']) {
                                            case 'client_confirmed':
                                            case 'client_refused':
                                            case 'request_rejected':
                                                $link = "request_details.php?id=" . $notif['related_request_id'];
                                                break;
                                            case 'nouvelle_demande':
                                                $link = "pending-requests.php?highlight=" . $notif['related_request_id'];
                                                break;
                                        }
                                        $timeAgo = time_elapsed_string($notif['created_at']);
                                    ?>
                                    <li>
                                        <a class="dropdown-item p-3 border-bottom <?= $notif['is_read'] ? '' : 'bg-light' ?>" 
                                           href="<?= $link ?>">
                                            <div class="d-flex">
                                                <div class="flex-shrink-0 me-3">
                                                    <?php if ($notif['type'] === 'nouvelle_demande'): ?>
                                                        <i class="fas fa-file-alt text-primary"></i>
                                                    <?php elseif ($notif['type'] === 'client_confirmed'): ?>
                                                        <i class="fas fa-check-circle text-success"></i>
                                                    <?php elseif ($notif['type'] === 'client_refused' || $notif['type'] === 'request_rejected'): ?>
                                                        <i class="fas fa-times-circle text-danger"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-bell text-info"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between">
                                                        <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                                        <small class="text-muted"><?= $timeAgo ?></small>
                                                    </div>
                                                    <div class="text-muted small"><?= htmlspecialchars($notif['message']) ?></div>
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
        <div class="admin-profile">
            <div class="admin-avatar">
                <i class="fas fa-user-shield"></i>
            </div>
            <h5 class="mt-3 mb-0"><?= htmlspecialchars($admin['department'] ?? 'Administrateur') ?></h5>
            <small class="text-white-50">Niveau d'accès: <?= $admin['access_level'] ?? 1 ?></small>
        </div>
        
        <ul class="nav flex-column px-3">
            <li class="nav-item">
                <a class="nav-link" href="admin-dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Tableau de bord
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage-clients.php">
                    <i class="fas fa-users"></i> Gestion Clients
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage-stations.php">
                    <i class="fas fa-train"></i> Gestion Gares
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage-tariffs.php">
                    <i class="fas fa-money-bill-wave"></i> Tarifs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="admin-settings.php">
                    <i class="fas fa-cog"></i> Paramètres
                </a>
            </li>
            <li class="nav-item active">
                <a class="nav-link" href="#">
                    <i class="fas fa-file-contract"></i> Créer Contrat
                </a>
            </li>
        </ul>
    </div>

       <!-- Main Content -->
    <div class="main-content">
        <!-- Alerts -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate-fade">
                <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="card dashboard-card animate-fade">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0"><i class="fas fa-file-contract me-2"></i>Créer un Contrat</h4>
                                <p class="mb-0">Demande #<?= $requestId ?> - <?= htmlspecialchars($request['company_name']) ?></p>
                            </div>
                            <a href="admin-dashboard.php" class="btn btn-outline-light">
                                <i class="fas fa-arrow-left me-2"></i>Retour
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="contractForm">
                            <!-- Client Information -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-building me-2"></i>Information Client</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Client Expéditeur</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($request['company_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Code Compte</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($request['account_code']) ?>" readonly>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Numéro Fiscal</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($request[''] ?? 'Non spécifié') ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Client Destinataire</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($request['recipient_name'] ?? 'Même que l\'expéditeur') ?>" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Shipment Information -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-train me-2"></i>Détails Expédition</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Type de Transaction <span class="text-danger">*</span></label>
                                        <select name="transaction_type" class="form-select" required>
                                            <option value="">Sélectionner...</option>
                                            <option value="export">Exportation</option>
                                            <option value="import">Importation</option>
                                            <option value="national" selected>Transport National</option>
                                            <option value="transit">Transit</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Date d'Expédition <span class="text-danger">*</span></label>
                                        <input type="date" name="shipment_date" class="form-control" value="<?= htmlspecialchars($defaultShipmentDate) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Train Assigné</label>
                                        <div class="input-group">
                                            <?php if ($defaultTrainId): ?>
                                                <span class="input-group-text">ID: <?= htmlspecialchars($defaultTrainId) ?></span>
                                            <?php endif; ?>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($defaultTrainNumber ?? 'Non assigné') ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Mode de Paiement</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($request['mode_paiement']) ?>" readonly>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Gare d'Origine</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?= htmlspecialchars($request['origin_code'] ?? '') ?></span>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($request['origin']) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Gare de Destination</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?= htmlspecialchars($request['destination_code'] ?? '') ?></span>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($request['destination']) ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Merchandise Information -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-box me-2"></i>Détails Marchandise</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Marchandise</label>
                                        <div class="input-group">
                                            <?php if ($request['merchandise_code']): ?>
                                                <span class="input-group-text"><?= htmlspecialchars($request['merchandise_code']) ?></span>
                                            <?php endif; ?>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($request['merchandise'] ?? $request['description']) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Poids (kg)</label>
                                        <input type="number" class="form-control" value="<?= htmlspecialchars($defaultWeight) ?>" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Nombre de Wagons <span class="text-danger">*</span></label>
                                        <input type="number" name="wagon_count" class="form-control" value="<?= htmlspecialchars($defaultWagonCount) ?>" min="1" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Financial Information -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-money-bill-wave me-2"></i>Informations Financières</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Prix Proposé (€) <span class="text-danger">*</span></label>
                                        <input type="number" name="price_quoted" class="form-control" value="<?= htmlspecialchars($defaultPrice) ?>" step="0.01" min="0" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Devise</label>
                                        <select name="currency" class="form-select">
                                            <option value="EUR" <?= $defaultCurrency === 'EUR' ? 'selected' : '' ?>>Euro (€)</option>
                                            <option value="USD" <?= $defaultCurrency === 'USD' ? 'selected' : '' ?>>Dollar US ($)</option>
                                            <option value="TND" <?= $defaultCurrency === 'TND' ? 'selected' : '' ?>>Dinar Tunisien (DT)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Agent Assignment -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-user-tie me-2"></i>Attribution Agent</h5>
                                <div class="mb-3">
                                    <label class="form-label">Agent Assigné <span class="text-danger">*</span></label>
                                    <select name="agent_id" class="form-select" required>
                                        <option value="">Sélectionner un agent</option>
                                        <?php foreach ($agentsByStation as $station => $stationAgents): ?>
                                            <optgroup label="<?= htmlspecialchars($station) ?>">
                                                <?php foreach ($stationAgents as $agent): ?>
                                                    <option value="<?= $agent['agent_id'] ?>" <?= isset($_POST['agent_id']) && $_POST['agent_id'] == $agent['agent_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($agent['username']) ?> 
                                                        <?php if ($agent['badge_number']): ?>
                                                            (Badge: <?= htmlspecialchars($agent['badge_number']) ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">
                                        L'agent recevra une notification pour compléter et finaliser ce contrat
                                    </small>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="admin-dashboard.php" class="btn btn-outline-secondary me-md-2">
                                    <i class="fas fa-times me-2"></i> Annuler
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i> Envoyer le Projet de Contrat
                                </button>
                            </div>
                        </form>
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

        // Client-side form validation
        document.getElementById('contractForm').addEventListener('submit', function(e) {
            let valid = true;
            const requiredFields = [
                'transaction_type', 'shipment_date', 'price_quoted', 'wagon_count', 'agent_id'
            ];
            
            requiredFields.forEach(field => {
                const input = document.querySelector(`[name="${field}"]`);
                if (!input.value) {
                    alert(`Veuillez remplir le champ ${input.previousElementSibling.textContent.trim()}`);
                    input.focus();
                    valid = false;
                    e.preventDefault();
                    return false;
                }
            });
            
            return valid;
        });
    </script>
</body>
</html>