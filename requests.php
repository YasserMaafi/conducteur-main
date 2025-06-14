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
