<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Set page title
$page_title = 'Détails du Paiement | SNCFT';

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

// Get payment ID from URL
$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$payment_id) {
    header('Location: client-payments.php');
    exit();
}

// Fetch payment details
$stmt = $pdo->prepare("
    SELECT p.*,
           c.contract_id,
           c.shipment_date,
           c.total_port_due,
           g1.libelle AS origin,
           g2.libelle AS destination,
           (SELECT username 
              FROM users 
              JOIN agents ON users.user_id = agents.user_id 
             WHERE agents.agent_id = c.agent_id
            ) AS agent_name
      FROM payments p
      JOIN contracts c ON p.contract_id = c.contract_id
      JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
      JOIN gares g2 ON c.gare_destinataire = g2.id_gare
     WHERE p.payment_id = ? 
       AND c.sender_client = ?
");
$stmt->execute([$payment_id, $client_id]);
$payment = $stmt->fetch();

if (!$payment) {
    header('Location: client-payments.php');
    exit();
}

// Include header
require_once 'includes/client_header.php';
?>

<!-- Payment Header -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                Paiement #<?= $payment['payment_id'] ?>
                <span class="payment-status <?= $payment['status'] ?> ms-2">
                    <?= ucfirst($payment['status']) ?>
                </span>
            </h4>
            <a href="client-payments.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Retour aux paiements
            </a>
        </div>
    </div>
</div>

<!-- Payment Details -->
<div class="row">
    <!-- Main Information -->
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Détails du Paiement</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Informations de Paiement</h6>
                        <table class="table table-sm">
                            <tr>
                                <th>Date:</th>
                                <td><?= date('d/m/Y H:i', strtotime($payment['payment_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Montant:</th>
                                <td><?= number_format($payment['amount'], 2) ?> TND</td>
                            </tr>
                            <tr>
                                <th>Méthode:</th>
                                <td><?= ucfirst($payment['payment_method']) ?></td>
                            </tr>
                            <tr>
                                <th>Référence:</th>
                                <td><?= $payment['reference_number'] ?: 'Non spécifiée' ?></td>
                            </tr>
                            <tr>
                                <th>Statut:</th>
                                <td>
                                    <span class="payment-status <?= $payment['status'] ?>">
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Détails du Contrat</h6>
                        <table class="table table-sm">
                            <tr>
                                <th>Contrat:</th>
                                <td>CT-<?= $payment['contract_id'] ?></td>
                            </tr>
                            <tr>
                                <th>Date d'expédition:</th>
                                <td><?= date('d/m/Y', strtotime($payment['shipment_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Trajet:</th>
                                <td><?= htmlspecialchars($payment['origin']) ?> → <?= htmlspecialchars($payment['destination']) ?></td>
                            </tr>
                            <tr>
                                <th>Agent:</th>
                                <td><?= $payment['agent_name'] ?: 'Non assigné' ?></td>
                            </tr>
                            <tr>
                                <th>Port total dû:</th>
                                <td><?= number_format($payment['total_port_due'], 2) ?> TND</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($payment['notes']): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Notes</h5>
                </div>
                <div class="card-body">
                    <?= nl2br(htmlspecialchars($payment['notes'])) ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Payment Summary -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Résumé</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6>Montant Total Dû</h6>
                    <h3 class="text-primary"><?= number_format($payment['total_port_due'], 2) ?> TND</h3>
                </div>
                <div class="mb-3">
                    <h6>Montant Payé</h6>
                    <h3 class="text-success"><?= number_format($payment['amount'], 2) ?> TND</h3>
                </div>
                <div class="mb-3">
                    <h6>Montant Restant</h6>
                    <h3 class="text-danger"><?= number_format($payment['total_port_due'] - $payment['amount'], 2) ?> TND</h3>
                </div>
                <?php if ($payment['status'] === 'pending'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Ce paiement est en attente de confirmation
                    </div>
                <?php elseif ($payment['status'] === 'failed'): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle me-2"></i>
                        Ce paiement a échoué
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
require_once 'includes/client_footer.php';
?> 