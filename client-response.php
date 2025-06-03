<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$station_filter = $_GET['station'] ?? '';
$merchandise_filter = $_GET['merchandise'] ?? '';
$client_filter = $_GET['client'] ?? '';

// Base query
$query = "
    SELECT fr.id, fr.status, fr.updated_at, fr.quantity, fr.date_start,
           c.company_name, c.client_id,
           g1.libelle AS origin_station, g2.libelle AS destination_station,
           m.description AS merchandise_type,
           n.metadata
    FROM freight_requests fr
    JOIN clients c ON fr.sender_client_id = c.client_id
    JOIN gares g1 ON fr.gare_depart = g1.id_gare
    JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
    LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
    LEFT JOIN notifications n ON fr.id = n.related_request_id
    WHERE fr.status IN ('client_confirmed', 'client_rejected')
";

// Add filters
$params = [];
if (!empty($status_filter)) {
    $query .= " AND fr.status = ?";
    $params[] = $status_filter;
}
if (!empty($station_filter)) {
    $query .= " AND (g1.libelle LIKE ? OR g2.libelle LIKE ?)";
    $params[] = "%$station_filter%";
    $params[] = "%$station_filter%";
}
if (!empty($merchandise_filter)) {
    $query .= " AND m.description LIKE ?";
    $params[] = "%$merchandise_filter%";
}
if (!empty($client_filter)) {
    $query .= " AND c.company_name LIKE ?";
    $params[] = "%$client_filter%";
}

$query .= " ORDER BY fr.updated_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$responses = $stmt->fetchAll();

// Get unique values for filter dropdowns
$stations = $pdo->query("SELECT libelle FROM gares ORDER BY libelle")->fetchAll(PDO::FETCH_COLUMN);
$merchandise_types = $pdo->query("SELECT DISTINCT description FROM merchandise ORDER BY description")->fetchAll(PDO::FETCH_COLUMN);
$clients = $pdo->query("SELECT DISTINCT company_name FROM clients ORDER BY company_name")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réponses Clients | SNCFT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-card {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .badge-confirmed {
            background-color: #28a745;
        }
        .badge-rejected {
            background-color: #dc3545;
        }
        .table-responsive {
            overflow-x: auto;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <?php include 'includes/admin_nav.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- Sidebar -->

            <!-- Main Content -->
            <div class="col-lg-9">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-user-check me-2"></i>Réponses des Clients</h5>
                    </div>
                    <div class="card-body">
                        <!-- Filter Form -->
                        <div class="filter-card mb-4">
                            <form method="GET" action="client-response.php">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Statut</label>
                                        <select name="status" class="form-select">
                                            <option value="">Tous</option>
                                            <option value="client_confirmed" <?= $status_filter === 'client_confirmed' ? 'selected' : '' ?>>Confirmé</option>
                                            <option value="client_rejected" <?= $status_filter === 'client_rejected' ? 'selected' : '' ?>>Rejeté</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Gare</label>
                                        <select name="station" class="form-select">
                                            <option value="">Toutes</option>
                                            <?php foreach ($stations as $station): ?>
                                                <option value="<?= htmlspecialchars($station) ?>" <?= $station_filter === $station ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($station) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Type de Marchandise</label>
                                        <select name="merchandise" class="form-select">
                                            <option value="">Tous</option>
                                            <?php foreach ($merchandise_types as $type): ?>
                                                <option value="<?= htmlspecialchars($type) ?>" <?= $merchandise_filter === $type ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($type) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Client</label>
                                        <select name="client" class="form-select">
                                            <option value="">Tous</option>
                                            <?php foreach ($clients as $client): ?>
                                                <option value="<?= htmlspecialchars($client) ?>" <?= $client_filter === $client ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($client) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="fas fa-filter me-1"></i> Filtrer
                                        </button>
                                        <a href="client-response.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i> Réinitialiser
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Responses Table -->
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Client</th>
                                        <th>Trajet</th>
                                        <th>Marchandise</th>
                                        <th>Quantité</th>
                                        <th>Date</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($responses)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center">Aucune réponse trouvée</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($responses as $response): ?>
                                            <?php $meta = json_decode($response['metadata'] ?? '{}', true); ?>
                                            <tr>
                                                <td>FR-<?= $response['id'] ?></td>
                                                <td><?= htmlspecialchars($response['company_name']) ?></td>
                                                <td>
                                                    <?= htmlspecialchars($response['origin_station']) ?> 
                                                    <i class="fas fa-arrow-right mx-1"></i> 
                                                    <?= htmlspecialchars($response['destination_station']) ?>
                                                </td>
                                                <td><?= htmlspecialchars($response['merchandise_type'] ?? 'N/A') ?></td>
                                                <td><?= $response['quantity'] ?> kg</td>
                                                <td><?= date('d/m/Y', strtotime($response['date_start'])) ?></td>
                                                <td>
                                                    <span class="badge <?= $response['status'] === 'client_confirmed' ? 'badge-confirmed' : 'badge-rejected' ?>">
                                                        <?= $response['status'] === 'client_confirmed' ? 'Confirmé' : 'Rejeté' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="request_details.php?id=<?= $response['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> Détails
                                                    </a>
                                                    <?php if ($response['status'] === 'client_confirmed'): ?>
                                                        <a href="create_contract.php?request_id=<?= $response['id'] ?>" class="btn btn-sm btn-success ms-1">
                                                            <i class="fas fa-file-contract"></i> Créer Contrat
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>