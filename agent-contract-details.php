<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

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

// Get contract details with all related information
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name, cl.client_id, cl.account_code,
           g1.libelle AS origin, g1.code_gare AS origin_code,
           g2.libelle AS destination, g2.code_gare AS destination_code,
           m.description AS merchandise_type, m.code AS merchandise_code,
           c.merchandise_description AS request_description,
           fr.recipient_name, fr.recipient_contact,
           a.username AS admin_username,
           t.train_number,
           (SELECT COUNT(*) FROM payments WHERE contract_id = c.contract_id) as payment_count,
           (SELECT SUM(amount) FROM payments WHERE contract_id = c.contract_id) as total_paid
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN merchandise m ON c.merchandise_description = m.description
    LEFT JOIN freight_requests fr ON fr.id = c.freight_request_id
    LEFT JOIN users a ON a.user_id = c.draft_created_by
    LEFT JOIN trains t ON t.train_id = c.train_id
    WHERE c.contract_id = ? AND c.agent_id = ?
");
$stmt->execute([$contract_id, $agent['agent_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé ou accès non autorisé";
    redirect('agent-dashboard.php');
}

// Get payment history
$payments = $pdo->prepare("
    SELECT p.*, u.username as recorded_by
    FROM payments p
    LEFT JOIN users u ON u.user_id = p.client_id
    WHERE p.contract_id = ?
    ORDER BY p.payment_date DESC
");
$payments->execute([$contract_id]);
$payment_history = $payments->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du Contrat | SNCFT Agent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a3c8f;
            --secondary-color: #f8f9fa;
            --accent-color: #3459c0;
            --success-color: #198754;
            --info-color: #0dcaf0;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
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
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .status-badge {
            font-size: 0.9rem;
            padding: 0.5em 1em;
            border-radius: 50rem;
        }
        
        .status-badge.draft {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }
        
        .status-badge.validé {
            background-color: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }
        
        .status-badge.en_cours {
            background-color: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
        }
        
        .status-badge.in_transit {
            background-color: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
        }
        
        .status-badge.completed {
            background-color: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }
        
        .detail-label {
            font-weight: 500;
            color: #6c757d;
        }
        
        .detail-value {
            font-weight: 500;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -34px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 2px solid #fff;
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
                            <a class="nav-link active" href="agent-contracts.php">
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

            <!-- Main Content Area -->
            <div class="col-lg-9">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?= $_SESSION['error'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <!-- Contract Header -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h2 class="mb-1">Contrat #CT-<?= $contract_id ?></h2>
                                <p class="text-muted mb-0">
                                    Créé le <?= date('d/m/Y', strtotime($contract['created_at'])) ?>
                                    par <?= htmlspecialchars($contract['admin_username'] ?? 'Administrateur') ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <span class="status-badge <?= $contract['status'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $contract['status'])) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Main Contract Details -->
                    <div class="col-lg-8">
                        <!-- Client Information -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-building me-2"></i>Information Client</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Client Expéditeur</div>
                                        <div class="detail-value"><?= htmlspecialchars($contract['client_name']) ?></div>
                                        <small class="text-muted">Code: <?= htmlspecialchars($contract['account_code']) ?></small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Client Destinataire</div>
                                        <div class="detail-value"><?= htmlspecialchars($contract['recipient_name'] ?? $contract['client_name']) ?></div>
                                        <?php if ($contract['recipient_contact']): ?>
                                            <small class="text-muted">Contact: <?= htmlspecialchars($contract['recipient_contact']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contract Details -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-file-contract me-2"></i>Détails du Contrat</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Type de Transaction</div>
                                        <div class="detail-value"><?= htmlspecialchars(ucfirst($contract['transaction_type'])) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Mode de Paiement</div>
                                        <div class="detail-value"><?= htmlspecialchars($contract['payment_mode']) ?></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Gare d'Origine</div>
                                        <div class="detail-value">
                                            <?= htmlspecialchars($contract['origin']) ?>
                                            <small class="text-muted">(<?= htmlspecialchars($contract['origin_code']) ?>)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Gare de Destination</div>
                                        <div class="detail-value">
                                            <?= htmlspecialchars($contract['destination']) ?>
                                            <small class="text-muted">(<?= htmlspecialchars($contract['destination_code']) ?>)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Shipping Details -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-truck me-2"></i>Détails d'Expédition</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Marchandise</div>
                                        <div class="detail-value">
                                            <?= htmlspecialchars($contract['merchandise_type'] ?? $contract['request_description']) ?>
                                            <?php if ($contract['merchandise_code']): ?>
                                                <small class="text-muted">(<?= htmlspecialchars($contract['merchandise_code']) ?>)</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Date d'Expédition</div>
                                        <div class="detail-value"><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <div class="detail-label">Poids (kg)</div>
                                        <div class="detail-value"><?= number_format($contract['shipment_weight'], 2) ?></div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="detail-label">Wagons</div>
                                        <div class="detail-value"><?= $contract['wagon_count'] ?></div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="detail-label">Bâches</div>
                                        <div class="detail-value"><?= $contract['tarp_count'] ?></div>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <div class="detail-label">Unités</div>
                                        <div class="detail-value"><?= $contract['total_units'] ?></div>
                                    </div>
                                </div>
                                <?php if ($contract['accessories']): ?>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="detail-label">Accessoires</div>
                                        <div class="detail-value"><?= htmlspecialchars($contract['accessories']) ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Financial Details -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Détails Financiers</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Port Dû Total</div>
                                        <div class="detail-value"><?= number_format($contract['total_port_due'], 2) ?> €</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Port Payé</div>
                                        <div class="detail-value"><?= number_format($contract['paid_port'], 2) ?> €</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Total Payé</div>
                                        <div class="detail-value"><?= number_format($contract['total_paid'] ?? 0, 2) ?> €</div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Frais</div>
                                        <div class="detail-value"><?= number_format($contract['expenses'], 2) ?> €</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Remboursement</div>
                                        <div class="detail-value"><?= number_format($contract['reimbursement'], 2) ?> €</div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="detail-label">Allocation Analytique</div>
                                        <div class="detail-value"><?= htmlspecialchars($contract['analytical_allocation'] ?? 'Non spécifié') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="col-lg-4">
                        <!-- Status Timeline -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Historique</h5>
                            </div>
                            <div class="card-body">
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="detail-label">Création</div>
                                        <div class="detail-value"><?= date('d/m/Y H:i', strtotime($contract['created_at'])) ?></div>
                                        <small class="text-muted">Par <?= htmlspecialchars($contract['admin_username'] ?? 'Administrateur') ?></small>
                                    </div>
                                    <?php if ($contract['updated_at']): ?>
                                    <div class="timeline-item">
                                        <div class="detail-label">Dernière mise à jour</div>
                                        <div class="detail-value"><?= date('d/m/Y H:i', strtotime($contract['updated_at'])) ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Paiements</h5>
                                <span class="badge bg-primary"><?= count($payment_history) ?></span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($payment_history)): ?>
                                    <div class="p-3 text-center text-muted">
                                        <i class="fas fa-info-circle mb-2"></i>
                                        <p class="mb-0">Aucun paiement enregistré</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($payment_history as $payment): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <div class="detail-value"><?= number_format($payment['amount'], 2) ?> €</div>
                                                        <small class="text-muted">
                                                            <?= date('d/m/Y', strtotime($payment['payment_date'])) ?>
                                                        </small>
                                                    </div>
                                                    <span class="badge bg-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>">
                                                        <?= ucfirst($payment['status']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Actions Rapides</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <?php if ($contract['status'] === 'draft'): ?>
                                        <a href="complete_contract.php?id=<?= $contract_id ?>" class="btn btn-primary">
                                            <i class="fas fa-edit me-2"></i>Compléter le contrat
                                        </a>
                                    <?php endif; ?>
                                    <a href="print-contract.php?id=<?= $contract_id ?>" target="_blank" class="btn btn-outline-primary">
                                        <i class="fas fa-print me-2"></i>Imprimer
                                    </a>
                                    <a href="track_shipment.php?id=<?= $contract_id ?>" class="btn btn-outline-info">
                                        <i class="fas fa-map-marked-alt me-2"></i>Suivre l'expédition
                                    </a>
                                    <?php if ($contract['status'] !== 'completed' && $contract['status'] !== 'cancelled'): ?>
                                        <a href="update_status.php?id=<?= $contract_id ?>" class="btn btn-outline-warning">
                                            <i class="fas fa-exchange-alt me-2"></i>Mettre à jour le statut
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 