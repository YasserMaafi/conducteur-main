<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

// Get contract ID
$contract_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contract_id) {
    $_SESSION['error'] = "ID de contrat invalide";
    redirect('agent-dashboard.php');
}

// Get agent data
$stmt = $pdo->prepare("SELECT a.*, u.email FROM agents a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();

// Get draft notifications
$draftNotifications = getDraftNotifications($pdo, $_SESSION['user']['id']);
$draftNotificationCount = count($draftNotifications);

// Get contract details with all prefilled info
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name, cl.client_id, cl.account_code,
           g1.libelle AS origin, g1.code_gare AS origin_code,
           g2.libelle AS destination, g2.code_gare AS destination_code,
           m.description AS merchandise_type, m.code AS merchandise_code,
           c.merchandise_description AS request_description,
           fr.recipient_name, fr.recipient_contact,
           a.username AS admin_username
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN merchandise m ON c.merchandise_description = m.description
    LEFT JOIN freight_requests fr ON fr.id = c.freight_request_id
    LEFT JOIN users a ON a.user_id = c.draft_created_by
    WHERE c.contract_id = ? AND c.agent_id = ? AND c.status = 'draft'
");
$stmt->execute([$contract_id, $agent['agent_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé ou déjà complété";
    redirect('agent-dashboard.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Check if problems are being reported
        $isReportingProblem = !empty($_POST['reported_problems']);
        
        // Validate fields based on whether we're reporting problems
        if ($isReportingProblem) {
            // Only require problem description when reporting problems
            if (empty($_POST['reported_problems'])) {
                throw new Exception("Veuillez décrire le problème rencontré");
            }
        } else {
            // Require all normal fields when not reporting problems
            $required = [
                'shipment_weight' => 'Poids de l\'expédition',
                'wagon_count' => 'Nombre de wagons',
                'tarp_count' => 'Nombre de bâches',
                'total_units' => 'Nombre total d\'unités'
            ];
            
            foreach ($required as $field => $name) {
                if (empty($_POST[$field])) {
                    throw new Exception("Le champ '$name' est requis");
                }
            }
        }

        // Update contract with additional details
        $update_data = [
            'shipment_weight' => $_POST['shipment_weight'] ?? $contract['shipment_weight'],
            'wagon_count' => $_POST['wagon_count'] ?? $contract['wagon_count'],
            'tarp_count' => $_POST['tarp_count'] ?? $contract['tarp_count'],
            'total_units' => $_POST['total_units'] ?? $contract['total_units'],
            'accessories' => $_POST['accessories'] ?? null,
            'expenses' => $_POST['expenses'] ?? null,
            'reimbursement' => $_POST['reimbursement'] ?? null,
            'paid_port' => $_POST['paid_port'] ?? null,
            'total_port_due' => $_POST['total_port_due'] ?? null,
            'analytical_allocation' => $_POST['analytical_allocation'] ?? null,
            'part_sncf' => $_POST['part_sncf'] ?? null,
            'part_oncf' => $_POST['part_oncf'] ?? null,
            'reported_problems' => $_POST['reported_problems'] ?? null,
            'status' => $isReportingProblem ? 'problem' : 'validé', // Set status based on problem report
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $set_clause = implode(', ', array_map(fn($field) => "$field = ?", array_keys($update_data)));
        
        // Update contract
        $stmt = $pdo->prepare("
            UPDATE contracts 
            SET $set_clause
            WHERE contract_id = ?
        ");
        $stmt->execute([...array_values($update_data), $contract_id]);
        
        // Update freight request status to 'contract_completed' if we have a freight_request_id
        if ($contract['freight_request_id']) {
            $stmt = $pdo->prepare("
                UPDATE freight_requests 
                SET status = 'contract_completed', updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$contract['freight_request_id']]);
        }
        
        // Send notification to admin
        $admin_id = $pdo->query("SELECT user_id FROM admins LIMIT 1")->fetchColumn();
        
        if ($admin_id) {
            $notificationData = [
                'contract_id' => $contract_id,
                'freight_request_id' => $contract['freight_request_id'] ?? null,
                'status' => 'terminé'
            ];
            
            createNotification(
                $admin_id,
                'contract_completed',
                'Contrat Complété',
                "Le contrat #$contract_id a été complété par l'agent " . $_SESSION['user']['username'],
                $contract_id,
                $notificationData
            );
        }

        // Send notification to the client
        $stmt = $pdo->prepare("
            SELECT u.user_id 
            FROM users u 
            JOIN clients c ON u.user_id = c.user_id 
            WHERE c.client_id = ?
        ");
        $stmt->execute([$contract['sender_client']]);
        $client_user_id = $stmt->fetchColumn();

        if ($client_user_id) {
            $notificationData = [
                'contract_id' => $contract_id,
                'link' => "contracts.php?highlight=" . $contract_id,
                'status' => 'terminé'
            ];
            
            createNotification(
                $client_user_id,
                'contract_completed',
                'Contrat Finalisé',
                "Votre contrat #$contract_id a été finalisé et complété par notre agent.",
                $contract_id,
                $notificationData
            );
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Contrat #$contract_id complété avec succès";
        redirect('agent-dashboard.php');
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
    <title>Compléter Contrat | SNCFT Agent</title>
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
        
        .form-section {
            border-left: 4px solid var(--primary-color);
            padding-left: 15px;
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 500;
        }
        
        .form-control[readonly] {
            background-color: #f8f9fa;
        }
        
        .prefilled-info {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .input-group-text {
            min-width: 120px;
            background-color: #e9ecef;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        #signature-pad {
            border: 1px solid #ddd;
            height: 150px;
            background: #fff;
            border-radius: 5px;
            cursor: crosshair;
        }
        
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        /* Add to your existing styles */
.collapse {
    transition: all 0.3s ease;
}

#problemReportSection {
    border-left: 3px solid #dc3545;
    padding-left: 15px;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    color: white;
}
    </style>
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
            <a class="navbar-brand" href="agent-dashboard.php">
                <i class="fas fa-train me-2"></i>SNCFT Agent
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-sm-inline">
                    <i class="fas fa-id-badge me-1"></i> <?= htmlspecialchars($agent['badge_number']) ?>
                </span>
                <!-- Notification Dropdown -->
                <div class="dropdown me-3">
                    <button class="btn btn-outline-light dropdown-toggle position-relative" 
                            type="button" 
                            id="notificationDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <?php if ($draftNotificationCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $draftNotificationCount ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown">
                        <?php if ($draftNotificationCount > 0): ?>
                            <li><h6 class="dropdown-header">Contrats en brouillon</h6></li>
                            <?php foreach ($draftNotifications as $notification): 
                                $metadata = json_decode($notification['metadata'], true);
                                $contract_id = $metadata['contract_id'] ?? 0;
                            ?>
                                <li>
                                    <a class="dropdown-item" href="complete_contract.php?id=<?= $contract_id ?>">
                                        <div class="d-flex justify-content-between">
                                            <span class="fw-bold"><?= htmlspecialchars($notification['title']) ?></span>
                                            <small class="text-muted ms-2"><?= date('H:i', strtotime($notification['created_at'])) ?></small>
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars($notification['message']) ?></small>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li><hr class="dropdown-divider"></li>
                        <?php else: ?>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-check-circle me-2 text-muted"></i>Aucun nouveau brouillon</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item text-center small" href="agent-contracts.php?status=draft">
                            Voir tous les brouillons
                        </a></li>
                    </ul>
                </div>
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
                            <a class="nav-link" href="agent-dashboard.php">
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
                    <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Add this alert to show if there are existing reported problems -->
        <?php if (!empty($contract['reported_problems'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Problème signalé précédemment:</strong> 
                <?= htmlspecialchars($contract['reported_problems']) ?>
            </div>
        <?php endif; ?>

            <!-- Main Content Area -->
            <div class="col-lg-9">
                <!-- Page Header -->
                <div class="card mb-4 bg-primary text-white">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1"><i class="fas fa-file-contract me-2"></i> Compléter Contrat #CT-<?= $contract_id ?></h4>
                                <p class="mb-0">Créé par: <?= htmlspecialchars($contract['admin_username'] ?? 'Administrateur') ?></p>
                            </div>
                            <div class="display-4">
                                <i class="fas fa-edit"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?= $_SESSION['error'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <!-- Prefilled Information -->
                        <div class="prefilled-info mb-4">
                            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Informations Préremplies (Non Modifiables)</h5>
                            
                            <!-- Client Information -->
                            <div class="form-section mt-3">
                                <h6><i class="fas fa-building me-2"></i>Information Client</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Client Expéditeur</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['client_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Code Compte</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['account_code']) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Numéro Fiscal</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract[''] ?? 'Non spécifié') ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Client Destinataire</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['recipient_name'] ?? $contract['client_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Destinataire</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['recipient_contact'] ?? 'Non spécifié') ?>" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contract Information -->
                            <div class="form-section">
                                <h6><i class="fas fa-file-contract me-2"></i>Détails du Contrat</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Type de Transaction</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst($contract['transaction_type'])) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Date d'Expédition</label>
                                        <input type="text" class="form-control" value="<?= date('d/m/Y', strtotime($contract['shipment_date'])) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Mode de Paiement</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['payment_mode']) ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Gare d'Origine</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?= htmlspecialchars($contract['origin_code'] ?? '') ?></span>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($contract['origin']) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Gare de Destination</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?= htmlspecialchars($contract['destination_code'] ?? '') ?></span>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($contract['destination']) ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Merchandise Information -->
                            <div class="form-section">
                                <h6><i class="fas fa-box me-2"></i>Détails Marchandise</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Marchandise</label>
                                        <div class="input-group">
                                            <?php if ($contract['merchandise_code']): ?>
                                                <span class="input-group-text"><?= htmlspecialchars($contract['merchandise_code']) ?></span>
                                            <?php endif; ?>
                                            <input type="text" class="form-control" 
                                                value="<?= htmlspecialchars($contract['merchandise_type'] ?? $contract['request_description'] ?? '') ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Poids Initial (kg)</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['shipment_weight']) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nombre de Wagons Initial</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['wagon_count']) ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Prix Proposé (TND)</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['total_port_due']) ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form to Complete Contract -->
                        <form method="POST">
                            <!-- Shipping Details -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-truck me-2"></i>Détails d'Expédition (À Compléter)</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Poids Final de l'Expédition (kg)</label>
                                        <input type="number" name="shipment_weight" class="form-control" 
                                            value="<?= htmlspecialchars($contract['shipment_weight']) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Nombre Final de Wagons</label>
                                        <input type="number" name="wagon_count" class="form-control" 
                                            value="<?= htmlspecialchars($contract['wagon_count']) ?>" min="1" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Nombre de Bâches</label>
                                        <input type="number" name="tarp_count" class="form-control" 
                                            value="<?= htmlspecialchars($contract['tarp_count'] ?? '') ?>" min="0" required>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Nombre Total d'Unités</label>
                                        <input type="number" name="total_units" class="form-control" 
                                            value="<?= htmlspecialchars($contract['total_units'] ?? '') ?>" min="1" required>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Accessoires</label>
                                        <input type="text" name="accessories" class="form-control" 
                                            value="<?= htmlspecialchars($contract['accessories'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Financial Details -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-money-bill-wave me-2"></i>Détails Financiers (À Compléter)</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Frais (TND)</label>
                                        <input type="number" name="expenses" class="form-control" 
                                            value="<?= htmlspecialchars($contract['expenses'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Remboursement (TND)</label>
                                        <input type="number" name="reimbursement" class="form-control" 
                                            value="<?= htmlspecialchars($contract['reimbursement'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Port Payé (TND)</label>
                                        <input type="number" name="paid_port" class="form-control" 
                                            value="<?= htmlspecialchars($contract['paid_port'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Port Dû Total (TND)</label>
                                        <input type="number" name="total_port_due" class="form-control" 
                                            value="<?= htmlspecialchars($contract['total_port_due'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Part SNCF (TND)</label>
                                        <input type="number" name="part_sncf" class="form-control" 
                                            value="<?= htmlspecialchars($contract['part_sncf'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Part ONCF (TND)</label>
                                        <input type="number" name="part_oncf" class="form-control" 
                                            value="<?= htmlspecialchars($contract['part_oncf'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Allocation Analytique</label>
                                        <input type="text" name="analytical_allocation" class="form-control" 
                                            value="<?= htmlspecialchars($contract['analytical_allocation'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Signature Section -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-signature me-2"></i>Signature Client</h5>
                                <div class="mb-3">
                                    <label class="form-label">Signature du Client</label>
                                    <div id="signature-pad"></div>
                                    <button type="button" id="clear-signature" class="btn btn-sm btn-outline-secondary mt-2">
                                        <i class="fas fa-eraser me-1"></i> Effacer
                                    </button>
                                    <input type="hidden" name="signature_data" id="signature-data">
                                </div>
                            </div>
<div class="form-section">
    <h5 class="mb-3"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Signaler un Problème</h5>
    <div class="mb-3">
        <button class="btn btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#problemReportSection">
            <i class="fas fa-flag me-1"></i> Signaler un problème
        </button>
        
        <div class="collapse mt-3" id="problemReportSection">
            <div class="card card-body">
                <div class="mb-3">
                    <label class="form-label">Description du problème</label>
                    <textarea name="reported_problems" class="form-control" rows="3" 
                        placeholder="Décrivez en détail le problème rencontré..."><?= htmlspecialchars($contract['reported_problems'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>
</div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="agent-dashboard.php" class="btn btn-outline-secondary me-md-2">
                                    <i class="fas fa-times me-1"></i> Annuler
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check-circle me-1"></i> Finaliser le Contrat
                                </button>
                            </div>
                        </form>
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
    <!-- Signature Pad JS -->
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script>
        // Initialize signature pad
        const canvas = document.getElementById("signature-pad");
        const signaturePad = new SignaturePad(canvas, {
            backgroundColor: 'rgb(255, 255, 255)',
            penColor: 'rgb(0, 0, 0)'
        });
        
        // Clear signature button
        document.getElementById("clear-signature").addEventListener("click", () => {
            signaturePad.clear();
        });
        
        // Save signature data before form submission
        document.querySelector("form").addEventListener("submit", (e) => {
            if (!signaturePad.isEmpty()) {
                document.getElementById("signature-data").value = signaturePad.toDataURL();
            }
            
            // Client-side validation
            const required = ['shipment_weight', 'wagon_count', 'tarp_count', 'total_units'];
            let isValid = true;
            
            required.forEach(field => {
                const input = document.querySelector(`[name="${field}"]`);
                if (!input.value.trim()) {
                    alert(`Le champ ${input.previousElementSibling.textContent.trim()} est requis`);
                    input.focus();
                    isValid = false;
                }
            });
            
            if (!isValid) {
                e.preventDefault();
            }
        });
        
        // Adjust canvas size when window resizes
        function resizeCanvas() {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear(); // otherwise isEmpty() might return incorrect value
        }
        
        window.addEventListener("resize", resizeCanvas);
        resizeCanvas();
    </script>
</body>
</html>