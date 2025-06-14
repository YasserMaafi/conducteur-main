<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

 

// 1) Access control: only logged-in clients
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user']['id'];

// 2) Ensure we have client_id in session
if (!isset($_SESSION['user']['client_id'])) {
    $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $c = $stmt->fetch();
    if (!$c) {
        die("Erreur : Compte client introuvable.");
    }
    $_SESSION['user']['client_id'] = $c['client_id'];
}
$client_id = $_SESSION['user']['client_id'];

// 3) Fetch client info for navbar/sidebar
$stmt = $pdo->prepare("
    SELECT c.company_name, u.email
    FROM clients c
    JOIN users   u ON c.user_id = u.user_id
    WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

// 4) Fetch all freight requests for this client
$requestsStmt = $pdo->prepare("
    SELECT fr.*, 
           g1.libelle AS origin,
           g2.libelle AS destination
      FROM freight_requests fr
      JOIN gares g1 ON fr.gare_depart = g1.id_gare
      JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
     WHERE fr.sender_client_id = :cid OR fr.recipient_client_id = :cid
  ORDER BY fr.created_at DESC
");
$requestsStmt->execute(['cid' => $client_id]);

$requests = $requestsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mes Demandes | SNCFT Client</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body { background: #f8f9fc; }
    .shipment-status {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 20px;
      font-size: .8rem;
      font-weight: 500;
    }
    .status-pending      { background: #fff3cd; color: #856404; }
    .status-approved     { background: #d4edda; color: #155724; }
    .status-in_transit   { background: #cce5ff; color: #004085; }
    .status-delivered    { background: #e2e3e5; color: #383d41; }
    .status-rejected     { background: #f8d7da; color: #721c24; }
    
    /* Enhanced Notification dropdown styles */
    .dropdown-notifications {
      min-width: 350px;
      padding: 0;
      max-height: 450px;
      overflow-y: auto;
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
      border: none;
      border-radius: 8px;
    }
    .dropdown-notifications .dropdown-header {
      background-color: #1a3c8f;
      color: white;
      padding: 12px 15px;
      font-weight: 600;
      border-top-left-radius: 8px;
      border-top-right-radius: 8px;
    }
    .dropdown-notifications .dropdown-footer {
      background-color: #f8f9fa;
      padding: 10px;
      text-align: center;
      border-top: 1px solid #e9ecef;
      border-bottom-left-radius: 8px;
      border-bottom-right-radius: 8px;
    }
    .notification-badge {
      position: absolute;
      top: -8px;
      right: -8px;
      font-size: 0.7rem;
      padding: 3px 6px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    .navbar-notification-icon {
      font-size: 1.3rem;
      position: relative;
    }
    .dropdown-item.notification-item {
      padding: 12px 15px;
      border-bottom: 1px solid #f1f1f1;
      transition: all 0.2s ease;
    }
    .dropdown-item.notification-item:hover {
      background-color: #f5f9ff;
    }
    .dropdown-item.notification-item.unread {
      background-color: #f0f7ff;
      border-left: 4px solid #0d6efd;
    }
    .dropdown-item.notification-item strong {
      color: #1a3c8f;
      display: block;
      margin-bottom: 3px;
    }
    .dropdown-notifications .btn-link {
      color: #1a3c8f;
      font-weight: 500;
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
            <?php
            // Get unread notifications count
            $notifCountStmt = $pdo->prepare("SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND is_read = FALSE");
            $notifCountStmt->execute([$_SESSION['user']['id']]);
            $notifCount = $notifCountStmt->fetch()['count'];
            
            // Get recent notifications
            $notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $notifStmt->execute([$_SESSION['user']['id']]);
            $notifications = $notifStmt->fetchAll();
            ?>
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
                    <div class="p-4 text-center">
                        <i class="fas fa-bell-slash fa-2x text-muted mb-3"></i>
                        <p class="mb-0 text-muted">Aucune nouvelle notification</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                        <?php 
                            // Parse metadata if it exists
                            $metadata = json_decode($n['metadata'] ?? '{}', true);
                            
                            // Determine the link based on notification type
                            $link = '#';
                            if ($n['type'] === 'contract_completed' && isset($metadata['contract_id'])) {
                                $link = "client-contract-details.php?id=" . $metadata['contract_id'];
                            } elseif ($n['related_request_id']) {
                                $link = "request_details.php?id=" . $n['related_request_id'];
                            }
                            
                            $cls = $n['is_read'] ? '' : 'unread';
                            
                            // Determine icon based on notification type
                            $icon = 'fa-bell';
                            if (strpos($n['type'], 'contract') !== false) {
                                $icon = 'fa-file-contract';
                            } elseif (strpos($n['type'], 'payment') !== false) {
                                $icon = 'fa-money-bill';
                            } elseif (strpos($n['type'], 'shipment') !== false || strpos($n['type'], 'arrivage') !== false) {
                                $icon = 'fa-truck';
                            }
                        ?>
                        <a href="<?= htmlspecialchars($link) ?>" class="dropdown-item notification-item <?= $cls ?>" data-id="<?= $n['id'] ?>">
                            <div class="d-flex">
                                <div class="me-3 pt-1">
                                    <i class="fas <?= $icon ?> text-primary"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= htmlspecialchars($n['title']) ?></strong>
                                        <small class="text-muted ms-2"><?= time_elapsed_string($n['created_at']) ?></small>
                                    </div>
                                    <small class="text-muted"><?= htmlspecialchars($n['message']) ?></small>
                                </div>
                            </div>
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

  <div class="container-fluid mt-4">
    <div class="row">

      <!-- SIDEBAR -->
      <div class="col-lg-3 mb-4">
                <div class="card shadow-sm mb-4">
          <div class="card-body text-center">
            <div class="avatar bg-primary text-white rounded-circle p-3 mx-auto" style="width:80px;height:80px">
              <i class="fas fa-building fa-2x"></i>
            </div>
            <h5 class="mt-3"><?= htmlspecialchars($client['company_name']) ?></h5>
            <small class="text-muted">Client</small>
          </div>
        </div>
        <div class="list-group shadow-sm">
          <a href="client-dashboard.php"         class="list-group-item list-group-item-action">
            <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
          </a>
          <a href="new_request.php"       class="list-group-item list-group-item-action">
            <i class="fas fa-plus-circle me-2"></i>Nouvelle demande
          </a>
          <a href="interface.php"         class="list-group-item list-group-item-action">
            <i class="fas fa-truck me-2"></i>Mes expéditions
          </a>
          <a href="payments.php"          class="list-group-item list-group-item-action">
            <i class="fas fa-money-bill-wave me-2"></i>Paiements
          </a>
          <a href="notifications.php"     class="list-group-item list-group-item-action">
            <i class="fas fa-bell me-2"></i>Notifications
          </a>
          <a href="requests.php"          class="list-group-item list-group-item-action active">
            <i class="fas fa-file-alt me-2"></i>Mes demandes
          </a>
          <a href="profile.php"           class="list-group-item list-group-item-action">
            <i class="fas fa-user-cog me-2"></i>Profil
          </a>
        </div>
      </div>

      <!-- MAIN CONTENT -->
      <div class="col-lg-9">
        <h3 class="mb-4"><i class="fas fa-file-alt me-2"></i>Mes demandes de fret</h3>

        <?php if (empty($requests)): ?>
          <div class="alert alert-info">
            Vous n'avez soumis aucune demande de fret.
          </div>
        <?php else: ?>
          <div class="table-responsive shadow-sm">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Itinéraire</th>
                  <th>Date de début</th>
                  <th>Quantité</th>
                  <th>Mode de paiement</th>
                  <th>Statut</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                  <td>FR-<?= $r['id'] ?></td>
                  <td><?= htmlspecialchars($r['origin']) ?> → <?= htmlspecialchars($r['destination']) ?></td>
                  <td><?= date('d/m/Y', strtotime($r['date_start'])) ?></td>
                  <td><?= htmlspecialchars($r['quantity']) ?></td>
                  <td><?= htmlspecialchars($r['mode_paiement']) ?></td>
                  <td>
                    <?php 
                      $cls = 'status-' . $r['status'];
                      $txt = ucfirst(str_replace('_',' ',$r['status']));
                    ?>
                    <span class="shipment-status <?= $cls ?>">
                      <?= $txt ?>
                    </span>
                  </td>
                  <td>
                    <a href="request_details.php?id=<?= $r['id'] ?>"
                       class="btn btn-sm btn-outline-primary">
                      <i class="fas fa-eye"></i>
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
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
