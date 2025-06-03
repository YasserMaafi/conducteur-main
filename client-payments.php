<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Set page title
$page_title = 'Mes Paiements | SNCFT';

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
      JOIN users u ON c.user_id = u.user_id 
     WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

// Fetch all payments for this client
$paymentsStmt = $pdo->prepare("
    SELECT p.*,
           c.contract_id,
           c.shipment_date,
           g1.libelle AS origin,
           g2.libelle AS destination
      FROM payments p
      JOIN contracts c ON p.contract_id = c.contract_id
      JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
      JOIN gares g2 ON c.gare_destinataire = g2.id_gare
     WHERE c.sender_client = ? 
  ORDER BY p.payment_date DESC
");
$paymentsStmt->execute([$client_id]);
$payments = $paymentsStmt->fetchAll();

// Calculate payment statistics
$totalPaid = 0;
$totalPending = 0;
$totalFailed = 0;

foreach ($payments as $payment) {
    switch ($payment['status']) {
        case 'completed':
            $totalPaid += $payment['amount'];
            break;
        case 'pending':
            $totalPending += $payment['amount'];
            break;
        case 'failed':
            $totalFailed += $payment['amount'];
            break;
    }
}

// Include header
require_once 'includes/client_header.php';
?>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stats-card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Total Payé</h6>
                <h2><?= number_format($totalPaid, 2) ?> TND</h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stats-card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">En Attente</h6>
                <h2><?= number_format($totalPending, 2) ?> TND</h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stats-card bg-danger text-white">
            <div class="card-body">
                <h6 class="card-title">Échoués</h6>
                <h2><?= number_format($totalFailed, 2) ?> TND</h2>
            </div>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Historique des Paiements</h5>
    </div>
    <div class="card-body">
        <?php if (empty($payments)): ?>
            <div class="alert alert-info">Aucun paiement trouvé</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Contrat</th>
                            <th>Trajet</th>
                            <th>Montant</th>
                            <th>Méthode</th>
                            <th>Référence</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($payment['payment_date'])) ?></td>
                                <td>CT-<?= $payment['contract_id'] ?></td>
                                <td><?= htmlspecialchars($payment['origin']) ?> → <?= htmlspecialchars($payment['destination']) ?></td>
                                <td><?= number_format($payment['amount'], 2) ?> TND</td>
                                <td><?= ucfirst($payment['payment_method']) ?></td>
                                <td><?= $payment['reference_number'] ?></td>
                                <td>
                                    <span class="payment-status <?= $payment['status'] ?>">
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="payment-details.php?id=<?= $payment['payment_id'] ?>" 
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

<?php
// Include footer
require_once 'includes/client_footer.php';
?> 