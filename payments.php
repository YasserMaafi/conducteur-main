<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

// Get agent data
$stmt = $pdo->prepare("SELECT a.*, u.email FROM agents a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();
// Get draft notifications
$draftNotifications = getDraftNotifications($pdo, $_SESSION['user']['id']);
$draftNotificationCount = count($draftNotifications);
// Handle payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    try {
        $contract_id = filter_input(INPUT_POST, 'contract_id', FILTER_VALIDATE_INT);
        $payment_method = $_POST['payment_method'];
        $payment_reference = $_POST['payment_reference'] ?? null;
        $payment_date = $_POST['payment_date'];
        $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $notes = $_POST['notes'] ?? '';

        if (!$contract_id || !$amount) {
            throw new Exception("Données de paiement invalides");
        }

        $pdo->beginTransaction();

        // Update contract payment status
        $stmt = $pdo->prepare("
            UPDATE contracts 
            SET payment_status = 'fully_paid', 
                updated_at = NOW() 
            WHERE contract_id = ? AND agent_id = ?
        ");
        $stmt->execute([$contract_id, $agent['agent_id']]);

        // Insert payment record
        $stmt = $pdo->prepare("
        INSERT INTO payments (
            contract_id, 
            client_id, 
            amount, 
            currency, 
            payment_method, 
            payment_date, 
            status, 
            reference_number,
            notes
        )
        SELECT 
            c.contract_id,
            u.user_id,  -- Get user_id from clients->users relationship
            ?,
            'EUR',
            ?,
            ?,
            'completed',
            ?,
            ?
        FROM contracts c
        JOIN clients cl ON c.sender_client = cl.client_id
        JOIN users u ON cl.user_id = u.user_id
        WHERE c.contract_id = ?
    ");
        $stmt->execute([$amount, $payment_method, $payment_date, $payment_reference, $notes, $contract_id]);

        // Get client user_id for notification
        $stmt = $pdo->prepare("
            SELECT u.user_id, c.company_name
            FROM clients c
            JOIN users u ON c.user_id = u.user_id
            WHERE c.client_id = (
                SELECT sender_client 
                FROM contracts 
                WHERE contract_id = ?
            )
        ");
        $stmt->execute([$contract_id]);
        $client = $stmt->fetch();

        if ($client) {
            // Create notification for client
            $notificationData = [
                'contract_id' => $contract_id,
                'payment_method' => $payment_method,
                'payment_reference' => $payment_reference,
                'payment_date' => $payment_date,
                'amount' => $amount
            ];

            createNotification(
                $client['user_id'],
                'confirmation_paiement',
                'Paiement Confirmé',
                "Le paiement pour le contrat #$contract_id a été confirmé par l'agent. Méthode: $payment_method, Référence: $payment_reference",
                $contract_id,
                $notificationData
            );
        }

        $pdo->commit();
        $_SESSION['success'] = "Paiement confirmé avec succès";
        redirect('payments.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
}

// Get filter parameters
$payment_status = $_GET['payment_status'] ?? 'all';
$client = $_GET['client'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "
    SELECT c.*, 
           cl.company_name AS client_name, cl.account_code,
           g1.libelle AS origin, g1.code_gare AS origin_code,
           g2.libelle AS destination, g2.code_gare AS destination_code,
           p.payment_date, p.payment_method, p.reference_number
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN payments p ON c.contract_id = p.contract_id
    WHERE c.agent_id = ?
";

$params = [$agent['agent_id']];

if ($payment_status !== 'all') {
    $query .= " AND c.payment_status = ?";
    $params[] = $payment_status;
}

if ($client) {
    $query .= " AND (cl.company_name LIKE ? OR cl.account_code LIKE ?)";
    $params[] = "%$client%";
    $params[] = "%$client%";
}

if ($date_from) {
    $query .= " AND c.created_at >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND c.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$query .= " ORDER BY c.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$contracts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Paiements | SNCFT Agent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a3c8f;
            --secondary-color: #f8f9fa;
            --accent-color: #3459c0;
            --success-color: #198754;
            --info-color: #0dcaf0;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-brand {
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        
        .bg-primary {
            background-color: var(--primary-color) !important;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .sidebar {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .sidebar .nav-link {
            color: #495057;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover, 
        .sidebar .nav-link.active {
            color: var(--primary-color);
            background-color: rgba(26, 60, 143, 0.1);
            font-weight: 500;
        }
        
        .sidebar .nav-link i {
            width: 24px;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        
        .badge {
            padding: 0.5em 0.75em;
        }
        
        .payment-status {
            font-size: 0.75rem;
            padding: 0.5em 0.75em;
            border-radius: 50rem;
        }
        
        .payment-status.pending {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }
        
        .payment-status.paid {
            background-color: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }
        
        .payment-status.overdue {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }
        
        .filters {
            background-color: white;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .modal-header {
            background-color: var(--primary-color);
            color: white;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand" href="agent-dashboard.php">
                <i class="fas fa-train me-2"></i>SNCFT Agent
            </a>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-sm-inline">
                    <i class="fas fa-id-badge me-1"></i> <?= htmlspecialchars($agent['badge_number']) ?>
                </span>
                <!-- Notification Dropdown -->
<div class="dropdown me-3">
    <button class="btn btn-outline-light dropdown-toggle position-relative" 
            type="button" 
            id="notificationDropdown" 
            data-bs-toggle="dropdown" 
            aria-expanded="false">
        <i class="fas fa-bell"></i>
        <?php if ($draftNotificationCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                <?= $draftNotificationCount ?>
            </span>
        <?php endif; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown">
        <?php if ($draftNotificationCount > 0): ?>
            <li><h6 class="dropdown-header">Contrats en brouillon</h6></li>
            <?php foreach ($draftNotifications as $notification): 
                $metadata = json_decode($notification['metadata'], true);
                $contract_id = $metadata['contract_id'] ?? 0;
            ?>
                <li>
                    <a class="dropdown-item" href="complete_contract.php?id=<?= $contract_id ?>">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold"><?= htmlspecialchars($notification['title']) ?></span>
                            <small class="text-muted ms-2"><?= date('H:i', strtotime($notification['created_at'])) ?></small>
                        </div>
                        <small class="text-muted"><?= htmlspecialchars($notification['message']) ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
        <?php else: ?>
            <li><a class="dropdown-item" href="#"><i class="fas fa-check-circle me-2 text-muted"></i>Aucun nouveau brouillon</a></li>
        <?php endif; ?>
        <li><a class="dropdown-item text-center small" href="agent-contracts.php?status=draft">
            Voir tous les brouillons
        </a></li>
    </ul>
</div>
                <div class="dropdown">
                    <button class="btn btn-outline-light dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profil</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Paramètres</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-3">
                <div class="sidebar p-3 shadow-sm">
                    <div class="text-center mb-4 p-3">
                        <div class="avatar bg-primary text-white rounded-circle mb-3 mx-auto" style="width: 80px; height: 80px;">
                            <i class="fas fa-user-tie fa-2x"></i>
                        </div>
                        <h5 class="mb-1"><?= htmlspecialchars($_SESSION['user']['username']) ?></h5>
                        <span class="badge bg-primary">Agent</span>
                        <div class="text-muted mt-2 small">
                            <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($agent['email']) ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="agent-dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i> Tableau de bord
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="pending-contracts.php">
                                <i class="fas fa-clock me-2"></i> Contrats en attente
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="agent-contracts.php">
                                <i class="fas fa-clipboard-list me-2"></i> Tous les contrats
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="agent-expeditions.php">
                                <i class="fas fa-truck-loading me-2"></i> Expéditions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="payments.php">
                                <i class="fas fa-money-bill-wave me-2"></i> Paiements
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="col-lg-9">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="mb-1">Gestion des Paiements</h4>
                        <p class="text-muted mb-0">Suivez et confirmez les paiements des contrats</p>
                    </div>
                    <a href="agent-dashboard.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left me-2"></i>Retour au tableau de bord
                    </a>
                </div>

                <!-- Alerts -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <!-- Filters -->
                <div class="filters mb-4">
                    <form class="row g-3" method="GET">
                        <div class="col-md-3">
                            <label class="form-label">Statut de paiement</label>
                            <select name="payment_status" class="form-select">
                                <option value="all" <?= $payment_status === 'all' ? 'selected' : '' ?>>Tous les statuts</option>
                                <option value="pending" <?= $payment_status === 'pending' ? 'selected' : '' ?>>En attente</option>
                                <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>Payé</option>
                                <option value="overdue" <?= $payment_status === 'overdue' ? 'selected' : '' ?>>En retard</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Client</label>
                            <input type="text" name="client" class="form-control" placeholder="Rechercher par client..." value="<?= htmlspecialchars($client) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date de début</label>
                            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date de fin</label>
                            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-2"></i>Filtrer
                            </button>
                            <a href="payments.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Réinitialiser
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Contracts Table -->
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <?php if (empty($contracts)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                <h5>Aucun contrat trouvé</h5>
                                <p class="text-muted">Aucun contrat ne correspond à vos critères de recherche</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Client</th>
                                            <th>Montant</th>
                                            <th>Statut</th>
                                            <th>Date de paiement</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($contracts as $contract): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-light text-dark">CT-<?= $contract['contract_id'] ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= htmlspecialchars($contract['client_name']) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($contract['account_code']) ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?= number_format($contract['total_port_due'], 2) ?> €</div>
                                                    <small class="text-muted">Port dû</small>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    $statusText = '';
                                                    switch ($contract['payment_status']) {
                                                        case 'pending':
                                                            $statusClass = 'pending';
                                                            $statusText = 'En attente';
                                                            break;
                                                        case 'paid':
                                                            $statusClass = 'paid';
                                                            $statusText = 'Payé';
                                                            break;
                                                        case 'overdue':
                                                            $statusClass = 'overdue';
                                                            $statusText = 'En retard';
                                                            break;
                                                        default:
                                                            $statusClass = 'pending';
                                                            $statusText = 'En attente';
                                                    }
                                                    ?>
                                                    <span class="payment-status <?= $statusClass ?>">
                                                        <i class="fas fa-circle me-1"></i><?= $statusText ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($contract['payment_date']): ?>
                                                        <?= date('d/m/Y', strtotime($contract['payment_date'])) ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= htmlspecialchars($contract['payment_method']) ?>
                                                            <?php if ($contract['reference_number']): ?>
                                                                <br>Ref: <?= htmlspecialchars($contract['reference_number']) ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($contract['payment_status'] !== 'paid'): ?>
                                                        <button type="button" 
                                                                class="btn btn-primary btn-sm"
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#confirmPaymentModal"
                                                                data-contract-id="<?= $contract['contract_id'] ?>"
                                                                data-amount="<?= $contract['total_port_due'] ?>"
                                                                data-client="<?= htmlspecialchars($contract['client_name']) ?>">
                                                            <i class="fas fa-check-circle me-1"></i>Confirmer
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="agent-payment-details.php?id=<?= $contract['contract_id'] ?>" 
                                                       class="btn btn-outline-secondary btn-sm">
                                                       <i class="fas fa-eye"></i> Voir
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
            </div>
        </div>
    </div>

    <!-- Confirm Payment Modal -->
    <div class="modal fade" id="confirmPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Confirmer le Paiement
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="contract_id" id="contract_id">
                        <input type="hidden" name="confirm_payment" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Client</label>
                            <input type="text" class="form-control" id="client_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Montant <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" id="amount" step="0.01" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Méthode de paiement <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-select" required>
                                <option value="">Sélectionner...</option>
                                <option value="cash">Espèces</option>
                                <option value="bank_transfer">Virement bancaire</option>
                                <option value="check">Chèque</option>
                                <option value="credit_card">Carte bancaire</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Référence de paiement</label>
                            <input type="text" name="payment_reference" class="form-control">
                            <small class="text-muted">Numéro de chèque, référence de virement, etc. (optionnel)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date de paiement <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Annuler
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle me-2"></i>Confirmer le paiement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-white py-4 mt-5 border-top">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0 text-muted">&copy; <?= date('Y') ?> SNCFT - Société Nationale des Chemins de Fer Tunisiens</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0 text-muted">Système de gestion des contrats de fret</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Handle payment confirmation modal
        const confirmPaymentModal = document.getElementById('confirmPaymentModal');
        if (confirmPaymentModal) {
            confirmPaymentModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const contractId = button.getAttribute('data-contract-id');
                const amount = button.getAttribute('data-amount');
                const client = button.getAttribute('data-client');
                
                confirmPaymentModal.querySelector('#contract_id').value = contractId;
                confirmPaymentModal.querySelector('#amount').value = amount;
                confirmPaymentModal.querySelector('#client_name').value = client;
                
                // Set default payment date to today
                const today = new Date().toISOString().split('T')[0];
                confirmPaymentModal.querySelector('input[name="payment_date"]').value = today;
            });
        }
    </script>
</body>
</html> 