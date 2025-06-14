<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Set page title
$page_title = 'Détails du Contrat | SNCFT';

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

// Get contract ID from URL
$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$contract_id) {
    header('Location: client-contracts.php');
    exit();
}

// Fetch contract details
$stmt = $pdo->prepare("
    SELECT c.*,
           g1.libelle AS origin,
           g2.libelle AS destination,
           t.train_number,
           (SELECT username 
              FROM users 
              JOIN agents ON users.user_id = agents.user_id 
             WHERE agents.agent_id = c.agent_id
            ) AS agent_name,
           (SELECT company_name FROM clients WHERE client_id = c.recipient_client) AS recipient_company
      FROM contracts c
      JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
      JOIN gares g2 ON c.gare_destinataire = g2.id_gare
      LEFT JOIN trains t ON c.train_id = t.train_id
     WHERE c.contract_id = ? 
       AND c.sender_client = ?
");
$stmt->execute([$contract_id, $client_id]);
$contract = $stmt->fetch();

if (!$contract) {
    header('Location: client-contracts.php');
    exit();
}

// Fetch payment information
$paymentStmt = $pdo->prepare("
    SELECT * FROM payments 
     WHERE contract_id = ? 
  ORDER BY payment_date DESC
");
$paymentStmt->execute([$contract_id]);
$payments = $paymentStmt->fetchAll();

// Calculate payment totals
$totalPaid = 0;
$totalDue = $contract['total_port_due'];
foreach ($payments as $payment) {
    if ($payment['status'] === 'completed') {
        $totalPaid += $payment['amount'];
    }
}
$remainingDue = $totalDue - $totalPaid;

// Include header
require_once 'includes/client_header.php';
?>

<!-- Contract Header -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                Contrat #CT-<?= $contract['contract_id'] ?>
                <span class="contract-status status-<?= $contract['status'] ?> ms-2">
                    <?= ucfirst(str_replace('_', ' ', $contract['status'])) ?>
                </span>
            </h4>
            <div>
                <?php if ($contract['status'] === 'in_transit'): ?>
                    <a href="track_shipment.php?id=<?= $contract['contract_id'] ?>" 
                       class="btn btn-info">
                        <i class="fas fa-map-marked-alt"></i> Suivre l'expédition
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Contract Details -->
<div class="row">
    <!-- Main Information -->
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informations du Contrat</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Détails de l'Expédition</h6>
                        <table class="table table-sm">
                            <tr>
                                <th>Date d'expédition:</th>
                                <td><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Gare de départ:</th>
                                <td><?= htmlspecialchars($contract['origin']) ?></td>
                            </tr>
                            <tr>
                                <th>Gare d'arrivée:</th>
                                <td><?= htmlspecialchars($contract['destination']) ?></td>
                            </tr>
                            <tr>
                                <th>Train assigné:</th>
                                <td><?= $contract['train_number'] ?: 'Non assigné' ?></td>
                            </tr>
                            <tr>
                                <th>Agent responsable:</th>
                                <td><?= $contract['agent_name'] ?: 'Non assigné' ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Détails de la Marchandise</h6>
                        <table class="table table-sm">
                            <tr>
                                <th>Description:</th>
                                <td><?= htmlspecialchars($contract['merchandise_description']) ?></td>
                            </tr>
                            <tr>
                                <th>Poids:</th>
                                <td><?= number_format($contract['shipment_weight'], 2) ?> kg</td>
                            </tr>
                            <tr>
                                <th>Nombre de wagons:</th>
                                <td><?= $contract['wagon_count'] ?></td>
                            </tr>
                            <tr>
                                <th>Nombre de bâches:</th>
                                <td><?= $contract['tarp_count'] ?></td>
                            </tr>
                            <tr>
                                <th>Unités totales:</th>
                                <td><?= $contract['total_units'] ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Historique des Paiements</h5>
            </div>
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <div class="alert alert-info">Aucun paiement enregistré</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Montant</th>
                                    <th>Méthode</th>
                                    <th>Référence</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                                        <td><?= number_format($payment['amount'], 2) ?> TND</td>
                                        <td><?= ucfirst($payment['payment_method']) ?></td>
                                        <td><?= $payment['reference_number'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($payment['status']) ?>
                                            </span>
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

    <!-- Payment Summary -->
    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Résumé des Paiements</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Montant Total</h6>
                    <h3 class="text-primary"><?= number_format($totalDue, 2) ?> TND</h3>
                </div>
                <div class="mb-3">
                    <h6>Montant Payé</h6>
                    <h3 class="text-success"><?= number_format($totalPaid, 2) ?> TND</h3>
                </div>
                <div class="mb-3">
                    <h6>Montant Restant</h6>
                    <h3 class="text-danger"><?= number_format($remainingDue, 2) ?> TND</h3>
                </div>
                <?php if ($remainingDue > 0): ?>
                    <a href="client-payments.php?contract_id=<?= $contract['contract_id'] ?>" 
                       class="btn btn-primary w-100">
                        <i class="fas fa-credit-card me-2"></i>Effectuer un Paiement
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informations Supplémentaires</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th>Mode de paiement:</th>
                        <td><?= ucfirst($contract['payment_mode']) ?></td>
                    </tr>
                    <tr>
                        <th>Type de transaction:</th>
                        <td><?= ucfirst($contract['transaction_type']) ?></td>
                    </tr>
                    <tr>
                        <th>Date de création:</th>
                        <td><?= date('d/m/Y H:i', strtotime($contract['created_at'])) ?></td>
                    </tr>
                    <?php if ($contract['updated_at']): ?>
                        <tr>
                            <th>Dernière mise à jour:</th>
                            <td><?= date('d/m/Y H:i', strtotime($contract['updated_at'])) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
require_once 'includes/client_footer.php';
?> 