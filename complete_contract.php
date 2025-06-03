<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

// Get contract ID
$contract_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contract_id) {
    $_SESSION['error'] = "ID de contrat invalide";
    redirect('agent-dashboard.php');
}

// Get agent data
$stmt = $pdo->prepare("SELECT agent_id FROM agents WHERE user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();

// Get contract details with all prefilled info
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name, cl.client_id, cl.account_code,
           g1.libelle AS origin, g1.code_gare AS origin_code,
           g2.libelle AS destination, g2.code_gare AS destination_code,
           m.description AS merchandise_type, m.code AS merchandise_code,
           c.merchandise_description AS request_description,
           fr.recipient_name, fr.recipient_contact,
           a.username AS admin_username
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN merchandise m ON c.merchandise_description = m.description
    LEFT JOIN freight_requests fr ON fr.id = c.freight_request_id
    LEFT JOIN users a ON a.user_id = c.draft_created_by
    WHERE c.contract_id = ? AND c.agent_id = ? AND c.status = 'draft'
");
$stmt->execute([$contract_id, $agent['agent_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé ou déjà complété";
    redirect('agent-dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
try {
    $pdo->beginTransaction();
    
    // Validate required fields
    $required = [
        'shipment_weight' => 'Poids de l\'expédition',
        'wagon_count' => 'Nombre de wagons',
        'tarp_count' => 'Nombre de bâches',
        'total_units' => 'Nombre total d\'unités'
    ];
    
    foreach ($required as $field => $name) {
        if (empty($_POST[$field])) {
            throw new Exception("Le champ '$name' est requis");
        }
    }

    // Update contract with additional details
    $update_data = [
        'shipment_weight' => $_POST['shipment_weight'],
        'wagon_count' => $_POST['wagon_count'],
        'tarp_count' => $_POST['tarp_count'],
        'total_units' => $_POST['total_units'],
        'accessories' => $_POST['accessories'] ?? null,
        'expenses' => $_POST['expenses'] ?? null,
        'reimbursement' => $_POST['reimbursement'] ?? null,
        'paid_port' => $_POST['paid_port'] ?? null,
        'total_port_due' => $_POST['total_port_due'] ?? null,
        'analytical_allocation' => $_POST['analytical_allocation'] ?? null,
        'part_sncf' => $_POST['part_sncf'] ?? null,
        'part_oncf' => $_POST['part_oncf'] ?? null,
        'status' => 'validé',
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    $set_clause = implode(', ', array_map(fn($field) => "$field = ?", array_keys($update_data)));
    
    // Update contract
    $stmt = $pdo->prepare("
        UPDATE contracts 
        SET $set_clause
        WHERE contract_id = ?
    ");
    $stmt->execute([...array_values($update_data), $contract_id]);
    
    // Update freight request status to 'contract_completed' if we have a freight_request_id
    if ($contract['freight_request_id']) {
        $stmt = $pdo->prepare("
            UPDATE freight_requests 
            SET status = 'contract_completed', updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$contract['freight_request_id']]);
    }
    
    // Send notification to admin
    $admin_id = $pdo->query("SELECT user_id FROM admins LIMIT 1")->fetchColumn();
    
    if ($admin_id) {
        $notificationData = [
            'contract_id' => $contract_id,
            'freight_request_id' => $contract['freight_request_id'] ?? null,
            'status' => 'terminé'
        ];
        
        createNotification(
            $admin_id,
            'contract_completed',
            'Contrat Complété',
            "Le contrat #$contract_id a été complété par l'agent " . $_SESSION['user']['username'],
            $contract_id,
            $notificationData
        );
    }

    // Send notification to the client
    $stmt = $pdo->prepare("
        SELECT u.user_id 
        FROM users u 
        JOIN clients c ON u.user_id = c.user_id 
        WHERE c.client_id = ?
    ");
    $stmt->execute([$contract['sender_client']]);
    $client_user_id = $stmt->fetchColumn();

    if ($client_user_id) {
        $notificationData = [
            'contract_id' => $contract_id,
            'link' => "contracts.php?highlight=" . $contract_id,
            'status' => 'terminé'
        ];
        
        createNotification(
            $client_user_id,
            'contract_completed',
            'Contrat Finalisé',
            "Votre contrat #$contract_id a été finalisé et complété par notre agent.",
            $contract_id,
            $notificationData
        );
    }
    
    $pdo->commit();
    $_SESSION['success'] = "Contrat #$contract_id complété avec succès";
    redirect('agent-dashboard.php');
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = "Erreur: " . $e->getMessage();
}
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compléter Contrat | SNCFT Agent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-section {
            border-left: 4px solid #0d6efd;
            padding-left: 15px;
            margin-bottom: 20px;
        }
        .form-label {
            font-weight: 500;
        }
        .form-control[readonly] {
            background-color: #f8f9fa;
        }
        .prefilled-info {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .input-group-text {
            min-width: 120px;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="agent-dashboard.php">
                <i class="fas fa-train me-2"></i>SNCFT Agent
            </a>
            <a href="agent-dashboard.php" class="btn btn-outline-light">
                <i class="fas fa-arrow-left me-2"></i> Retour
            </a>
        </div>
    </nav>
    
    <div class="container mt-4">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-12 mx-auto">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="fas fa-file-contract me-2"></i> Compléter Contrat #CT-<?= $contract_id ?></h4>
                        <p class="mb-0">Créé par: <?= htmlspecialchars($contract['admin_username'] ?? 'Administrateur') ?></p>
                    </div>
                    <div class="card-body">
                        <!-- Prefilled Information -->
                        <div class="prefilled-info mb-4">
                            <h5><i class="fas fa-info-circle me-2"></i>Informations Préremplies (Non Modifiables)</h5>
                            
                            <!-- Client Information -->
                            <div class="form-section mt-3">
                                <h6><i class="fas fa-building me-2"></i>Information Client</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Client Expéditeur</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['client_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Code Compte</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['account_code']) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Numéro Fiscal</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract[''] ?? 'Non spécifié') ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Client Destinataire</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['recipient_name'] ?? $contract['client_name']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Contact Destinataire</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['recipient_contact'] ?? 'Non spécifié') ?>" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contract Information -->
                            <div class="form-section">
                                <h6><i class="fas fa-file-contract me-2"></i>Détails du Contrat</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Type de Transaction</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst($contract['transaction_type'])) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Date d'Expédition</label>
                                        <input type="text" class="form-control" value="<?= date('d/m/Y', strtotime($contract['shipment_date'])) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Mode de Paiement</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['payment_mode']) ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Gare d'Origine</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?= htmlspecialchars($contract['origin_code'] ?? '') ?></span>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($contract['origin']) ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Gare de Destination</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?= htmlspecialchars($contract['destination_code'] ?? '') ?></span>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($contract['destination']) ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Merchandise Information -->
                            <div class="form-section">
                                <h6><i class="fas fa-box me-2"></i>Détails Marchandise</h6>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Marchandise</label>
                                        <div class="input-group">
                                            <?php if ($contract['merchandise_code']): ?>
                                                <span class="input-group-text"><?= htmlspecialchars($contract['merchandise_code']) ?></span>
                                            <?php endif; ?>
                                            <input type="text" class="form-control" 
                                                value="<?= htmlspecialchars($contract['merchandise_type'] ?? $contract['request_description'] ?? '') ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Poids Initial (kg)</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['shipment_weight']) ?>" readonly>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Nombre de Wagons Initial</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['wagon_count']) ?>" readonly>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Prix Proposé (€)</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($contract['total_port_due']) ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Form to Complete Contract -->
                        <form method="POST">
                            <!-- Shipping Details -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-truck me-2"></i>Détails d'Expédition (À Compléter)</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Poids Final de l'Expédition (kg) <span class="text-danger">*</span></label>
                                        <input type="number" name="shipment_weight" class="form-control" 
                                            value="<?= htmlspecialchars($contract['shipment_weight']) ?>" >
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Nombre Final de Wagons <span class="text-danger">*</span></label>
                                        <input type="number" name="wagon_count" class="form-control" 
                                            value="<?= htmlspecialchars($contract['wagon_count']) ?>" min="1" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Nombre de Bâches <span class="text-danger">*</span></label>
                                        <input type="number" name="tarp_count" class="form-control" 
                                            value="<?= htmlspecialchars($contract['tarp_count'] ?? '') ?>" min="0" required>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Nombre Total d'Unités <span class="text-danger">*</span></label>
                                        <input type="number" name="total_units" class="form-control" 
                                            value="<?= htmlspecialchars($contract['total_units'] ?? '') ?>" min="1" required>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Accessoires</label>
                                        <input type="text" name="accessories" class="form-control" 
                                            value="<?= htmlspecialchars($contract['accessories'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Financial Details -->
                            <div class="form-section">
                                <h5 class="mb-3"><i class="fas fa-money-bill-wave me-2"></i>Détails Financiers (À Compléter)</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Frais (€)</label>
                                        <input type="number" name="expenses" class="form-control" 
                                            value="<?= htmlspecialchars($contract['expenses'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Remboursement (€)</label>
                                        <input type="number" name="reimbursement" class="form-control" 
                                            value="<?= htmlspecialchars($contract['reimbursement'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Port Payé (€)</label>
                                        <input type="number" name="paid_port" class="form-control" 
                                            value="<?= htmlspecialchars($contract['paid_port'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Port Dû Total (€)</label>
                                        <input type="number" name="total_port_due" class="form-control" 
                                            value="<?= htmlspecialchars($contract['total_port_due'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Part SNCF (€)</label>
                                        <input type="number" name="part_sncf" class="form-control" 
                                            value="<?= htmlspecialchars($contract['part_sncf'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Part ONCF (€)</label>
                                        <input type="number" name="part_oncf" class="form-control" 
                                            value="<?= htmlspecialchars($contract['part_oncf'] ?? '') ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Allocation Analytique</label>
                                        <input type="text" name="analytical_allocation" class="form-control" 
                                            value="<?= htmlspecialchars($contract['analytical_allocation'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
    <label class="form-label">Client Signature</label>
    <div id="signature-pad" style="border:1px solid #ddd; height:150px; background:#fff">
        <!-- Signature pad will render here -->
    </div>
    <button type="button" id="clear-signature" class="btn btn-sm btn-outline-secondary mt-2">
        <i class="fas fa-eraser"></i> Clear
    </button>
    <input type="hidden" name="signature_data" id="signature-data">
</div>
                            </div>
                            
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-check-circle me-2"></i> Finaliser le Contrat
                                </button>
                                <a href="agent-dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Annuler
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const required = ['shipment_weight', 'wagon_count', 'tarp_count', 'total_units'];
            let valid = true;
            
            required.forEach(field => {
                const input = document.querySelector(`[name="${field}"]`);
                if (!input.value) {
                    alert(`Veuillez remplir le champ ${input.previousElementSibling.textContent.trim()}`);
                    input.focus();
                    valid = false;
                    e.preventDefault();
                    return false;
                }
            });
            
            return valid;
        });
    </script>
    <script>
    const canvas = document.getElementById("signature-pad");
    const signaturePad = new SignaturePad(canvas);
    
    document.getElementById("clear-signature").addEventListener("click", () => {
        signaturePad.clear();
    });
    
    document.querySelector("form").addEventListener("submit", () => {
        document.getElementById("signature-data").value = signaturePad.isEmpty() 
            ? "" 
            : signaturePad.toDataURL();
    });
</script>
</body>
</html>