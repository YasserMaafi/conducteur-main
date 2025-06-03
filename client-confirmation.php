<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Set page title
$page_title = "Confirmations Clients | SNCFT Admin";

// Include header
require_once 'includes/header.php';

// Include sidebar
require_once 'includes/sidebar.php';
?>

<!-- Main Content -->
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

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-user-check me-2"></i>Confirmations Clients</h5>
        </div>
        <div class="card-body">
            <?php
            $client_responses = $pdo->query("
                SELECT fr.id, fr.status, fr.updated_at, 
                       c.company_name, 
                       g1.libelle AS origin, g2.libelle AS destination,
                       n.metadata
                FROM freight_requests fr
                JOIN clients c ON fr.sender_client_id = c.client_id
                JOIN gares g1 ON fr.gare_depart = g1.id_gare
                JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
                JOIN notifications n ON fr.id = n.related_request_id
                WHERE fr.status IN ('client_confirmed', 'client_rejected')
                ORDER BY fr.updated_at DESC
                LIMIT 10
            ")->fetchAll();
            ?>
            
            <?php if (empty($client_responses)): ?>
                <div class="alert alert-info">Aucune réponse client récente</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Client</th>
                                <th>Trajet</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th>Détails</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($client_responses as $response): 
                                $meta = json_decode($response['metadata'] ?? '{}', true);
                            ?>
                                <tr>
                                    <td>FR-<?= $response['id'] ?></td>
                                    <td><?= htmlspecialchars($response['company_name']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($response['origin']) ?> 
                                        <i class="fas fa-arrow-right mx-1"></i> 
                                        <?= htmlspecialchars($response['destination']) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $response['status'] === 'client_confirmed' ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $response['status'] === 'client_confirmed' ? 'Confirmée' : 'Rejetée' ?>
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($response['updated_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                            data-bs-target="#detailsModal<?= $response['id'] ?>">
                                            <i class="fas fa-eye"></i> Détails
                                        </button>
                                    </td>
                                    <td>
                                        <?php if ($response['status'] === 'client_confirmed'): ?>
                                            <a href="create_contract.php?request_id=<?= $response['id'] ?>" 
                                               class="btn btn-sm btn-primary">
                                               <i class="fas fa-file-contract"></i> Créer Contrat
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary" disabled>
                                                <i class="fas fa-times-circle"></i> Refusé
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- Details Modal -->
                                <div class="modal fade" id="detailsModal<?= $response['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Détails de la réponse #<?= $response['id'] ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <ul class="list-group">
                                                    <li class="list-group-item">
                                                        <strong>Client:</strong> <?= htmlspecialchars($response['company_name']) ?>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Trajet:</strong> <?= htmlspecialchars($response['origin']) ?> → <?= htmlspecialchars($response['destination']) ?>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Statut:</strong> 
                                                        <span class="badge <?= $response['status'] === 'client_confirmed' ? 'bg-success' : 'bg-danger' ?>">
                                                            <?= $response['status'] === 'client_confirmed' ? 'Confirmée' : 'Rejetée' ?>
                                                        </span>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Date de réponse:</strong> <?= date('d/m/Y H:i', strtotime($response['updated_at'])) ?>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Prix:</strong> <?= htmlspecialchars($meta['price'] ?? 'N/A') ?> €
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>Nombre de wagons:</strong> <?= htmlspecialchars($meta['wagon_count'] ?? 'N/A') ?>
                                                    </li>
                                                    <li class="list-group-item">
                                                        <strong>ETA:</strong> <?= htmlspecialchars($meta['eta'] ?? 'N/A') ?>
                                                    </li>
                                                    <?php if ($response['status'] === 'client_rejected' && isset($meta['reason'])): ?>
                                                    <li class="list-group-item">
                                                        <strong>Raison du refus:</strong> <?= htmlspecialchars($meta['reason']) ?>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                                <?php if ($response['status'] === 'client_confirmed'): ?>
                                                    <a href="create_contract.php?request_id=<?= $response['id'] ?>" 
                                                       class="btn btn-primary">
                                                       <i class="fas fa-file-contract"></i> Créer Contrat
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Include footer
require_once 'includes/footer.php';
?>