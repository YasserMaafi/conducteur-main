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

// Get contract ID
$contract_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contract_id) {
    $_SESSION['error'] = "ID de contrat invalide";
    header('Location: admin-contracts.php');
    exit();
}

// Get contract details with all related information
$stmt = $pdo->prepare("
    SELECT c.*,
           cl.company_name AS client_name,
           cl.account_code,
           g1.libelle AS origin_station,
           g2.libelle AS destination_station,
           a.badge_number AS agent_badge,
           u.username AS agent_name,
           fr.id AS request_id,
           p.payment_id,
           p.status AS payment_status,
           p.amount AS payment_amount,
           p.payment_date,
           p.payment_method,
           p.reference_number,
           m.description AS merchandise_type,
           m.code AS merchandise_code
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN agents a ON c.agent_id = a.agent_id
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN freight_requests fr ON c.freight_request_id = fr.id
    LEFT JOIN payments p ON c.contract_id = p.contract_id
    LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
    WHERE c.contract_id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé";
    header('Location: admin-contracts.php');
    exit();
}

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

// Function to format status badge
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'draft':
            return 'bg-secondary';
        case 'en_cours':
            return 'bg-primary';
        case 'terminé':
            return 'bg-success';
        case 'annulé':
            return 'bg-danger';
        default:
            return 'bg-info';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails Contrat | SNCFT Admin</title>
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

        .detail-section {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .detail-section h5 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .detail-item {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 500;
            color: var(--secondary-color);
        }

        .detail-value {
            color: var(--dark-color);
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
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notifDropdown">
                        <?php if (empty($notifications)): ?>
                            <li><span class="dropdown-item-text">Aucune notification</span></li>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <li>
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-bell text-primary"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-2">
                                                <p class="mb-0"><?= htmlspecialchars($notif['message']) ?></p>
                                                <small class="text-muted">
                                                    <?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                <a class="nav-link active" href="admin-contracts.php">
                    <i class="fas fa-file-contract"></i> Contrats
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="admin-settings.php">
                    <i class="fas fa-cog"></i> Paramètres
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
            <h2><i class="fas fa-file-contract me-2"></i>Détails du Contrat #<?= $contract_id ?></h2>
            <div>
                <a href="admin-contracts.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-2"></i>Retour
                </a>
                <?php if ($contract['status'] === 'draft'): ?>
                    <a href="edit-contract.php?id=<?= $contract_id ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Modifier
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Contract Details -->
            <div class="col-lg-8">
                <!-- Client Information -->
                <div class="detail-section">
                    <h5><i class="fas fa-building me-2"></i>Information Client</h5>
                    <div class="row">
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Client</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['client_name']) ?></div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Code Compte</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['account_code']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Contract Information -->
                <div class="detail-section">
                    <h5><i class="fas fa-file-contract me-2"></i>Détails du Contrat</h5>
                    <div class="row">
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Statut</div>
                            <div class="detail-value">
                                <span class="badge <?= getStatusBadgeClass($contract['status']) ?>">
                                    <?= ucfirst($contract['status']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Type de Transaction</div>
                            <div class="detail-value"><?= htmlspecialchars(ucfirst($contract['transaction_type'])) ?></div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Date d'Expédition</div>
                            <div class="detail-value"><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Mode de Paiement</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['payment_mode']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Route Information -->
                <div class="detail-section">
                    <h5><i class="fas fa-route me-2"></i>Information Trajet</h5>
                    <div class="row">
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Gare d'Origine</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['origin_station']) ?></div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Gare de Destination</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['destination_station']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Shipment Details -->
                <div class="detail-section">
                    <h5><i class="fas fa-box me-2"></i>Détails Marchandise</h5>
                    <div class="row">
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Type de Marchandise</div>
                            <div class="detail-value">
                                <?php if ($contract['merchandise_code']): ?>
                                    <span class="badge bg-info me-2"><?= htmlspecialchars($contract['merchandise_code']) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($contract['merchandise_type'] ?? $contract['merchandise_description']) ?>
                            </div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Poids (kg)</div>
                            <div class="detail-value"><?= number_format($contract['shipment_weight'], 2) ?></div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Nombre de Wagons</div>
                            <div class="detail-value"><?= $contract['wagon_count'] ?></div>
                        </div>
                        <div class="col-md-6 detail-item">
                            <div class="detail-label">Nombre de Bâches</div>
                            <div class="detail-value"><?= $contract['tarp_count'] ?? 'Non spécifié' ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar Information -->
            <div class="col-lg-4">
                <!-- Payment Information -->
                <div class="detail-section">
                    <h5><i class="fas fa-money-bill-wave me-2"></i>Information Paiement</h5>
                    <div class="detail-item">
                        <div class="detail-label">Montant Total</div>
                        <div class="detail-value h4 text-primary mb-3">
                            <?= number_format($contract['total_port_due'], 2) ?> €
                        </div>
                    </div>
                    <?php if ($contract['payment_id']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Statut Paiement</div>
                            <div class="detail-value">
                                <span class="badge bg-<?= $contract['payment_status'] === 'paid' ? 'success' : 'warning' ?>">
                                    <?= ucfirst($contract['payment_status']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date de Paiement</div>
                            <div class="detail-value"><?= date('d/m/Y', strtotime($contract['payment_date'])) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Méthode de Paiement</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['payment_method']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Référence</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['reference_number']) ?></div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>Aucun paiement enregistré
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Agent Information -->
                <div class="detail-section">
                    <h5><i class="fas fa-user-tie me-2"></i>Agent Assigné</h5>
                    <?php if ($contract['agent_name']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Nom</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['agent_name']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Badge</div>
                            <div class="detail-value"><?= htmlspecialchars($contract['agent_badge']) ?></div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>Aucun agent assigné
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Related Request -->
                <?php if ($contract['request_id']): ?>
                    <div class="detail-section">
                        <h5><i class="fas fa-link me-2"></i>Demande Associée</h5>
                        <div class="detail-item">
                            <div class="detail-label">ID Demande</div>
                            <div class="detail-value">
                                <a href="admin-request-details.php?id=<?= $contract['request_id'] ?>" class="text-primary">
                                    #<?= $contract['request_id'] ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
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