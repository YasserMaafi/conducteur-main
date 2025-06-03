<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

// Get agent data
$stmt = $pdo->prepare("SELECT a.*, u.email FROM agents a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();

// Handle arrival notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_arrival'])) {
    $contract_id = filter_input(INPUT_POST, 'contract_id', FILTER_VALIDATE_INT);
    
    if ($contract_id) {
        try {
            $pdo->beginTransaction();
            
            // Update contract status
            $stmt = $pdo->prepare("
                UPDATE contracts 
                SET status = 'arrived', 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE contract_id = ? AND agent_id = ?
            ");
            $stmt->execute([$contract_id, $agent['agent_id']]);
            
            // Get contract details for notification
            $stmt = $pdo->prepare("
                SELECT c.*, cl.user_id as client_user_id, cl.company_name
                FROM contracts c
                JOIN clients cl ON c.sender_client = cl.client_id
                WHERE c.contract_id = ?
            ");
            $stmt->execute([$contract_id]);
            $contract = $stmt->fetch();
            
            if ($contract) {
                // Create notification
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, metadata, created_at)
                    VALUES (?, 'arrival', 'Arrivée de votre expédition', ?, ?, CURRENT_TIMESTAMP)
                ");
                
                $message = "Votre expédition #CT-{$contract_id} est arrivée à destination.";
                $metadata = json_encode([
                    'contract_id' => $contract_id,
                    'client_name' => $contract['company_name']
                ]);
                
                $stmt->execute([$contract['client_user_id'], $message, $metadata]);
            }
            
            $pdo->commit();
            $_SESSION['success'] = "Notification d'arrivée envoyée avec succès";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de l'envoi de la notification";
        }
    }
    
    redirect('agent-expeditions.php');
}

// Get today's shipments
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name,
           g1.libelle AS origin, g2.libelle AS destination,
           t.train_number,
           (SELECT COUNT(*) FROM payments WHERE contract_id = c.contract_id) as payment_count,
           (SELECT SUM(amount) FROM payments WHERE contract_id = c.contract_id) as total_paid
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN trains t ON t.train_id = c.train_id
    WHERE c.agent_id = ? 
    AND c.shipment_date = CURRENT_DATE
    AND c.status IN ('in_transit', 'en_cours')
    ORDER BY c.shipment_date ASC
");
$stmt->execute([$agent['agent_id']]);
$today_shipments = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expéditions du Jour | SNCFT Agent</title>
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
        
        .shipment-card {
            transition: transform 0.2s;
        }
        
        .shipment-card:hover {
            transform: translateY(-5px);
        }
        
        .status-badge {
            font-size: 0.9rem;
            padding: 0.5em 1em;
            border-radius: 50rem;
        }
        
        .status-badge.in_transit {
            background-color: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
        }
        
        .status-badge.en_cours {
            background-color: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
        }
        
        .status-badge.arrived {
            background-color: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
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
                            <a class="nav-link active" href="agent-expeditions.php">
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

                <!-- Page Header -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-1">Expéditions du <?= date('d/m/Y') ?></h4>
                                <p class="text-muted mb-0">Gérez les expéditions arrivant aujourd'hui</p>
                            </div>
                            <div class="display-4 text-primary">
                                <i class="fas fa-truck"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shipments List -->
                <?php if (empty($today_shipments)): ?>
                    <div class="card shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-truck fa-3x text-muted mb-3"></i>
                            <h5>Aucune expédition aujourd'hui</h5>
                            <p class="text-muted">Il n'y a pas d'expéditions prévues pour aujourd'hui.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($today_shipments as $shipment): ?>
                            <div class="col-md-6">
                                <div class="card shadow-sm shipment-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="mb-1">CT-<?= $shipment['contract_id'] ?></h5>
                                                <p class="text-muted mb-0">
                                                    <i class="fas fa-building me-1"></i>
                                                    <?= htmlspecialchars($shipment['client_name']) ?>
                                                </p>
                                            </div>
                                            <span class="status-badge <?= $shipment['status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $shipment['status'])) ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fas fa-route text-primary me-2"></i>
                                                <div>
                                                    <small class="text-muted d-block">Trajet</small>
                                                    <?= htmlspecialchars($shipment['origin']) ?> 
                                                    <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                                    <?= htmlspecialchars($shipment['destination']) ?>
                                                </div>
                                            </div>
                                            
                                            <?php if ($shipment['train_number']): ?>
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fas fa-train text-primary me-2"></i>
                                                <div>
                                                    <small class="text-muted d-block">Train</small>
                                                    <?= htmlspecialchars($shipment['train_number']) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-box text-primary me-2"></i>
                                                <div>
                                                    <small class="text-muted d-block">Marchandise</small>
                                                    <?= htmlspecialchars($shipment['merchandise_description']) ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <small class="text-muted d-block">Port dû</small>
                                                <span class="fw-bold"><?= number_format($shipment['total_port_due'], 2) ?> €</span>
                                            </div>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="contract_id" value="<?= $shipment['contract_id'] ?>">
                                                <button type="submit" name="notify_arrival" class="btn btn-success" 
                                                        onclick="return confirm('Confirmer l\'arrivée de l\'expédition ?')">
                                                    <i class="fas fa-check me-2"></i>Confirmer arrivée
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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