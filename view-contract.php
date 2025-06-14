<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

$contractId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$contractId) {
    $_SESSION['error'] = "ID de contrat invalide";
    redirect('agent-dashboard.php');
}

// Get contract details with client and station information
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name,
           g1.libelle AS origin, g2.libelle AS destination
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    WHERE c.contract_id = ? AND c.agent_id = ?
");
$stmt->execute([$contractId, $_SESSION['user']['id']]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé";
    redirect('agent-dashboard.php');
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails Contrat | SNCFT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">
    <!-- Navigation same as agent-dashboard -->

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-file-contract me-2"></i>Contrat #<?= $contractId ?></h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Client</h5>
                                <p><?= htmlspecialchars($contract['client_name']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5>Statut</h5>
                                <span class="badge bg-<?= 
                                    $contract['status'] === 'draft' ? 'warning' : 
                                    ($contract['status'] === 'completed' ? 'success' : 'info') 
                                ?>">
                                    <?= ucfirst($contract['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Trajet</h5>
                                <p><?= htmlspecialchars($contract['origin']) ?> → <?= htmlspecialchars($contract['destination']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5>Prix (€)</h5>
                                <p><?= $contract['price_quoted'] ?></p>
                            </div>
                        </div>
                        
                        <?php if ($contract['status'] !== 'draft'): ?>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Poids (kg)</h5>
                                <p><?= $contract['shipment_weight'] ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5>Nombre de Wagons</h5>
                                <p><?= $contract['wagon_count'] ?></p>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Date d'Expédition</h5>
                                <p><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5>Date d'Arrivée Estimée</h5>
                                <p><?= date('d/m/Y', strtotime($contract['estimated_arrival'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-end mt-4">
                            <a href="agent-dashboard.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1"></i> Retour
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>