<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify admin or client role
if (!isLoggedIn() || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['role'] !== 'client')) {
    header('Location: index.php');
    exit();
}
$user_id = $_SESSION['user']['id'];
$client_id = $_SESSION['user']['client_id'];

// Validate request ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid request.");
}
$requestId = (int) $_GET['id'];
$userId    = $_SESSION['user']['id'];

// For clients, verify they own the request
if ($_SESSION['user']['role'] === 'client') {
    $clientId = getClientIdByUserId($pdo, $userId);
    $clientCheck = "AND fr.sender_client_id = $clientId";
} else {
    // Admin can view any request
    $clientCheck = "";
}
$stmt = $pdo->prepare("
    SELECT c.*, u.email 
      FROM clients c 
      JOIN users   u ON c.user_id = u.user_id 
     WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();
// Fetch the freight request with client information
$stmt = $pdo->prepare("
    SELECT fr.*,
           g1.libelle AS departure_station,
           g2.libelle AS arrival_station,
           m.description AS merchandise_description,
           sc.company_name AS sender_company_name,
           sc.phone_number AS sender_phone_number,
           rc.company_name AS recipient_company_name
      FROM freight_requests fr
 LEFT JOIN gares g1 ON fr.gare_depart = g1.id_gare
 LEFT JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
 LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
 LEFT JOIN clients sc ON fr.sender_client_id = sc.client_id
 LEFT JOIN clients rc ON fr.recipient_client_id = rc.client_id
     WHERE fr.id = ? $clientCheck
");
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    die("Request not found or access denied.");
}

// Get the admin decision notification
$decisionStmt = $pdo->prepare("
    SELECT n.*, u.username AS admin_username 
    FROM notifications n
    JOIN users u ON n.user_id = u.user_id
    WHERE n.related_request_id = ?
    AND n.title IN ('Demande Approuvée', 'Demande Rejetée')
    ORDER BY n.created_at DESC
    LIMIT 1
");
$decisionStmt->execute([$requestId]);
$decisionNotification = $decisionStmt->fetch();

// Decode metadata if exists
$decisionMetadata = [];
if ($decisionNotification && $decisionNotification['metadata']) {
    $decisionMetadata = json_decode($decisionNotification['metadata'], true);
}

// Handle client POST actions (confirm / reject) - only for clients
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['user']['role'] === 'client') {
    if (($_POST['action'] ?? '') === 'confirm') {
        $upd = $pdo->prepare("UPDATE freight_requests SET status = 'client_confirmed' WHERE id = ?");
        $upd->execute([$requestId]);

        // notify all admins - use exact string from database constraint
        $adminStmt = $pdo->query("SELECT user_id FROM admins");
        while ($admin = $adminStmt->fetch()) {
            createNotification(
                $admin['user_id'],
                'request_approved', // Using a known valid notification type
                'Demande Confirmée par Client',
                "Le client a confirmé la demande #{$requestId}",
                $requestId
            );
        }

        header("Location: client-dashboard.php?msg=Demande confirmée avec succès");
        exit;
    }
    if (($_POST['action'] ?? '') === 'reject') {
        $rejectReason = $_POST['reject_reason'] ?? '';
        
        if (empty($rejectReason)) {
            $_SESSION['error'] = "Veuillez spécifier la raison du refus";
            header("Location: request_details.php?id=$requestId");
            exit;
        }

        // PostgreSQL-compatible update with COALESCE
        $upd = $pdo->prepare("UPDATE freight_requests 
                             SET status = 'client_rejected', 
                                 admin_notes = COALESCE(admin_notes, '') || E'\n\nRaison du refus client: ' || ? 
                             WHERE id = ?");
        $upd->execute([$rejectReason, $requestId]);
        
        // notify all admins - use exact string from database constraint
        $adminStmt = $pdo->query("SELECT user_id FROM admins");
        while ($admin = $adminStmt->fetch()) {
            createNotification(
                $admin['user_id'],
                'request_rejected', // Using a known valid notification type
                'Demande Rejetée par Client',
                "Le client a rejeté la demande #{$requestId}\nRaison: $rejectReason",
                $requestId,
                ['reason' => $rejectReason]
            );
        }

        header("Location: client-dashboard.php?msg=Demande rejetée avec succès");
        exit;
    }
}

// Get current user info for sidebar
if ($_SESSION['user']['role'] === 'client') {
    $clientStmt = $pdo->prepare("
        SELECT c.company_name
        FROM clients c
        WHERE c.client_id = ?
    ");
    $clientStmt->execute([$clientId]);
    $userInfo = $clientStmt->fetch();
} else {
    // For admin, get admin info
    $adminStmt = $pdo->prepare("
        SELECT a.department AS company_name
        FROM admins a
        WHERE a.user_id = ?
    ");
    $adminStmt->execute([$userId]);
    $userInfo = $adminStmt->fetch();
}
// Count unread notifications
$notifCountStmt = $pdo->prepare("
    SELECT COUNT(*) AS count
      FROM notifications
     WHERE user_id = ? 
       AND is_read = FALSE
");
$notifCountStmt->execute([$user_id]);
$notifCount = $notifCountStmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de la Demande #<?= htmlspecialchars($request['id']) ?></title>
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #4e73df;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f8f9fc;
        }

        .shipment-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-approved {
            background-color: #d4edda;
            color: #155724;
        }
        .status-in_transit {
            background-color: #cce5ff;
            color: #004085;
        }
        .status-delivered {
            background-color: #e2e3e5;
            color: #383d41;
        }
        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-client_confirmed {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .status-client_rejected {
            background-color: #f8d7da;
            color: #721c24;
        }

        .decision-card {
            border-left: 4px solid #0d6efd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
        }
        
        .decision-approved {
            border-left-color: #28a745;
        }
        
        .decision-rejected {
            border-left-color: #dc3545;
        }

        .timestamp {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
        }
        
        .detail-value {
            background-color: #f8f9fa;
            border-radius: 4px;
            padding: 8px 12px;
            margin-top: 5px;
            display: block;
        }
        
        #rejectReason {
            min-height: 100px;
        }
    </style>
</head>
<body>
 <!-- NAVBAR -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
      <a class="navbar-brand" href="#">
        <i class="fas fa-train me-2"></i>SNCFT Client
      </a>
      <div class="d-flex align-items-center">
<!-- Notification Dropdown -->
<div class="dropdown me-3">
    <button class="btn btn-primary position-relative" type="button" id="dropdownNotification" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="navbar-notification-icon">
            <i class="fas fa-bell"></i>
            <?php if ($notifCount > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">
                    <?= $notifCount > 9 ? '9+' : $notifCount ?>
                </span>
            <?php endif; ?>
        </span>
    </button>
    <div class="dropdown-menu dropdown-menu-end dropdown-notifications" aria-labelledby="dropdownNotification">
        <div class="dropdown-header">
            <i class="fas fa-bell me-2"></i>Notifications
        </div>
        
        <?php if (empty($notifications)): ?>
            <div class="p-3 text-center text-muted">Aucune nouvelle notification</div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <?php 
                    // Get related request ID from notification
                    $request_id = $n['related_request_id'];
                    $link = $request_id ? "request_details.php?id=$request_id" : '#';
                    $cls  = $n['is_read'] ? '' : 'unread';
                ?>
                <a href="<?= htmlspecialchars($link) ?>" class="dropdown-item notification-item <?= $cls ?> px-3 py-2 border-bottom" data-id="<?= $n['id'] ?>">
                    <div class="d-flex justify-content-between">
                        <strong><?= htmlspecialchars($n['title']) ?></strong>
                        <small class="text-muted"><?= time_elapsed_string($n['created_at']) ?></small>
                    </div>
                    <small><?= htmlspecialchars($n['message']) ?></small>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="dropdown-footer">
            <a href="notifications.php" class="btn btn-sm btn-link">Voir toutes les notifications</a>
        </div>
    </div>
</div>
        
        <span class="text-white me-3">
          <i class="fas fa-building me-1"></i><?= htmlspecialchars($client['company_name']) ?>
        </span>
        <a href="logout.php" class="btn btn-outline-light">
          <i class="fas fa-sign-out-alt"></i> 
        </a>
      </div>
    </div>
  </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="avatar bg-primary text-white rounded-circle p-3 mx-auto" style="width: 80px; height: 80px;">
                                <i class="fas fa-<?= $_SESSION['user']['role'] === 'admin' ? 'user-shield' : 'building' ?> fa-2x"></i>
                            </div>
                            <h5 class="mt-3 mb-0"><?= htmlspecialchars($userInfo['company_name'] ?? '') ?></h5>
                            <small class="text-muted"><?= ucfirst($_SESSION['user']['role']) ?></small>
                        </div>
                        <hr>
                        <ul class="nav flex-column">
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $_SESSION['user']['role'] === 'admin' ? 'admin-dashboard.php' : 'dashboard.php' ?>">
                                    <i class="fas fa-tachometer-alt me-2"></i> Tableau de bord
                                </a>
                            </li>
                            <?php if ($_SESSION['user']['role'] === 'client'): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="new_request.php">
                                        <i class="fas fa-plus-circle me-2"></i> Nouvelle demande
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= $_SESSION['user']['role'] === 'admin' ? 'manage-requests.php' : 'interface.php' ?>">
                                    <i class="fas fa-truck me-2"></i> Expéditions
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="notifications.php">
                                    <i class="fas fa-bell me-2"></i> Notifications
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link active" href="profile.php">
                                    <i class="fas fa-user-cog me-2"></i> Profil
                                </a>
                            </li>
                        </ul>
                    </div>
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
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h3 mb-0">Détails de la Demande #<?= htmlspecialchars($request['id']) ?></h2>
                    <a href="<?= $_SESSION['user']['role'] === 'admin' ? 'admin-dashboard.php' : 'client-dashboard.php' ?>" class="btn btn-outline-secondary">
                        ← Retour
                    </a>
                </div>

<!-- Request Details Card -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center bg-white">
        <span>Demande #<?= htmlspecialchars($request['id']) ?></span>
        <span class="badge <?= getStatusBadgeClass($request['status']) ?>">
            <?= formatStatus($request['status']) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row">
            <!-- Left column -->
            <div class="col-md-6">
                <p><strong>Expéditeur :</strong> <?= htmlspecialchars($request['sender_company_name'] ?? '') ?></p>
                <p><strong>Destinataire :</strong> <?= htmlspecialchars($request['recipient_name']) ?></p>
                <p><strong>Contact destinataire :</strong> <?= htmlspecialchars($request['recipient_contact']) ?></p>
                <p><strong>Marchandise :</strong>
                    <?= htmlspecialchars($request['merchandise_description'] ?? 'Non spécifiée') ?>
                </p>
            </div>
            <!-- Right column -->
            <div class="col-md-6">
                <p><strong>Départ :</strong>
                    <?= htmlspecialchars($request['departure_station'] ?? $request['gare_depart']) ?>
                </p>
                <p><strong>Arrivée :</strong>
                    <?= htmlspecialchars($request['arrival_station'] ?? $request['gare_arrivee']) ?>
                </p>
                <p><strong>Quantité :</strong>
                    <?= htmlspecialchars($request['quantity']) . ' ' . htmlspecialchars($request['quantity_unit'] ?? '') ?>
                </p>
                <p><strong>Mode de paiement :</strong> <?= htmlspecialchars($request['mode_paiement']) ?></p>
                <p><strong>Date de début :</strong> <?= formatDate($request['date_start']) ?></p>
            </div>
        </div>

        <?php if (!empty($request['admin_notes'])): ?>
            <hr>
            <div class="admin-notes">
                <h5><i class="fas fa-comment-dots me-2"></i>Notes de l'admin</h5>
                <p class="text-muted"><?= nl2br(htmlspecialchars($request['admin_notes'])) ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>


                <!-- Admin Decision Section -->
                <?php if ($decisionNotification): ?>
                    <div class="card shadow-sm mb-4 <?= $decisionNotification['title'] === 'Demande Approuvée' ? 'decision-approved' : 'decision-rejected' ?>">
                        <div class="card-header bg-white">
                            <h5 class="mb-0">
                                <i class="fas fa-<?= $decisionNotification['title'] === 'Demande Approuvée' ? 'check-circle text-success' : 'times-circle text-danger' ?> me-2"></i>
                                <?= htmlspecialchars($decisionNotification['title']) ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($decisionNotification['title'] === 'Demande Approuvée'): ?>
                                <div class="row">
                                    <?php if (isset($decisionMetadata['price'])): ?>
                                        <div class="col-md-4 mb-3">
                                            <span class="detail-label">Prix proposé</span>
                                            <span class="detail-value"><?= htmlspecialchars($decisionMetadata['price']) ?> €</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($decisionMetadata['eta'])): ?>
                                        <div class="col-md-4 mb-3">
                                            <span class="detail-label">Date estimée d'arrivée</span>
                                            <span class="detail-value"><?= htmlspecialchars($decisionMetadata['eta']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($decisionMetadata['wagon_count'])): ?>
                                        <div class="col-md-4 mb-3">
                                            <span class="detail-label">Nombre de wagons</span>
                                            <span class="detail-value"><?= htmlspecialchars($decisionMetadata['wagon_count']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($decisionNotification['title'] === 'Demande Rejetée' && isset($decisionMetadata['reason'])): ?>
                                <div class="alert alert-danger">
                                    <h6>Raison du refus :</h6>
                                    <p class="mb-0"><?= nl2br(htmlspecialchars($decisionMetadata['reason'])) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-muted small mt-3">
                                <i class="fas fa-user me-1"></i> Décision prise par <?= htmlspecialchars($decisionNotification['admin_username']) ?>
                                <br>
                                <i class="fas fa-clock me-1"></i> <?= formatDateTime($decisionNotification['created_at']) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Client Actions -->
                <?php if ($request['status'] === 'accepted' && $_SESSION['user']['role'] === 'client'): ?>
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">Confirmation du client</h5>
                            <p class="card-text">Veuillez confirmer ou rejeter cette demande approuvée.</p>
                            
                            <form method="POST" class="mb-3" onsubmit="return confirm('Confirmer le service ?');">
                                <input type="hidden" name="action" value="confirm">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check me-1"></i> Confirmer
                                </button>
                            </form>
                            
                            <form method="POST" id="rejectForm">
                                <input type="hidden" name="action" value="reject">
                                <div class="mb-3">
                                    <label for="rejectReason" class="form-label">Raison du refus <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="rejectReason" name="reject_reason" rows="3" required></textarea>
                                    <small class="text-muted">Veuillez expliquer pourquoi vous rejetez cette demande</small>
                                </div>
                                <button type="submit" class="btn btn-danger" onclick="return confirmRejection()">
                                    <i class="fas fa-times me-1"></i> Rejeter
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <script>
                        function confirmRejection() {
                            const reason = document.getElementById('rejectReason').value.trim();
                            if (!reason) {
                                alert('Veuillez spécifier la raison du refus');
                                return false;
                            }
                            return confirm('Êtes-vous sûr de vouloir rejeter cette demande ?');
                        }
                        
                        document.getElementById('rejectForm').addEventListener('submit', function(e) {
                            const reason = document.getElementById('rejectReason').value.trim();
                            if (!reason) {
                                e.preventDefault();
                                alert('Veuillez spécifier la raison du refus');
                                return false;
                            }
                            return true;
                        });
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Helper functions
function formatStatus($s) {
    $map = [
        'pending'           => 'En attente',
        'approved'          => 'Approuvée',
        'rejected'          => 'Rejetée',
        'client_confirmed'  => 'Confirmée',
        'client_rejected'   => 'Rejetée par client',
        'completed'         => 'Terminée'
    ];
    return $map[$s] ?? ucfirst($s);
}

function getStatusBadgeClass($s) {
    $cls = [
        'pending' => 'bg-warning',
        'approved'=> 'bg-success',
        'rejected'=> 'bg-danger',
        'client_confirmed' => 'bg-primary',
        'client_rejected'  => 'bg-danger',
        'completed'=> 'bg-info'
    ];
    return $cls[$s] ?? 'bg-secondary';
}

function formatDate($d) {
    return date('d/m/Y', strtotime($d));
}

function formatDateTime($dt) {
    return date('d/m/Y H:i', strtotime($dt));
}

if (!function_exists('getClientIdByUserId')) {
    function getClientIdByUserId($pdo, $userId) {
        $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result ? $result['client_id'] : null;
    }
}
?>