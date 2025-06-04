<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify client role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
    redirect('index.php');
}

// Initialize variables
$error = '';
$sender_client = []; // Will hold only the logged-in client's information
$gares = [];
$merchandise_types = [];

try {
    // Get the logged-in client's information only (SENDER)
    $stmt = $pdo->prepare("
        SELECT c.client_id, c.company_name, c.phone_number, c.account_code, u.email     
        FROM clients c 
        JOIN users u ON c.user_id = u.user_id 
        WHERE u.user_id = ? AND u.is_active = true
    ");
    $stmt->execute([$_SESSION['user']['id']]);
    $sender_client = $stmt->fetch();
    
    // If no client record is found for this user
    if (!$sender_client) {
        throw new Exception("Votre compte client n'a pas été trouvé ou est inactif");
    }

    // Get stations list
    $stmt = $pdo->query("SELECT id_gare, libelle FROM gares ORDER BY libelle");
    $gares = $stmt->fetchAll();

    // Get merchandise types from new merchandise table
    $stmt = $pdo->query("
        SELECT merchandise_id, code, description, hazardous 
        FROM merchandise 
        ORDER BY description
    ");
    $merchandise_types = $stmt->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    try {
        $pdo->beginTransaction();
        
        // Validate inputs
        $required = [
            'recipient_name', 'recipient_contact',
            'gare_depart', 'gare_arrivee', 'merchandise_id', 
            'date_start', 'mode_paiement'
        ];
        
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Le champ " . str_replace('_', ' ', $field) . " est requis");
            }
        }
        
        // Validate quantity and unit
        $quantity = $_POST['quantity'] ?? null;
        $unit = $_POST['quantity_unit'] ?? '';
        
        if (empty($quantity) || !in_array($unit, ['kg', 'wagons'])) {
            throw new Exception("Veuillez spécifier la quantité et l'unité (KG ou Wagons).");
        }
        
        if (!is_numeric($quantity) || $quantity <= 0) {
            throw new Exception("La quantité doit être un nombre positif.");
        }
        
        if ($unit === 'wagons' && !is_numeric($quantity) || (int)$quantity != $quantity) {
            throw new Exception("Le nombre de wagons doit être un entier.");
        }

        // Validate merchandise exists
        $stmt = $pdo->prepare("SELECT 1 FROM merchandise WHERE merchandise_id = ?");
        $stmt->execute([$_POST['merchandise_id']]);
        if (!$stmt->fetch()) {
            throw new Exception("Type de marchandise invalide");
        }

        // Validate contact info (phone or email)
        $recipient_contact = $_POST['recipient_contact'];
        if (!filter_var($recipient_contact, FILTER_VALIDATE_EMAIL) && !preg_match('/^[0-9]{8,15}$/', $recipient_contact)) {
            throw new Exception("Le contact doit être un email valide ou un numéro de téléphone");
        }

        // Validate payment mode
        $valid_payment_modes = ['cash', 'check', 'transfer', 'account'];
        if (!in_array($_POST['mode_paiement'], $valid_payment_modes)) {
            throw new Exception("Mode de paiement invalide");
        }

        // Validate account code if compte courant selected
        if ($_POST['mode_paiement'] === 'account') {
            if (empty($_POST['account_code'])) {
                throw new Exception("Le code de compte est requis pour le paiement par compte courant");
            }
        }


        // Get merchandise details for notification
        $stmt = $pdo->prepare("SELECT description, hazardous FROM merchandise WHERE merchandise_id = ?");
        $stmt->execute([$_POST['merchandise_id']]);
        $merchandise = $stmt->fetch();

        // Determine values for quantity and wagon_count based on unit
        $quantityValue = ($unit === 'kg') ? (float)$quantity : null;
        $wagonCountValue = ($unit === 'wagons') ? (int)$quantity : null;

        // Insert freight request - Set quantity or wagon_count based on unit
        $stmt = $pdo->prepare("
            INSERT INTO freight_requests (
                sender_client_id, recipient_name, recipient_contact,
                gare_depart, gare_arrivee, merchandise_id,
                quantity, quantity_unit, date_start, mode_paiement, account_code, status,
                wagon_count
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");

        $stmt->execute([
            $sender_client['client_id'],
            $_POST['recipient_name'],
            $_POST['recipient_contact'],
            $_POST['gare_depart'],
            $_POST['gare_arrivee'],
            $_POST['merchandise_id'],
            $quantityValue,
            $unit,
            $_POST['date_start'],
            $_POST['mode_paiement'],
            ($_POST['mode_paiement'] === 'account' ? $sender_client['account_code'] : null),
            $wagonCountValue
        ]);
        
        $request_id = $pdo->lastInsertId();
        
        // Create notification for admins
        $stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, related_request_id, metadata
            ) 
            SELECT user_id, 'nouvelle_demande', 'Nouvelle demande de fret', ?, ?, ?
            FROM users WHERE role = 'admin'
        ");
        
        $origin = '';
        $destination = '';
        foreach ($gares as $gare) {
            if ($gare['id_gare'] == $_POST['gare_depart']) $origin = $gare['libelle'];
            if ($gare['id_gare'] == $_POST['gare_arrivee']) $destination = $gare['libelle'];
        }
        
        $metadata = [
            'sender' => $sender_client['company_name'],
            'sender_id' => $sender_client['client_id'],
            'recipient' => $_POST['recipient_name'],
            'origin' => $origin,
            'destination' => $destination,
            'date' => $_POST['date_start'],
            'quantity' => $_POST['quantity'],
            'merchandise' => $merchandise['description'],
            'hazardous' => $merchandise['hazardous']
        ];
        
        $stmt->execute([
            "Nouvelle demande #$request_id de " . $sender_client['company_name'] . " pour " . $_POST['recipient_name'],
            $request_id,
            json_encode($metadata)
        ]);
        
        $pdo->commit();
        $_SESSION['success'] = "Demande soumise avec succès (N° $request_id)";
        redirect('client-dashboard.php');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Demande de Fret | SNCFT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .form-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        .account-code-field {
            display: none;
        }
        .hazardous-item {
            color: #dc3545;
            font-weight: bold;
        }
        .sidebar {
            position: sticky;
            top: 20px;
        }
        .notification-badge {
            font-size: 0.6rem;
        }
    </style>
</head>
<body class="bg-light">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show m-2">
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-train me-2"></i>SNCFT Client
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="fas fa-building me-1"></i><?= htmlspecialchars($sender_client['company_name'] ?? '') ?>
                </span>
                <a href="logout.php" class="btn btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> 
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <!-- SIDEBAR -->
            <div class="col-lg-3 mb-4">
                <div class="card shadow-sm mb-4 sidebar">
                    <div class="card-body text-center">
                        <div class="avatar bg-primary text-white rounded-circle p-3 mx-auto" style="width:80px;height:80px">
                            <i class="fas fa-building fa-2x"></i>
                        </div>
                        <h5 class="mt-3"><?= htmlspecialchars($sender_client['company_name'] ?? '') ?></h5>
                        <small class="text-muted">Client</small>
                    </div>
                </div>

                <div class="list-group mb-4">
                    <a href="client-dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-tachometer-alt me-2"></i>Tableau de bord
                    </a>
                    <a href="new_request.php" class="list-group-item list-group-item-action active">
                        <i class="fas fa-plus-circle me-2"></i>Nouvelle demande
                    </a>
                    <a href="interface.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-truck me-2"></i>Mes expéditions
                    </a>
                    <a href="client-payments.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-money-bill-wave me-2"></i>Paiements
                    </a>
                    <a href="client-contracts.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-contract me-2"></i>Contrats
                    </a>
                    <a href="notifications.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-bell me-2"></i>Notifications
                    </a>
                    <a href="profile.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-user-cog me-2"></i>Profil
                    </a>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-file-alt me-2"></i>Nouvelle Demande de Fret
                            </h4>
                            <a href="client-dashboard.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Retour
                            </a>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <form method="POST" class="needs-validation" novalidate>
                            <!-- Sender Information (Read-only) -->
                            <div class="form-section">
                                <h5><i class="fas fa-paper-plane me-2"></i>Expéditeur (Vous)</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Société</label>
                                        <div class="form-control bg-light">
                                            <?= htmlspecialchars($sender_client['company_name'] ?? '') ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Contact</label>
                                        <div class="form-control bg-light">
                                            <?= htmlspecialchars($sender_client['phone_number'] ?? $sender_client['email'] ?? '') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recipient Information Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-user-tie me-2"></i>Informations Destinataire</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Nom du Destinataire</label>
                                        <input type="text" name="recipient_name" class="form-control" 
                                            value="<?= htmlspecialchars($_POST['recipient_name'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Contact du Destinataire</label>
                                        <input type="text" name="recipient_contact" class="form-control" 
                                            value="<?= htmlspecialchars($_POST['recipient_contact'] ?? '') ?>" 
                                            placeholder="Numéro de téléphone ou email" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Shipment Details Section -->
                            <div class="form-section">
                                <h5><i class="fas fa-truck me-2"></i>Détails de l'Expédition</h5>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Gare de Départ</label>
                                        <select name="gare_depart" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($gares as $gare): ?>
                                                <option value="<?= $gare['id_gare'] ?>" 
                                                    <?= isset($_POST['gare_depart']) && $_POST['gare_depart'] == $gare['id_gare'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($gare['libelle']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Gare d'Arrivée</label>
                                        <select name="gare_arrivee" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($gares as $gare): ?>
                                                <option value="<?= $gare['id_gare'] ?>" 
                                                    <?= isset($_POST['gare_arrivee']) && $_POST['gare_arrivee'] == $gare['id_gare'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($gare['libelle']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label required-field">Type de Marchandise</label>
                                        <select name="merchandise_id" class="form-select" required id="merchandiseSelect">
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($merchandise_types as $type): ?>
                                                <option 
                                                    value="<?= $type['merchandise_id'] ?>"
                                                    data-hazardous="<?= $type['hazardous'] ? 'true' : 'false' ?>"
                                                    <?= isset($_POST['merchandise_id']) && $_POST['merchandise_id'] == $type['merchandise_id'] ? 'selected' : '' ?>
                                                    class="<?= $type['hazardous'] ? 'hazardous-item' : '' ?>"
                                                >
                                                    <?= htmlspecialchars($type['description']) ?>
                                                    <?= $type['hazardous'] ? ' (☢ Matière dangereuse)' : '' ?>
                                                    [<?= htmlspecialchars($type['code']) ?>]
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="hazardousWarning" class="alert alert-danger mt-2" style="display: none;">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            Attention: Cette marchandise est classée comme dangereuse. Des conditions spéciales de transport s'appliquent.
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Quantité</label>   
                                        <div class="input-group">
                                            <input type="number" name="quantity" id="quantity" class="form-control" 
                                                value="<?= $_POST['quantity'] ?? '' ?>" step="0.01" min="0.01">
                                            <select name="quantity_unit" id="quantity_unit" class="form-select" required>
                                                <option value="">-- Unité --</option>
                                                <option value="kg" <?= (($_POST['quantity_unit'] ?? '') === 'kg') ? 'selected' : '' ?>>Kilogrammes (KG)</option>
                                                <option value="wagons" <?= (($_POST['quantity_unit'] ?? '') === 'wagons') ? 'selected' : '' ?>>Nombre de Wagons</option>
                                            </select>
                                        </div>
                                        <small class="form-text text-muted">Entrez soit le poids total en kg ou le nombre de wagons.</small>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Date de Départ Souhaitée</label>
                                        <input type="date" name="date_start" class="form-control" 
                                            value="<?= $_POST['date_start'] ?? '' ?>" min="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Mode de Paiement</label>
                                        <select name="mode_paiement" id="paymentMode" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <option value="cash" <?= isset($_POST['mode_paiement']) && $_POST['mode_paiement'] == 'cash' ? 'selected' : '' ?>>Espèces</option>
                                            <option value="check" <?= isset($_POST['mode_paiement']) && $_POST['mode_paiement'] == 'check' ? 'selected' : '' ?>>Chèque</option>
                                            <option value="transfer" <?= isset($_POST['mode_paiement']) && $_POST['mode_paiement'] == 'transfer' ? 'selected' : '' ?>>Virement Bancaire</option>
                                            <option value="account" <?= isset($_POST['mode_paiement']) && $_POST['mode_paiement'] == 'account' ? 'selected' : '' ?>>Compte Courant</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 account-code-field" id="accountCodeField">
                                        <label class="form-label required-field">Code de Compte</label>
                                        <input type="text" name="account_code" class="form-control" 
                                            value="<?= htmlspecialchars($_POST['account_code'] ?? $sender_client['account_code'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="d-flex justify-content-end gap-3 mt-4">
                                <button type="reset" class="btn btn-outline-secondary px-4">
                                    <i class="fas fa-eraser me-1"></i> Annuler
                                </button>
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="fas fa-paper-plane me-1"></i> Soumettre
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap & JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            const forms = document.querySelectorAll('.needs-validation')
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
        
        // Payment mode account code field toggle
        document.getElementById('paymentMode').addEventListener('change', function() {
            const accountField = document.getElementById('accountCodeField');
            const isAccount = this.value === 'account';
            
            accountField.style.display = isAccount ? 'block' : 'none';
            accountField.querySelector('input').required = isAccount;
            
            if (!isAccount) {
                accountField.querySelector('input').value = '';
            }
        });
        
        // Hazardous material warning
        document.getElementById('merchandiseSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isHazardous = selectedOption.getAttribute('data-hazardous') === 'true';
            document.getElementById('hazardousWarning').style.display = isHazardous ? 'block' : 'none';
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Payment mode
            const paymentMode = document.getElementById('paymentMode');
            if (paymentMode.value === 'account') {
                document.getElementById('accountCodeField').style.display = 'block';
            }
            
            // Hazardous warning
            const merchandiseSelect = document.getElementById('merchandiseSelect');
            if (merchandiseSelect.value) {
                const selectedOption = merchandiseSelect.options[merchandiseSelect.selectedIndex];
                const isHazardous = selectedOption.getAttribute('data-hazardous') === 'true';
                document.getElementById('hazardousWarning').style.display = isHazardous ? 'block' : 'none';
            }
        });
    </script>
</body>
</html>