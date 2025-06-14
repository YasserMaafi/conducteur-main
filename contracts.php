<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify client role and session
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
    header('Location: index.php');
    exit();
}

// Get client ID
$client_id = $_SESSION['user']['client_id'];
if (!$client_id) {
    $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $c = $stmt->fetch();
    if (!$c) {
        die("Error: Client account not properly configured");
    }
    $_SESSION['user']['client_id'] = $c['client_id'];
    $client_id = $c['client_id'];
}

// Get highlighted contract if any
$highlight_id = filter_input(INPUT_GET, 'highlight', FILTER_VALIDATE_INT);

// Fetch client info
$stmt = $pdo->prepare("
    SELECT c.*, u.email 
    FROM clients c 
    JOIN users u ON c.user_id = u.user_id 
    WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

// Fetch all contracts for this client
$stmt = $pdo->prepare("
    SELECT c.*,
           g1.libelle AS origin_station,
           g2.libelle AS destination_station,
           a.badge_number AS agent_badge,
           u.username AS agent_name,
           fr.id AS request_id
    FROM contracts c
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN agents a ON c.agent_id = a.agent_id
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN freight_requests fr ON c.freight_request_id = fr.id
    WHERE c.sender_client = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$client_id]);
$contracts = $stmt->fetchAll();

// Count unread notifications
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$stmt->execute([$_SESSION['user']['id']]);
$unread_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Contrats | SNCFT Client</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .contract-card {
            transition: all 0.3s ease;
        }
        .contract-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .highlight {
            animation: highlight 2s ease-in-out;
        }
        @keyframes highlight {
            0%, 100% { background-color: transparent; }
            50% { background-color: rgba(13, 110, 253, 0.1); }
        }
        .status-badge {
            padding: 0.35em 0.65em;
            border-radius: 30px;
            font-size: 0.85em;
        }
        .status-draft { background-color: #e2e3e5; color: #383d41; }
        .status-terminé { background-color: #d4edda; color: #155724; }
        .status-in_transit { background-color: #cce5ff; color: #004085; }
    </style>
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="client-dashboard.php">
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
            <!-- Sidebar -->
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
                    <a href="client-dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
                    </a>
                    <a href="new_request.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-plus-circle me-2"></i>Nouvelle demande
                    </a>
                    <a href="interface.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-truck me-2"></i>Mes expéditions
                    </a>
                    <a href="contracts.php" class="list-group-item list-group-item-action active">
                        <i class="fas fa-file-contract me-2"></i>Mes contrats
                    </a>
                    <a href="notifications.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-bell me-2"></i>Notifications</span>
                        <?php if($unread_count): ?>
                            <span class="badge bg-danger rounded-pill"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="profile.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-user-cog me-2"></i>Profil
                    </a>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h3 mb-0">Mes Contrats</h2>
                </div>

                <?php if (empty($contracts)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>Vous n'avez aucun contrat pour le moment.
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($contracts as $contract): ?>
                            <div class="col-md-6">
                                <div class="card contract-card <?= $highlight_id == $contract['contract_id'] ? 'highlight' : '' ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h5 class="card-title mb-0">
                                                Contrat #<?= $contract['contract_id'] ?>
                                                <?php if ($contract['request_id']): ?>
                                                    <small class="text-muted">(Demande #<?= $contract['request_id'] ?>)</small>
                                                <?php endif; ?>
                                            </h5>
                                            <span class="status-badge status-<?= $contract['status'] ?>">
                                                <?= ucfirst($contract['status']) ?>
                                            </span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <i class="fas fa-route me-2 text-primary"></i>
                                            <strong><?= htmlspecialchars($contract['origin_station']) ?></strong>
                                            <i class="fas fa-arrow-right mx-2"></i>
                                            <strong><?= htmlspecialchars($contract['destination_station']) ?></strong>
                                        </div>

                                        <div class="mb-3">
                                            <p class="mb-1">
                                                <i class="fas fa-calendar me-2"></i>
                                                Date d'expédition: <?= date('d/m/Y', strtotime($contract['shipment_date'])) ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-user-tie me-2"></i>
                                                Agent: <?= $contract['agent_name'] ? htmlspecialchars($contract['agent_name']) : 'Non assigné' ?>
                                            </p>
                                            <p class="mb-0">
                                                <i class="fas fa-boxes me-2"></i>
                                                <?= $contract['wagon_count'] ?> wagon(s)
                                            </p>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-primary fw-bold">
                                                <?= number_format($contract['total_port_due'], 2) ?> €
                                            </span>
                                            <a href="contract_details.php?id=<?= $contract['contract_id'] ?>" 
                                               class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye me-1"></i>Voir détails
                                            </a>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 