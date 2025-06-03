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
            $new_status = 'accepted';
            $stmt = $pdo->prepare("UPDATE freight_requests SET status = ?, admin_notes = ? WHERE id = ?");
            $stmt->execute([$new_status, $notes, $request_id]);
            
            // Prefill contract draft
            $contract_data = [
                'gare_expéditrice' => $request['gare_depart'],
                'gare_destinataire' => $request['gare_arrivee'],
                'sender_client' => $request['sender_client_id'],
                'merchandise_description' => $request['merchandise'] ?? $request['description'],
                'quantity' => $request['quantity'],
                'wagon_count' => $_POST['wagon_count'],
                'payment_mode' => $request['mode_paiement'],
                'price_quoted' => $_POST['price'],
                'estimated_arrival' => $_POST['eta']
            ];
            $_SESSION['contract_draft_' . $request_id] = $contract_data;

            $metadata = [
                'price' => $_POST['price'],
                'wagon_count' => $_POST['wagon_count'],
                'eta' => $_POST['eta'],
                'origin' => $request['origin'],
                'destination' => $request['destination']
            ];
            $message = "Votre demande #$request_id a été approuvée. Prix: {$_POST['price']}€, Wagons: {$_POST['wagon_count']}, ETA: {$_POST['eta']}";
            $notification_type = 'nouvelle_demande';
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
           c.company_name, n.metadata
    FROM freight_requests fr
    JOIN clients c ON fr.sender_client_id = c.client_id
    JOIN notifications n ON fr.id = n.related_request_id
    WHERE fr.status IN ('accepted', 'rejected')
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
       <?php require_once 'assets/css/style.css';?>

        .request-card {
            transition: all 0.3s;
        }
        .request-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .badge-pending {
            background-color: #ffc107;
            color: #000;
        }
        .badge-approved {
            background-color: #28a745;
        }
        .badge-rejected {
            background-color: #dc3545;
        }
        .notification-item.unread {
            border-left: 4px solid #0d6efd;
            background-color: #f8f9fa;
        }
        .price-calculator {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
<?php require_once 'includes/admin_nav.php'; ?>
    <!-- Main Content -->
<div class="container-fluid mt-4">
    <div class="row g-4">
        <!-- Sidebar -->
        <div class="col-lg-3 d-none d-lg-block">
            <div class="card shadow-sm sticky-top" style="top: 70px;">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="avatar bg-primary text-white rounded-circle p-3 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-user-shield fa-2x"></i>
                        </div>
                        <h5 class="mt-3 mb-0"><?= htmlspecialchars($admin['department'] ?? 'Administrateur') ?></h5>
                        <small class="text-muted">Niveau d'accès: <?= $admin['access_level'] ?? 1 ?></small>
                    </div>
                    <hr>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="admin-dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i> Tableau de bord
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage-clients.php">
                                <i class="fas fa-users me-2"></i> Gestion Clients
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage-stations.php">
                                <i class="fas fa-train me-2"></i> Gestion Gares
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage-tariffs.php">
                                <i class="fas fa-money-bill-wave me-2"></i> Tarifs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin-settings.php">
                                <i class="fas fa-cog me-2"></i> Paramètres
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="col-lg-9">
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

            <!-- Quick Stats -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card bg-primary text-white h-100">
                        <div class="card-body d-flex align-items-center">
                            <i class="fas fa-clock fa-2x me-3"></i>
                            <div>
                                <h5 class="mb-0"><?= count($pending_requests) ?></h5>
                                <small>Demandes en attente</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-success text-white h-100">
                        <div class="card-body d-flex align-items-center">
                            <i class="fas fa-check-circle fa-2x me-3"></i>
                            <div>
                                <h5 class="mb-0">12</h5>
                                <small>Demandes approuvées</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-danger text-white h-100">
                        <div class="card-body d-flex align-items-center">
                            <i class="fas fa-times-circle fa-2x me-3"></i>
                            <div>
                                <h5 class="mb-0">3</h5>
                                <small>Demandes rejetées</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Requests Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Demandes en Attente</h5>
                    <span class="badge bg-primary rounded-pill"><?= count($pending_requests) ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($pending_requests)): ?>
                        <div class="alert alert-info">Aucune demande en attente</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
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
                                            <td>FR-<?= $request['request_id'] ?></td>
                                            <td>
                                                <?= htmlspecialchars($request['company_name']) ?>
                                                <br><small class="text-muted"><?= $request['client_email'] ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($request['origin']) ?> → <?= htmlspecialchars($request['destination']) ?></td>
                                            <td><?= htmlspecialchars($request['merchandise'] ?? $request['description']) ?></td>
                                            <td><?= $request['quantity'] ?> kg</td>
                                            <td><?= date('d/m/Y', strtotime($request['date_start'])) ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                    data-bs-target="#detailsModal<?= $request['request_id'] ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success ms-1" data-bs-toggle="modal"
                                                    data-bs-target="#approveModal<?= $request['request_id'] ?>">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger ms-1" data-bs-toggle="modal"
                                                    data-bs-target="#rejectModal<?= $request['request_id'] ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
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
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Activité Récente</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php if (empty($recent_activity)): ?>
                            <div class="list-group-item text-center text-muted">Aucune activité récente</div>
                        <?php else: ?>
                            <?php foreach ($recent_activity as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong>Demande #<?= $activity['request_id'] ?></strong> - 
                                            <?= htmlspecialchars($activity['company_name']) ?>
                                            <span class="badge <?= $activity['status'] === 'accepted' ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $activity['status'] === 'accepted' ? 'Approuvée' : 'Rejetée' ?>
                                            </span>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('d/m/Y H:i', strtotime($activity['updated_at'])) ?>
                                        </small>
                                    </div>
                                    <?php if ($activity['status'] === 'accepted' && $activity['metadata']): ?>
                                        <?php $meta = json_decode($activity['metadata'], true); ?>
                                        <div class="mt-2">
                                            <small>
                                                <i class="fas fa-euro-sign"></i> Prix: <?= htmlspecialchars($meta['price'] ?? 'N/A') ?> |
                                                Wagons: <?= htmlspecialchars($meta['wagon_count'] ?? 'N/A') ?> |
                                                ETA: <?= htmlspecialchars($meta['eta'] ?? 'N/A') ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
                                        <?php if (!empty($request['quantity'])): ?>
                                            <i class="fas fa-weight me-1"></i><?= number_format($request['quantity'], 0, ',', ' ') ?> kg
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
                    <form method="POST">
                        <input type="hidden" name="request_id" value="<?= $request['request_id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <div class="modal-header">
                            <h5 class="modal-title">Approuver Demande #<?= $request['request_id'] ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="price-calculator mb-3">
                                <h6><i class="fas fa-calculator me-2"></i>Calculateur de Prix</h6>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Distance (km)</label>
                                        <input type="number" class="form-control distance-input" 
                                            data-origin="<?= $request['gare_depart'] ?>" 
                                            data-destination="<?= $request['gare_arrivee'] ?>"
                                            placeholder="Calcul automatique">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Tarif (€/km)</label>
                                        <input type="number" class="form-control tariff-input" step="0.01" value="0.25">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Prix Calculé</label>
                                        <input type="number" class="form-control calculated-price" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Prix proposé (€)</label>
                                <input type="number" name="price" class="form-control price-input" step="0.01" min="0" required>
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
                                <textarea class="form-control" name="notes"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-success">Confirmer l'approbation</button>
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
                        <div class="modal-header">
                            <h5 class="modal-title">Refuser Demande #<?= $request['request_id'] ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Raison du refus</label>
                                <textarea class="form-control" name="notes" required></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-danger">Confirmer le refus</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Bootstrap & JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Mark notification as read when clicked
            $('.notification-item').click(function(e) {
                e.preventDefault();
                const notificationId = $(this).data('id');
                
                // AJAX call to mark as read
                $.post('mark_notification_read.php', { id: notificationId }, function() {
                    $(this).removeClass('unread').find('.badge').remove();
                }.bind(this));
            });

            // Price calculator functionality
            $('.distance-input, .tariff-input').on('input', function() {
                const distance = parseFloat($('.distance-input').val()) || 0;
                const tariff = parseFloat($('.tariff-input').val()) || 0;
                const calculatedPrice = distance * tariff;
                
                $('.calculated-price').val(calculatedPrice.toFixed(2));
                $('.price-input').val(calculatedPrice.toFixed(2));
            });

            // Auto-fetch distance between stations
            $('.distance-input').each(function() {
                const origin = $(this).data('origin');
                const destination = $(this).data('destination');
                
                // In a real app, you would make an AJAX call to calculate distance
                // This is just a placeholder
                $.get('calculate_distance.php', { 
                    origin: origin, 
                    destination: destination 
                }, function(data) {
                    $(this).val(data.distance);
                    $(this).trigger('input');
                }.bind(this));
            });
        });

        // Helper function for time elapsed (compatible with PHP function)
        function time_elapsed_string(date) {
            // JavaScript implementation matching your PHP function
            // (Implementation would go here)
        }
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