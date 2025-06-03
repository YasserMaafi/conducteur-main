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
    redirect('payments.php');
}

// Get agent data
$stmt = $pdo->prepare("SELECT a.*, u.email FROM agents a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();

// Get contract and payment details
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name, cl.account_code,
           g1.libelle AS origin, g1.code_gare AS origin_code,
           g2.libelle AS destination, g2.code_gare AS destination_code,
           t.train_number,
           (SELECT COUNT(*) FROM payments WHERE contract_id = c.contract_id) as payment_count,
           (SELECT SUM(amount) FROM payments WHERE contract_id = c.contract_id) as total_paid
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN trains t ON t.train_id = c.train_id
    WHERE c.contract_id = ? AND c.agent_id = ?
");
$stmt->execute([$contract_id, $agent['agent_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé ou accès non autorisé";
    redirect('payments.php');
}

// Get payment history
$stmt = $pdo->prepare("
    SELECT p.*, u.username as recorded_by
    FROM payments p
    LEFT JOIN users u ON u.user_id = p.client_id
    WHERE p.contract_id = ?
    ORDER BY p.payment_date DESC
");
$stmt->execute([$contract_id]);
$payment_history = $stmt->fetchAll();

// Calculate payment statistics
$total_due = $contract['total_port_due'];
$total_paid = $contract['total_paid'] ?? 0;
$remaining_amount = $total_due - $total_paid;
$payment_percentage = ($total_paid / $total_due) * 100;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails du Paiement | SNCFT Agent</title>
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
        
        .payment-progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            margin-top: 0.5rem;
        }
        
        .payment-progress-bar {
            height: 100%;
            border-radius: 4px;
            background-color: var(--success-color);
            transition: width 0.3s ease;
        }
        
        .payment-status {
            font-size: 0.9rem;
            padding: 0.5em 1em;
            border-radius: 50rem;
        }
        
        .payment-status.pending {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }
        
        .payment-status.paid {
            background-color: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }
        
        .payment-status.overdue {
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
                            <a class="nav-link active" href="payments.php">
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

                <!-- Page Header -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1">Détails du Paiement - CT-<?= $contract_id ?></h4>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-building me-1"></i>
                                    <?= htmlspecialchars($contract['client_name']) ?>
                                </p>
                            </div>
                            <a href="payments.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left me-2"></i>Retour aux paiements
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Payment Overview -->
                    <div class="col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title mb-4">Aperçu du Paiement</h5>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="detail-label">Total dû</span>
                                        <span class="detail-value"><?= number_format($total_due, 2) ?> €</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="detail-label">Total payé</span>
                                        <span class="detail-value"><?= number_format($total_paid, 2) ?> €</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="detail-label">Reste à payer</span>
                                        <span class="detail-value"><?= number_format($remaining_amount, 2) ?> €</span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="detail-label">Progression</span>
                                        <span class="detail-value"><?= number_format($payment_percentage, 1) ?>%</span>
                                    </div>
                                    <div class="payment-progress">
                                        <div class="payment-progress-bar" style="width: <?= $payment_percentage ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <span class="payment-status <?= $contract['payment_status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $contract['payment_status'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contract Details -->
                    <div class="col-md-8">
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
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Date d'Expédition</div>
                                        <div class="detail-value"><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="detail-label">Train</div>
                                        <div class="detail-value"><?= htmlspecialchars($contract['train_number'] ?? 'Non assigné') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <div class="card shadow-sm">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Historique des Paiements</h5>
                                <span class="badge bg-primary"><?= count($payment_history) ?></span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($payment_history)): ?>
                                    <div class="p-4 text-center text-muted">
                                        <i class="fas fa-info-circle mb-2"></i>
                                        <p class="mb-0">Aucun paiement enregistré</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Montant</th>
                                                    <th>Méthode</th>
                                                    <th>Référence</th>
                                                    <th>Statut</th>
                                                    <th>Enregistré par</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($payment_history as $payment): ?>
                                                    <tr>
                                                        <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                                                        <td><?= number_format($payment['amount'], 2) ?> €</td>
                                                        <td><?= htmlspecialchars($payment['payment_method']) ?></td>
                                                        <td><?= htmlspecialchars($payment['reference_number']) ?></td>
                                                        <td>
                                                            <span class="payment-status <?= $payment['status'] ?>">
                                                                <?= ucfirst($payment['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td><?= htmlspecialchars($payment['recorded_by']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
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