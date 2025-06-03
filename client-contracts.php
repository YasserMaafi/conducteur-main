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
      JOIN users u ON c.user_id = u.user_id 
     WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

// Fetch all contracts for this client
$contractsStmt = $pdo->prepare("
    SELECT c.*,
           g1.libelle AS origin,
           g2.libelle AS destination,
           t.train_number,
           (SELECT username 
              FROM users 
              JOIN agents ON users.user_id = agents.user_id 
             WHERE agents.agent_id = c.agent_id
            ) AS agent_name
      FROM contracts c
      JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
      JOIN gares g2 ON c.gare_destinataire = g2.id_gare
      LEFT JOIN trains t ON c.train_id = t.train_id
     WHERE c.sender_client = ? 
  ORDER BY c.created_at DESC
");
$contractsStmt->execute([$client_id]);
$contracts = $contractsStmt->fetchAll();

// Count contracts by status
$statusCounts = [
    'en_cours' => 0,
    'in_transit' => 0,
    'validé' => 0,
    'completed' => 0
];

foreach ($contracts as $contract) {
    if (isset($statusCounts[$contract['status']])) {
        $statusCounts[$contract['status']]++;
    }
}

// Set page title
$page_title = 'Mes Contrats | SNCFT';

// Include header
require_once 'includes/client_header.php';
?>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stats-card bg-warning text-white">
            <div class="card-body">
                <h6 class="card-title">En cours</h6>
                <h2><?= $statusCounts['en_cours'] ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card bg-info text-white">
            <div class="card-body">
                <h6 class="card-title">En transit</h6>
                <h2><?= $statusCounts['in_transit'] ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card bg-success text-white">
            <div class="card-body">
                <h6 class="card-title">Validés</h6>
                <h2><?= $statusCounts['validé'] ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card bg-secondary text-white">
            <div class="card-body">
                <h6 class="card-title">Complétés</h6>
                <h2><?= $statusCounts['completed'] ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- Contracts Table -->
<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-file-contract me-2"></i>Mes Contrats</h5>
    </div>
    <div class="card-body">
        <?php if (empty($contracts)): ?>
            <div class="alert alert-info">Aucun contrat trouvé</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Trajet</th>
                            <th>Train</th>
                            <th>Agent</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contracts as $contract): ?>
                            <tr>
                                <td>CT-<?= $contract['contract_id'] ?></td>
                                <td><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></td>
                                <td><?= htmlspecialchars($contract['origin']) ?> → <?= htmlspecialchars($contract['destination']) ?></td>
                                <td><?= $contract['train_number'] ?: 'Non assigné' ?></td>
                                <td><?= $contract['agent_name'] ?: 'Non assigné' ?></td>
                                <td>
                                    <span class="contract-status status-<?= $contract['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $contract['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="client-contract-details.php?id=<?= $contract['contract_id'] ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($contract['status'] === 'in_transit'): ?>
                                        <a href="track_shipment.php?id=<?= $contract['contract_id'] ?>" 
                                           class="btn btn-sm btn-info ms-1">
                                            <i class="fas fa-map-marked-alt"></i>
                                        </a>
                                    <?php endif; ?>
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