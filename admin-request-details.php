<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Validate request ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid request.");
}
$requestId = (int) $_GET['id'];
$userId    = $_SESSION['user']['id'];

// Fetch the freight request with client information
// Replace your current freight request query with this:
$stmt = $pdo->prepare("
    SELECT fr.*,
           g1.libelle AS departure_station,
           g2.libelle AS arrival_station,
           m.description AS merchandise_description,
           sc.company_name AS sender_company_name,
           sc.phone_number AS sender_phone_number,
           rc.company_name AS recipient_company_name,
           (SELECT n.metadata->>'price' FROM notifications n 
            WHERE n.related_request_id = fr.id AND n.type = 'request_approved' 
            ORDER BY n.created_at DESC LIMIT 1) AS approved_price,
           (SELECT n.metadata->>'wagon_count' FROM notifications n 
            WHERE n.related_request_id = fr.id AND n.type = 'request_approved' 
            ORDER BY n.created_at DESC LIMIT 1) AS approved_wagon_count,
           (SELECT n.metadata->>'eta' FROM notifications n 
            WHERE n.related_request_id = fr.id AND n.type = 'request_approved' 
            ORDER BY n.created_at DESC LIMIT 1) AS approved_eta
      FROM freight_requests fr
 LEFT JOIN gares g1 ON fr.gare_depart = g1.id_gare
 LEFT JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
 LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
 LEFT JOIN clients sc ON fr.sender_client_id = sc.client_id
 LEFT JOIN clients rc ON fr.recipient_client_id = rc.client_id
     WHERE fr.id = ?
");
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    die("Request not found.");
}

// Get admin info for sidebar
$adminStmt = $pdo->prepare("
    SELECT a.*, u.email
    FROM admins a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.user_id = ?
");
$adminStmt->execute([$userId]);
$admin = $adminStmt->fetch();

// Get unread notifications for navbar
$notifStmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE (user_id = ? OR (user_id IS NULL AND metadata->>'target_audience' = 'admins'))
    AND is_read = FALSE
    ORDER BY created_at DESC
    LIMIT 5
");
$notifStmt->execute([$userId]);
$notifications = $notifStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de la Demande | SNCFT Admin</title>
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
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
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            border-bottom: none;
            font-weight: 600;
            padding: 1rem 1.5rem;
        }

        /* Status badges */
        .status-badge {
            padding: 0.35em 0.65em;
            font-weight: 500;
            border-radius: 50rem;
        }

        .status-pending {
            background-color: var(--warning-color);
            color: #856404;
        }
        .status-approved {
            background-color: var(--success-color);
            color: white;
        }
        .status-rejected {
            background-color: var(--danger-color);
            color: white;
        }
        .status-client_confirmed {
            background-color: var(--info-color);
            color: white;
        }
        .status-client_rejected {
            background-color: #dc3545;
            color: white;
        }

        /* Detail cards */
        .detail-card {
            border-left: 4px solid var(--primary-color);
            border-radius: 0.25rem;
            margin-bottom: 1rem;
        }

        .detail-card.approved {
            border-left-color: var(--success-color);
        }

        .detail-card.rejected {
            border-left-color: var(--danger-color);
        }

        .detail-label {
            font-weight: 600;
            color: var(--dark-color);
        }

        .detail-value {
            background-color: var(--light-color);
            border-radius: 0.25rem;
            padding: 0.5rem;
            margin-top: 0.25rem;
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
                                        $request_id = $notif['related_request_id'] ?? null;
                                        
                                        if ($request_id) {
                                            switch ($notif['type']) {
                                                case 'client_confirmed':
                                                case 'client_rejected':
                                                case 'request_rejected':
                                                    $link = "admin-request-details.php?id=" . (int)$request_id;
                                                    break;
                                                case 'nouvelle_demande':
                                                    $link = "pending-requests.php?highlight=" . (int)$request_id;
                                                    break;
                                            }
                                        }
                                        $timeAgo = time_elapsed_string($notif['created_at']);
                                    ?>
                                    <li>
                                        <a class="dropdown-item p-3 border-bottom <?= $notif['is_read'] ? '' : 'bg-light' ?>" 
                                           href="<?= htmlspecialchars($link) ?>"
                                           <?= ($request_id && $request_id > 0) ? '' : 'onclick="return false;"' ?>>
                                            <div class="d-flex">
                                                <div class="flex-shrink-0 me-3">
                                                    <?php if ($notif['type'] === 'nouvelle_demande'): ?>
                                                        <i class="fas fa-file-alt text-primary"></i>
                                                    <?php elseif ($notif['type'] === 'client_confirmed'): ?>
                                                        <i class="fas fa-check-circle text-success"></i>
                                                    <?php elseif ($notif['type'] === 'client_rejected' || $notif['type'] === 'request_rejected'): ?>
                                                        <i class="fas fa-times-circle text-danger"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-bell text-warning"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between">
                                                        <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                                        <small class="text-muted"><?= htmlspecialchars($timeAgo) ?></small>
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
        <div class="admin-profile">
            <div class="admin-avatar">
                <i class="fas fa-user-shield"></i>
            </div>
            <h5 class="mt-3 mb-0"><?= htmlspecialchars($admin['department'] ?? 'Administration') ?></h5>
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
                <a class="nav-link" href="manage-requests.php">
                    <i class="fas fa-file-alt"></i> Demandes
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
                <a class="nav-link active" href="admin-notifications.php">
                    <i class="fas fa-bell"></i> Notifications
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Alerts -->
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
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0">
                <i class="fas fa-file-alt me-2"></i>Détails de la Demande #<?= htmlspecialchars($request['id']) ?>
            </h2>
            <a href="admin-notifications.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Retour
            </a>
        </div>

        <!-- Request Details Card -->
        <div class="card dashboard-card mb-4 animate-fade">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Informations de la Demande</h5>
                <span class="status-badge <?= getStatusBadgeClass($request['status']) ?>">
                    <?= formatStatus($request['status']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <span class="detail-label">Expéditeur</span>
                            <span class="detail-value">
                                <?= htmlspecialchars($request['sender_company_name'] ?? 'Non spécifié') ?>
                                <?php if (!empty($request['sender_phone_number'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($request['sender_phone_number']) ?></small>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <span class="detail-label">Destinataire</span>
                            <span class="detail-value">
                                <?= htmlspecialchars($request['recipient_name']) ?>
                                <?php if (!empty($request['recipient_contact'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($request['recipient_contact']) ?></small>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <span class="detail-label">Marchandise</span>
                            <span class="detail-value">
                                <?= htmlspecialchars($request['merchandise_description'] ?? $request['description'] ?? 'Non spécifiée') ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <span class="detail-label">Trajet</span>
                            <span class="detail-value">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?= htmlspecialchars($request['departure_station'] ?? $request['gare_depart']) ?>
                                <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                <i class="fas fa-map-marker-alt text-success me-1"></i>
                                <?= htmlspecialchars($request['arrival_station'] ?? $request['gare_arrivee']) ?>
                            </span>
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
                            <span class="detail-label">Date de départ</span>
                            <span class="detail-value">
                                <?= formatDate($request['date_start']) ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <span class="detail-label">Mode de paiement</span>
                            <span class="detail-value">
                                <?= htmlspecialchars($request['mode_paiement']) ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($request['admin_notes'])): ?>
                    <hr>
                    <div class="mb-3">
                        <span class="detail-label">Notes administratives</span>
                        <span class="detail-value">
                            <?= nl2br(htmlspecialchars($request['admin_notes'])) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Add this new section for contract creation -->
                <?php if ($request['status'] === 'client_confirmed'): ?>
                    <hr>
                    <div class="d-flex justify-content-end">
                        <a href="create_contract.php?request_id=<?= $request['id'] ?>" 
                           class="btn btn-success"
                           title="Créer contrat">
                           <i class="fas fa-file-contract me-1"></i> Créer un contrat
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Admin Actions -->
        <?php if ($request['status'] === 'pending'): ?>
            <div class="card dashboard-card animate-fade">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Actions Administratives</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-2">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                            <i class="fas fa-check me-1"></i> Approuver
                        </button>
                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="fas fa-times me-1"></i> Rejeter
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Approve Modal -->
            <div class="modal fade" id="approveModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="process_request.php">
                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-check-circle me-2"></i>Approuver la Demande
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Prix proposé (€)</label>
                                    <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Nombre de wagons</label>
                                    <input type="number" name="wagon_count" class="form-control" min="1" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Date estimée d'arrivée</label>
                                    <input type="date" name="eta" class="form-control" min="<?= date('Y-m-d', strtotime($request['date_start'])) ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Notes (optionnel)</label>
                                    <textarea class="form-control" name="notes" rows="3"></textarea>
                                </div>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i> Annuler
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check me-1"></i> Confirmer l'approbation
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Reject Modal -->
            <div class="modal fade" id="rejectModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="process_request.php">
                            <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">
                                    <i class="fas fa-times-circle me-2"></i>Rejeter la Demande
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
                                    <textarea class="form-control" name="reason" rows="4" required></textarea>
                                </div>
                            </div>
                            
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-1"></i> Annuler
                                </button>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-ban me-1"></i> Confirmer le refus
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.querySelector('.admin-sidebar').classList.toggle('show');
        });
    </script>
</body>
</html>

<?php
// Helper functions
function formatStatus($status) {
    $statusMap = [
        'pending' => 'En attente',
        'approved' => 'Approuvée',
        'rejected' => 'Rejetée',
        'client_confirmed' => 'Confirmée par client',
        'client_rejected' => 'Rejetée par client',
        'completed' => 'Terminée'
    ];
    return $statusMap[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function getStatusBadgeClass($status) {
    $classMap = [
        'pending' => 'bg-warning',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        'client_confirmed' => 'bg-primary',
        'client_rejected' => 'bg-danger',
        'completed' => 'bg-info'
    ];
    return $classMap[$status] ?? 'bg-secondary';
}

function formatDate($dateString) {
    return date('d/m/Y', strtotime($dateString));
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $string = [
        'y' => 'an',
        'm' => 'mois',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' il y a' : 'à l\'instant';
}
?>