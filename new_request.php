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
        
        /* Enhanced Notification dropdown styles */
        .dropdown-notifications {
          min-width: 350px;
          padding: 0;
          max-height: 450px;
          overflow-y: auto;
          box-shadow: 0 5px 15px rgba(0,0,0,0.1);
          border: none;
          border-radius: 8px;
        }
        .dropdown-notifications .dropdown-header {
          background-color: #1a3c8f;
          color: white;
          padding: 12px 15px;
          font-weight: 600;
          border-top-left-radius: 8px;
          border-top-right-radius: 8px;
        }
        .dropdown-notifications .dropdown-footer {
          background-color: #f8f9fa;
          padding: 10px;
          text-align: center;
          border-top: 1px solid #e9ecef;
          border-bottom-left-radius: 8px;
          border-bottom-right-radius: 8px;
        }
        .notification-badge {
          position: absolute;
          top: -8px;
          right: -8px;
          font-size: 0.7rem;
          padding: 3px 6px;
          box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .navbar-notification-icon {
          font-size: 1.3rem;
          position: relative;
        }
        .dropdown-item.notification-item {
          padding: 12px 15px;
          border-bottom: 1px solid #f1f1f1;
          transition: all 0.2s ease;
        }
        .dropdown-item.notification-item:hover {
          background-color: #f5f9ff;
        }
        .dropdown-item.notification-item.unread {
          background-color: #f0f7ff;
          border-left: 4px solid #0d6efd;
        }
        .dropdown-item.notification-item strong {
          color: #1a3c8f;
          display: block;
          margin-bottom: 3px;
        }
        .dropdown-notifications .btn-link {
          color: #1a3c8f;
          font-weight: 500;
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
                <!-- Notification Dropdown -->
                <div class="dropdown me-3">
                    <?php
                    // Get unread notifications count
                    $notifCountStmt = $pdo->prepare("SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND is_read = FALSE");
                    $notifCountStmt->execute([$_SESSION['user']['id']]);
                    $notifCount = $notifCountStmt->fetch()['count'];
                    
                    // Get recent notifications
                    $notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                    $notifStmt->execute([$_SESSION['user']['id']]);
                    $notifications = $notifStmt->fetchAll();
                    ?>
                    <button class="btn btn-primary position-relative" type="button" id="dropdownNotification" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="navbar-notification-icon">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">
                                    <?= $notifCount > 9 ? '9+' : $notifCount ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end dropdown-notifications" aria-labelledby="dropdownNotification">
                        <div class="dropdown-header">
                            <i class="fas fa-bell me-2"></i>Notifications
                        </div>
                        
                        <?php if (empty($notifications)): ?>
                            <div class="p-4 text-center">
                                <i class="fas fa-bell-slash fa-2x text-muted mb-3"></i>
                                <p class="mb-0 text-muted">Aucune nouvelle notification</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <?php 
                                    // Parse metadata if it exists
                                    $metadata = json_decode($n['metadata'] ?? '{}', true);
                                    
                                    // Determine the link based on notification type
                                    $link = '#';
                                    if ($n['type'] === 'contract_completed' && isset($metadata['contract_id'])) {
                                        $link = "client-contract-details.php?id=" . $metadata['contract_id'];
                                    } elseif ($n['related_request_id']) {
                                        $link = "request_details.php?id=" . $n['related_request_id'];
                                    }
                                    
                                    $cls = $n['is_read'] ? '' : 'unread';
                                    
                                    // Determine icon based on notification type
                                    $icon = 'fa-bell';
                                    if (strpos($n['type'], 'contract') !== false) {
                                        $icon = 'fa-file-contract';
                                    } elseif (strpos($n['type'], 'payment') !== false) {
                                        $icon = 'fa-money-bill';
                                    } elseif (strpos($n['type'], 'shipment') !== false || strpos($n['type'], 'arrivage') !== false) {
                                        $icon = 'fa-truck';
                                    }
                                ?>
                                <a href="<?= htmlspecialchars($link) ?>" class="dropdown-item notification-item <?= $cls ?>" data-id="<?= $n['id'] ?>">
                                    <div class="d-flex">
                                        <div class="me-3 pt-1">
                                            <i class="fas <?= $icon ?> text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between">
                                                <strong><?= htmlspecialchars($n['title']) ?></strong>
                                                <small class="text-muted ms-2"><?= time_elapsed_string($n['created_at']) ?></small>
                                            </div>
                                            <small class="text-muted"><?= htmlspecialchars($n['message']) ?></small>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div class="dropdown-footer">
                            <a href="notifications.php" class="btn btn-sm btn-link">Voir toutes les notifications</a>
                        </div>
                    </div>
                </div>
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
        // Helper function to display relative time
        function time_elapsed_string(datetime, full = false) {
            const now = new Date();
            const ago = new Date(datetime);
            const diff = Math.floor((now - ago) / 1000);
            
            const intervals = {
                year: 31536000,
                month: 2592000,
                week: 604800,
                day: 86400,
                hour: 3600,
                minute: 60,
                second: 1
            };
            
            const names = {
                year: ['an', 'ans'],
                month: ['mois', 'mois'],
                week: ['semaine', 'semaines'],
                day: ['jour', 'jours'],
                hour: ['heure', 'heures'],
                minute: ['minute', 'minutes'],
                second: ['seconde', 'secondes']
            };
            
            for (const [unit, seconds] of Object.entries(intervals)) {
                const count = Math.floor(diff / seconds);
                if (count >= 1) {
                    const name = count > 1 && unit !== 'month' ? names[unit][1] : names[unit][0];
                    return `Il y a ${count} ${name}`;
                }
            }
            
            return "À l'instant";
        }
        
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
            
            // Mark notification as read on click
            document.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', function() {
                    if (!this.classList.contains('unread')) return;
                    this.classList.remove('unread');
                    fetch('mark_notification_read.php?id=' + this.dataset.id, { method: 'POST' });
                });
            });
        });
    </script>
</body>
</html>