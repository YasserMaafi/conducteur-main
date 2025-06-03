<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify client role and session
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../index.php');
    exit();
}

// Ensure client_id in session
if (!isset($_SESSION['user']['client_id'])) {
    $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $c = $stmt->fetch();
    if (!$c) {
        die("Error: Client account not properly configured");
    }
    $_SESSION['user']['client_id'] = $c['client_id'];
}
$client_id = $_SESSION['user']['client_id'];

// Fetch client info
$stmt = $pdo->prepare("
    SELECT c.*, u.email 
      FROM clients c 
      JOIN users   u ON c.user_id = u.user_id 
     WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();
if (!$client) {
    die("Error: Could not retrieve client information");
}

// 1) Fetch recent freight requests
$requestsStmt = $pdo->prepare("
    SELECT fr.*,
           g1.libelle AS origin,
           g2.libelle AS destination
      FROM freight_requests fr
      JOIN gares g1 ON fr.gare_depart   = g1.id_gare
      JOIN gares g2 ON fr.gare_arrivee  = g2.id_gare
     WHERE fr.sender_client_id = ?
  ORDER BY fr.created_at DESC
     LIMIT 5
");
$requestsStmt->execute([$client_id]);
$requests = $requestsStmt->fetchAll();

// 2) Fetch active shipments
// 2) Fetch active shipments - Updated query to include more statuses
$activeStmt = $pdo->prepare("
    SELECT c.*,
           g1.libelle AS origin,
           g2.libelle AS destination,
           (SELECT username 
              FROM users 
              JOIN agents ON users.user_id = agents.user_id 
             WHERE agents.agent_id = c.agent_id
            ) AS agent_name
      FROM contracts c
      JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
      JOIN gares g2 ON c.gare_destinataire = g2.id_gare
     WHERE c.sender_client = ? 
       AND c.status IN ('en_cours', 'in_transit', 'validé')
       AND c.shipment_date <= CURRENT_DATE
     LIMIT 5
");
$activeStmt->execute([$client_id]);
$active_shipments = $activeStmt->fetchAll();

// 3) Fetch unread notifications for this user
$user_id = $_SESSION['user']['id'];
$notifStmt = $pdo->prepare("
    SELECT * 
      FROM notifications 
     WHERE user_id = ? 
       AND is_read = FALSE
  ORDER BY created_at DESC
     LIMIT 5
");
$notifStmt->execute([$user_id]);
$notifications = $notifStmt->fetchAll();

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
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tableau de Bord Client | SNCFT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    .quick-action-card { transition: transform .2s; cursor: pointer; }
    .quick-action-card:hover { transform: translateY(-5px); box-shadow:0 10px 20px rgba(0,0,0,.1); }
    .notification-item.unread { background-color: #f8f9fa; border-left:4px solid #0d6efd; }
    .shipment-status { display:inline-block; padding:3px 8px; border-radius:20px; font-size:.8rem; font-weight:500; }
    .status-pending     { background:#fff3cd; color:#856404; }
    .status-approved    { background:#d4edda; color:#155724; }
    .status-in_transit  { background:#cce5ff; color:#004085; }
    .status-delivered   { background:#e2e3e5; color:#383d41; }
    .status-rejected    { background:#f8d7da; color:#721c24; }
    
    /* Notification dropdown styles */
    .dropdown-notifications {
      min-width: 320px;
      padding: 0;
      max-height: 400px;
      overflow-y: auto;
    }
    .dropdown-notifications .dropdown-header {
      background-color: #f8f9fa;
      padding: 10px 15px;
      border-bottom: 1px solid #e9ecef;
      font-weight: 600;
    }
    .dropdown-notifications .dropdown-footer {
      background-color: #f8f9fa;
      padding: 10px;
      text-align: center;
      border-top: 1px solid #e9ecef;
    }
    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      font-size: 0.6rem;
      padding: 2px 5px;
    }
    .navbar-notification-icon {
      font-size: 1.2rem;
      position: relative;
    }
  </style>
</head>
<body class="bg-light">
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

        <div class="list-group mb-4">
          <a href="client-dashboard.php"     class="list-group-item list-group-item-action">
            <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
          </a>
          <a href="new_request.php"   class="list-group-item list-group-item-action">
            <i class="fas fa-plus-circle me-2"></i>Nouvelle demande
          </a>
          <a href="interface.php"     class="list-group-item list-group-item-action">
            <i class="fas fa-truck me-2"></i>Mes expéditions
          </a>
          <a href="client-payments.php"      class="list-group-item list-group-item-action">
            <i class="fas fa-money-bill-wave me-2"></i>Paiements
          </a>
          <a href="client-contracts.php"     class="list-group-item list-group-item-action">
            <i class="fas fa-file-contract me-2"></i>Contrats
          </a>
          <a href="notifications.php" class="list-group-item list-group-item-action">
            <i class="fas fa-bell me-2"></i>Notifications
          </a>
          <a href="requests.php"      class="list-group-item list-group-item-action active">
            <i class="fas fa-file-alt me-2"></i>Mes demandes
          </a>
          <a href="profile.php"       class="list-group-item list-group-item-action">
            <i class="fas fa-user-cog me-2"></i>Profil
          </a>
        </div>

        <!-- Notifications Panel -->
        <div class="card shadow-sm">
          <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-bell me-2"></i>Notifications</h6>
            <?php if ($notifCount > 0): ?>
              <span class="badge bg-danger"><?= $notifCount ?></span>
            <?php endif; ?>
          </div>
          <div class="card-body p-0">
            <?php if (empty($notifications)): ?>
              <div class="p-3 text-center text-muted">
                <i class="fas fa-bell-slash mb-2"></i>
                <p class="mb-0">Aucune nouvelle notification</p>
              </div>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($notifications as $n): ?>
                  <?php 
                    $md = json_decode($n['metadata'] ?? '{}', true);
                    $link = $md['link'] ?? '#';
                    $cls = $n['is_read'] ? '' : 'unread';
                  ?>
                  <a href="<?= htmlspecialchars($link) ?>" 
                     class="list-group-item list-group-item-action notification-item <?= $cls ?>" 
                     data-id="<?= $n['id'] ?>">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <h6 class="mb-1"><?= htmlspecialchars($n['title']) ?></h6>
                        <p class="mb-1 small text-muted"><?= htmlspecialchars($n['message']) ?></p>
                        <small class="text-muted">
                          <?= time_elapsed_string($n['created_at']) ?>
                        </small>
                      </div>
                      <?php if (!$n['is_read']): ?>
                        <span class="badge bg-primary">Nouveau</span>
                      <?php endif; ?>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-footer bg-white text-center">
            <a href="notifications.php" class="btn btn-sm btn-link">
              <i class="fas fa-arrow-right me-1"></i>Voir toutes
            </a>
          </div>
        </div>
      </div>

      <!-- MAIN -->
      <div class="col-lg-9">
        <!-- Quick Actions -->
        <div class="row mb-4">
          <div class="col-md-4">
            <div class="card quick-action-card h-100" onclick="location.href='new_request.php'">
              <div class="card-body text-center">
                <div class="icon-circle bg-primary text-white mb-3 mx-auto">
                  <i class="fas fa-plus"></i>
                </div>
                <h6>Nouvelle Demande</h6>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card quick-action-card h-100" onclick="location.href='interface.php'">
              <div class="card-body text-center">
                <div class="icon-circle bg-success text-white mb-3 mx-auto">
                  <i class="fas fa-truck"></i>
                </div>
                <h6>Suivi Expédition</h6>
                <p class="text-muted small"><?= count($active_shipments) ?> en cours</p>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card quick-action-card h-100" onclick="location.href='client-payments.php'">
              <div class="card-body text-center">
                <div class="icon-circle bg-info text-white mb-3 mx-auto">
                  <i class="fas fa-money-bill-wave"></i>
                </div>
                <h6>Paiements</h6>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Requests -->
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-file-alt me-2"></i>Mes Demandes Récentes</h5>
            <a href="requests.php" class="btn btn-sm btn-outline-primary">Voir toutes</a>
          </div>
          <div class="card-body">
            <?php if (empty($requests)): ?>
              <div class="alert alert-info">Aucune demande de fret trouvée</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>ID</th><th>Trajet</th><th>Date</th><th>Destinataire</th><th>Statut</th><th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($requests as $r): ?>
                      <tr>
                        <td>FR-<?= $r['id'] ?></td>
                        <td><?= htmlspecialchars($r['origin']) ?> → <?= htmlspecialchars($r['destination']) ?></td>
                        <td><?= date('d/m/Y', strtotime($r['date_start'])) ?></td>
                        <td><?= htmlspecialchars($r['recipient_name']) ?></td>
                        <td>
                          <?php 
                            $cls = 'status-' . $r['status'];
                            $txt = ucfirst(str_replace('_',' ',$r['status']));
                          ?>
                          <span class="shipment-status <?= $cls ?>"><?= $txt ?></span>
                        </td>
                        <td>
                          <a href="request_details.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
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

        <!-- Active Shipments -->
        <div class="card shadow-sm">
          <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-truck me-2"></i>Expéditions Actives</h5>
            <a href="shipments.php" class="btn btn-sm btn-outline-primary">Voir toutes</a>
          </div>
          <div class="card-body">
            <?php if (empty($active_shipments)): ?>
              <div class="alert alert-info">Aucune expédition active</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>CT-ID</th><th>Trajet</th><th>Date</th><th>Agent</th><th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($active_shipments as $s): ?>
                      <tr>
                        <td>CT-<?= $s['contract_id'] ?></td>
                        <td><?= htmlspecialchars($s['origin']) ?> → <?= htmlspecialchars($s['destination']) ?></td>
                        <td><?= date('d/m/Y', strtotime($s['shipment_date'])) ?></td>
                        <td><?= $s['agent_name'] ?: '<span class="text-muted">Non assigné</span>' ?></td>
                        <td>
                          <a href="client-contract-details.php?id=<?= $s['contract_id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye"></i>
                          </a>
                          <a href="track_shipment.php?id=<?= $s['contract_id'] ?>" class="btn btn-sm btn-info ms-1">
                            <i class="fas fa-map-marked-alt"></i>
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
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Mark notification as read on click
    document.querySelectorAll('.notification-item').forEach(item => {
      item.addEventListener('click', function() {
        if (!this.classList.contains('unread')) return;
        this.classList.remove('unread');
        fetch('mark_notification_read.php?id=' + this.dataset.id, { method: 'POST' });
      });
    });
  </script>
</body>
</html>

<?php
// Helper function to display relative time
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'an',
        'm' => 'mois',
        'w' => 'semaine',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 && $k != 'm' ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? 'Il y a ' . implode(', ', $string) : 'À l\'instant';
}
?>