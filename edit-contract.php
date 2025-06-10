<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get contract ID
$contract_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contract_id) {
    $_SESSION['error'] = "ID de contrat invalide";
    header('Location: admin-contracts.php');
    exit();
}

// Get contract details with corrected JOIN
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name, 
           g1.libelle AS origin_station, 
           g2.libelle AS destination_station,
           fr.merchandise_id,
           m.description AS merchandise_type,
           m.code AS merchandise_code
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN freight_requests fr ON c.freight_request_id = fr.id
    LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
    WHERE c.contract_id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé";
    header('Location: admin-contracts.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $shipment_date = filter_input(INPUT_POST, 'shipment_date', FILTER_SANITIZE_STRING);
    $shipment_weight = filter_input(INPUT_POST, 'shipment_weight', FILTER_VALIDATE_FLOAT);
    $wagon_count = filter_input(INPUT_POST, 'wagon_count', FILTER_VALIDATE_INT);
    $gare_expeditrice = filter_input(INPUT_POST, 'gare_expéditrice', FILTER_VALIDATE_INT);
    $gare_destinataire = filter_input(INPUT_POST, 'gare_destinataire', FILTER_VALIDATE_INT);
    $merchandise_id = filter_input(INPUT_POST, 'merchandise_id', FILTER_VALIDATE_INT);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    // Update contract
    $stmt = $pdo->prepare("
        UPDATE contracts 
        SET shipment_date = ?, 
            shipment_weight = ?, 
            wagon_count = ?, 
            gare_expéditrice = ?,
            gare_destinataire = ?,
            notes = ?,
            status = 'draft',
            updated_at = NOW()
        WHERE contract_id = ?
    ");
    
    if ($stmt->execute([$shipment_date, $shipment_weight, $wagon_count, $gare_expeditrice, $gare_destinataire, $notes, $contract_id])) {
        // If there's a freight request, update merchandise info
        if ($contract['freight_request_id']) {
            $stmt = $pdo->prepare("
                UPDATE freight_requests 
                SET merchandise_id = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$merchandise_id, $contract['freight_request_id']]);
        }
        
        $_SESSION['success'] = "Contrat mis à jour avec succès";
        header("Location: admin-contract-details.php?id=$contract_id");
        exit();
    } else {
        $_SESSION['error'] = "Erreur lors de la mise à jour du contrat";
    }
}

// Get stations for dropdown
$stations = $pdo->query("SELECT id_gare, libelle FROM gares ORDER BY libelle")->fetchAll();

// Get merchandise types
$merchandise = $pdo->query("SELECT merchandise_id, description FROM merchandise ORDER BY description")->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier Contrat | SNCFT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        <?php require_once 'assets/css/style.css'; ?>
        /* Add your custom styles here */
    </style>
</head>
<body>
    <!-- Include your navbar and sidebar similar to admin-contract-details.php -->

    <div class="main-content">
        <div class="container-fluid">
            <!-- Alerts -->
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

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-edit me-2"></i>Modifier Contrat #<?= $contract_id ?></h2>
                <a href="admin-contract-details.php?id=<?= $contract_id ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-2"></i>Annuler
                </a>
            </div>

            <form method="post">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-file-contract me-2"></i>Informations de base
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($contract['client_name']) ?>" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date d'expédition</label>
                                <input type="date" class="form-control" name="shipment_date" 
                                       value="<?= htmlspecialchars($contract['shipment_date']) ?>" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-route me-2"></i>Informations de trajet
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gare d'origine</label>
                                <select class="form-select" name="gare_expéditrice" required>
                                    <?php foreach ($stations as $station): ?>
                                        <option value="<?= $station['id_gare'] ?>" <?= $station['id_gare'] == $contract['gare_expéditrice'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($station['libelle']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gare de destination</label>
                                <select class="form-select" name="gare_destinataire" required>
                                    <?php foreach ($stations as $station): ?>
                                        <option value="<?= $station['id_gare'] ?>" <?= $station['id_gare'] == $contract['gare_destinataire'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($station['libelle']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-box me-2"></i>Détails de la marchandise
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Type de marchandise</label>
                                <select class="form-select" name="merchandise_id">
                                    <option value="">Sélectionner...</option>
                                    <?php foreach ($merchandise as $item): ?>
                                        <option value="<?= $item['merchandise_id'] ?>" <?= $item['merchandise_id'] == $contract['merchandise_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($item['description']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Poids (kg)</label>
                                <input type="number" step="0.01" class="form-control" name="shipment_weight" 
                                       value="<?= htmlspecialchars($contract['shipment_weight']) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre de wagons</label>
                                <input type="number" class="form-control" name="wagon_count" 
                                       value="<?= htmlspecialchars($contract['wagon_count']) ?>" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-sticky-note me-2"></i>Notes
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" name="notes" rows="3"><?= htmlspecialchars($contract['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>