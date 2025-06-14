<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

// Get agent data
$stmt = $pdo->prepare("SELECT a.*, u.email FROM agents a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();
// Get draft notifications
$draftNotifications = getDraftNotifications($pdo, $_SESSION['user']['id']);
$draftNotificationCount = count($draftNotifications);
// Get stats
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM contracts 
    WHERE status = 'draft' AND agent_id = ?
");
$stmt->execute([$agent['agent_id']]);
$pending_contracts = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM contracts 
    WHERE status = 'in_transit' AND agent_id = ?
");
$stmt->execute([$agent['agent_id']]);
$active_shipments = $stmt->fetchColumn();

// Get total completed contracts count
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM contracts 
    WHERE status = 'completed' AND agent_id = ?
");
$stmt->execute([$agent['agent_id']]);
$completed_contracts = $stmt->fetchColumn();

// Get total clients count
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sender_client)
    FROM contracts 
    WHERE agent_id = ?
");
$stmt->execute([$agent['agent_id']]);
$total_clients = $stmt->fetchColumn();

// Get unread notifications
$notifications = getUnreadNotificationsAgent($pdo, $_SESSION['user']['id']);
$notification_count = count($notifications);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Agent | SNCFT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            --primary-color: #1a3c8f;
            --secondary-color: #f8f9fa;
            --accent-color: #3459c0;
            --success-color: #198754;
            --info-color: #0dcaf0;
            --warning-color: #ffc107;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-brand {
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        
        .bg-primary {
            background-color: var(--primary-color) !important;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .sidebar {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .sidebar .nav-link {
            color: #495057;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover, 
        .sidebar .nav-link.active {
            color: var(--primary-color);
            background-color: rgba(26, 60, 143, 0.1);
            font-weight: 500;
        }
        
        .sidebar .nav-link i {
            width: 24px;
        }
        
        .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            transition: transform 0.3s;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card {
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card .icon-circle {
            position: absolute;
            right: 20px;
            top: 20px;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .stat-card .icon-circle i {
            font-size: 1.5rem;
        }
        
        .stat-card .card-title {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .quick-action-card {
            transition: all 0.3s;
        }
        
        .quick-action-card:hover {
            background-color: #f8f9fa;
        }
        
        .badge {
            padding: 0.5em 0.75em;
        }
        
        .table {
            vertical-align: middle;
        }
        
        .card-header {
            border-bottom: none;
            padding: 1.25rem 1.25rem 0.5rem;
        }
        
        .card-footer {
            background-color: white;
            border-top: none;
            text-align: center;
        }
        
        /* Custom badge colors */
        .bg-draft {
            background-color: var(--warning-color);
        }
        
        .bg-in-transit {
            background-color: var(--info-color);
        }
        
        .bg-completed {
            background-color: var(--success-color);
        }
        
        /* Responsive adjustments */
        @media (max-width: 991px) {
            .sidebar {
                margin-bottom: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-train me-2"></i>SNCFT Agent
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-sm-inline">
                    <i class="fas fa-id-badge me-1"></i> <?= htmlspecialchars($agent['badge_number']) ?>
                </span>
                <?php include 'includes/agent_notification_dropdown.php'; ?>
                <?php include 'includes/notification_styles.php'; ?>
    
</style>
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profil</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Paramètres</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-3">
                <div class="sidebar p-3 shadow-sm">
                    <div class="text-center mb-4 p-3">
                        <div class="avatar bg-primary text-white rounded-circle mb-3 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-user-tie fa-2x"></i>
                        </div>
                        <h5 class="mb-1"><?= htmlspecialchars($_SESSION['user']['username']) ?></h5>
                        <span class="badge bg-primary">Agent</span>
                        <div class="text-muted mt-2 small">
                            <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($agent['email']) ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="agent-dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i> Tableau de bord
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pending-contracts.php">
                                <i class="fas fa-clock me-2"></i> Contrats en attente
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="agent-contracts.php">
                                <i class="fas fa-clipboard-list me-2"></i> Tous les contrats
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="agent-expeditions.php">
                                <i class="fas fa-truck-loading me-2"></i> Expéditions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="payments.php">
                                <i class="fas fa-money-bill-wave me-2"></i> Paiements
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="col-lg-9">
                <!-- Welcome Banner -->
                <div class="card mb-4 bg-primary text-white">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1">Bienvenue, <?= htmlspecialchars($_SESSION['user']['username']) ?></h4>
                                <p class="mb-0">Voici votre tableau de bord. Bon travail !</p>
                            </div>
                            <div class="display-4">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card bg-primary text-white h-100">
                            <div class="card-body">
                                <div class="icon-circle">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <h6 class="card-title">Contrats en attente</h6>
                                <h2 class="mb-0"><?= $pending_contracts ?></h2>
                                <p class="card-text mt-2 small">
                                    <a href="pending-contracts.php" class="text-white">Voir tous <i class="fas fa-arrow-right ms-1"></i></a>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card bg-info text-white h-100">
                            <div class="card-body">
                                <div class="icon-circle">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <h6 class="card-title">Expéditions actives</h6>
                                <h2 class="mb-0"><?= $active_shipments ?></h2>
                                <p class="card-text mt-2 small">
                                    <a href="agent-expeditions.php" class="text-white">Voir toutes <i class="fas fa-arrow-right ms-1"></i></a>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card bg-success text-white h-100">
                            <div class="card-body">
                                <div class="icon-circle">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <h6 class="card-title">Contrats terminés</h6>
                                <h2 class="mb-0"><?= $completed_contracts ?></h2>
                                <p class="card-text mt-2 small">
                                    <a href="contracts.php?status=completed" class="text-white">Voir tous <i class="fas fa-arrow-right ms-1"></i></a>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-xl-3">
                        <div class="card stat-card bg-warning text-white h-100">
                            <div class="card-body">
                                <div class="icon-circle">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h6 class="card-title">Total Clients</h6>
                                <h2 class="mb-0"><?= $total_clients ?></h2>
                                <p class="card-text mt-2 small">
                                    <a href="clients.php" class="text-white">Voir tous <i class="fas fa-arrow-right ms-1"></i></a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-4 shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2 text-primary"></i>Actions rapides</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="row g-0">
                            <div class="col-md-4 p-3 border-end quick-action-card">
                                <a href="new-contract.php" class="text-decoration-none text-dark d-block text-center">
                                    <div class="py-3">
                                        <i class="fas fa-file-contract fa-3x text-primary mb-3"></i>
                                        <h6>Nouveau contrat</h6>
                                        <p class="text-muted small mb-0">Créer un nouveau contrat</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-4 p-3 border-end quick-action-card">
                                <a href="payments.php" class="text-decoration-none text-dark d-block text-center">
                                    <div class="py-3">
                                        <i class="fas fa-money-bill-wave fa-3x text-success mb-3"></i>
                                        <h6>Paiement</h6>
                                        <p class="text-muted small mb-0">Enregistrer un paiement</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-4 p-3 quick-action-card">
                                <a href="arrivals.php" class="text-decoration-none text-dark d-block text-center">
                                    <div class="py-3">
                                        <i class="fas fa-train fa-3x text-info mb-3"></i>
                                        <h6>Arrivages</h6>
                                        <p class="text-muted small mb-0">Consulter les arrivages</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contract Drafts -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-file-alt me-2 text-warning"></i>Contrats à Compléter</h5>
                        <a href="pending-contracts.php" class="btn btn-sm btn-outline-primary">Voir tous</a>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        $draft_contracts = $pdo->prepare("
                            SELECT c.contract_id, c.created_at, 
                                   cl.company_name AS client_name,
                                   g1.libelle AS origin, g2.libelle AS destination
                            FROM contracts c
                            JOIN clients cl ON c.sender_client = cl.client_id
                            JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
                            JOIN gares g2 ON c.gare_destinataire = g2.id_gare
                            WHERE c.status = 'draft' AND c.agent_id = ?
                            ORDER BY c.created_at DESC
                            LIMIT 5
                        ");
                        $draft_contracts->execute([$agent['agent_id']]);
                        $drafts = $draft_contracts->fetchAll();
                        ?>
                        
                        <?php if (empty($drafts)): ?>
                            <div class="p-4 text-center">
                                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                <p class="mb-0">Aucun contrat à compléter</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Client</th>
                                            <th>Trajet</th>
                                            <th>Créé le</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($drafts as $draft): ?>
                                        <tr>
                                            <td>CT-<?= $draft['contract_id'] ?></td>
                                            <td><?= htmlspecialchars($draft['client_name']) ?></td>
                                            <td>
                                                <span class="d-inline-block text-truncate" style="max-width: 150px;">
                                                    <?= htmlspecialchars($draft['origin']) ?> 
                                                    <i class="fas fa-arrow-right mx-1"></i> 
                                                    <?= htmlspecialchars($draft['destination']) ?>
                                                </span>
                                            </td>
                                            <td><?= date('d/m/Y', strtotime($draft['created_at'])) ?></td>
                                            <td>
                                                <a href="complete_contract.php?id=<?= $draft['contract_id'] ?>" 
                                                   class="btn btn-sm btn-primary">
                                                   <i class="fas fa-edit"></i> Compléter
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Contracts -->
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>Contrats récents</h5>
                        <a href="contracts.php" class="btn btn-sm btn-outline-primary">Voir tous</a>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        $recent_contracts = $pdo->prepare("
                            SELECT c.contract_id, c.status, c.created_at,
                                   cl.company_name,
                                   g1.libelle AS origin, g2.libelle AS destination
                            FROM contracts c
                            JOIN clients cl ON c.sender_client = cl.client_id
                            JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
                            JOIN gares g2 ON c.gare_destinataire = g2.id_gare
                            WHERE c.agent_id = ?
                            ORDER BY c.created_at DESC
                            LIMIT 5
                        ");
                        $recent_contracts->execute([$agent['agent_id']]);
                        $contracts = $recent_contracts->fetchAll();
                        ?>
                        
                        <?php if (empty($contracts)): ?>
                            <div class="p-4 text-center">
                                <i class="fas fa-info-circle fa-3x text-info mb-3"></i>
                                <p class="mb-0">Aucun contrat récent</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Client</th>
                                            <th>Trajet</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($contracts as $contract): ?>
                                        <tr>
                                            <td>CT-<?= $contract['contract_id'] ?></td>
                                            <td><?= htmlspecialchars($contract['company_name']) ?></td>
                                            <td>
                                                <span class="d-inline-block text-truncate" style="max-width: 150px;">
                                                    <?= htmlspecialchars($contract['origin']) ?> → <?= htmlspecialchars($contract['destination']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge 
                                                    <?= $contract['status'] == 'draft' ? 'bg-draft' : 
                                                       ($contract['status'] == 'in_transit' ? 'bg-in-transit' : 'bg-completed') ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $contract['status'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                                                        Actions
                                                    </button>
                                                    <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                                                        <li>
                                                            <a class="dropdown-item" href="agent-contract-details.php?id=<?= $contract['contract_id'] ?>">
                                                                <i class="fas fa-eye me-2"></i>Voir détails
                                                            </a>
                                                        </li>
                                                        <?php if($contract['status'] == 'draft'): ?>
                                                        <li>
                                                            <a class="dropdown-item" href="complete_contract.php?id=<?= $contract['contract_id'] ?>">
                                                                <i class="fas fa-edit me-2"></i>Éditer
                                                            </a>
                                                        </li>
                                                        <?php endif; ?>
                                                        <li>
                                                            <a class="dropdown-item" href="print-contract.php?id=<?= $contract['contract_id'] ?>" target="_blank">
                                                                <i class="fas fa-print me-2"></i>Imprimer
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="agent-contracts.php" class="btn btn-sm btn-outline-primary">
                            Voir tous les contrats
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white py-4 mt-5 border-top">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0 text-muted">&copy; <?= date('Y') ?> SNCFT - Société Nationale des Chemins de Fer Tunisiens</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0 text-muted">Système de gestion des contrats de fret</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>