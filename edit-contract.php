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
           cl.company_name AS client_name, cl.account_code,
           g1.libelle AS origin_station, g1.code_gare AS origin_code,
           g2.libelle AS destination_station, g2.code_gare AS destination_code,
           fr.merchandise_id, fr.recipient_name, fr.recipient_contact,
           m.description AS merchandise_type, m.code AS merchandise_code
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
    try {
        // Validate and sanitize input
        $shipment_date = filter_input(INPUT_POST, 'shipment_date', FILTER_SANITIZE_STRING);
        $shipment_weight = filter_input(INPUT_POST, 'shipment_weight', FILTER_VALIDATE_FLOAT);
        $wagon_count = filter_input(INPUT_POST, 'wagon_count', FILTER_VALIDATE_INT);
        $tarp_count = filter_input(INPUT_POST, 'tarp_count', FILTER_VALIDATE_INT);
        $total_units = filter_input(INPUT_POST, 'total_units', FILTER_VALIDATE_INT);
        $gare_expeditrice = filter_input(INPUT_POST, 'gare_expéditrice', FILTER_VALIDATE_INT);
        $gare_destinataire = filter_input(INPUT_POST, 'gare_destinataire', FILTER_VALIDATE_INT);
        $merchandise_id = filter_input(INPUT_POST, 'merchandise_id', FILTER_VALIDATE_INT);
        $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
        $reported_problems = filter_input(INPUT_POST, 'reported_problems', FILTER_SANITIZE_STRING);
        
        // Financial details
        $expenses = filter_input(INPUT_POST, 'expenses', FILTER_VALIDATE_FLOAT);
        $reimbursement = filter_input(INPUT_POST, 'reimbursement', FILTER_VALIDATE_FLOAT);
        $paid_port = filter_input(INPUT_POST, 'paid_port', FILTER_VALIDATE_FLOAT);
        $total_port_due = filter_input(INPUT_POST, 'total_port_due', FILTER_VALIDATE_FLOAT);
        $part_sncf = filter_input(INPUT_POST, 'part_sncf', FILTER_VALIDATE_FLOAT);
        $part_oncf = filter_input(INPUT_POST, 'part_oncf', FILTER_VALIDATE_FLOAT);
        $analytical_allocation = filter_input(INPUT_POST, 'analytical_allocation', FILTER_SANITIZE_STRING);
        $accessories = filter_input(INPUT_POST, 'accessories', FILTER_SANITIZE_STRING);
        
        // Determine if there's a problem being reported
        $isReportingProblem = !empty($reported_problems);
        $status = $isReportingProblem ? 'problem' : 'draft';
        
        // Update contract with all fields
        $stmt = $pdo->prepare("
            UPDATE contracts 
            SET shipment_date = ?, 
                shipment_weight = ?, 
                wagon_count = ?, 
                tarp_count = ?,
                total_units = ?,
                gare_expéditrice = ?,
                gare_destinataire = ?,
                notes = ?,
                reported_problems = ?,
                expenses = ?,
                reimbursement = ?,
                paid_port = ?,
                total_port_due = ?,
                part_sncf = ?,
                part_oncf = ?,
                analytical_allocation = ?,
                accessories = ?,
                status = ?,
                updated_at = NOW()
            WHERE contract_id = ?
        ");
        
        if ($stmt->execute([
            $shipment_date, 
            $shipment_weight, 
            $wagon_count, 
            $tarp_count,
            $total_units,
            $gare_expeditrice, 
            $gare_destinataire, 
            $notes,
            $reported_problems,
            $expenses,
            $reimbursement,
            $paid_port,
            $total_port_due,
            $part_sncf,
            $part_oncf,
            $analytical_allocation,
            $accessories,
            $status,
            $contract_id
        ])) {
            // If there's a freight request, update merchandise info
            if ($contract['freight_request_id'] && $merchandise_id) {
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
            throw new Exception("Erreur lors de la mise à jour du contrat");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
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
        
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 280px;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 56px;
        }

        /* Admin Navigation */
        .admin-navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: 56px;
        }

        .avatar-sm {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Sidebar styling */
        .admin-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            position: fixed;
            height: calc(100vh - 56px);
            top: 56px;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 1000;
        }

        .admin-sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1.5rem;
            margin: 0.15rem 0;
            border-radius: 0.25rem;
            transition: all 0.2s;
        }

        .admin-sidebar .nav-link:hover, 
        .admin-sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(5px);
        }

        .admin-sidebar .nav-link i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }

        .admin-profile {
            text-align: center;
            padding: 1.5rem 0;
        }

        .admin-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 2.5rem;
            color: white;
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        /* Main content area */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all 0.3s;
            min-height: calc(100vh - 56px);
        }

        @media (max-width: 992px) {
            .admin-sidebar {
                margin-left: -100%;
            }
            .admin-sidebar.show {
                margin-left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Admin Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark admin-navbar fixed-top">
        <div class="container-fluid px-3">
            <!-- Brand with sidebar toggle for mobile -->
            <div class="d-flex align-items-center">
                <button class="btn btn-link me-2 d-lg-none text-white" id="mobileSidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <a class="navbar-brand fw-bold" href="admin-dashboard.php">
                    <i class="fas fa-train me-2"></i>SNCFT Admin
                </a>
            </div>

            <!-- Right side navigation items -->
            <div class="d-flex align-items-center">
                <!-- Notification dropdown -->
                <div class="dropdown me-3">
                    <a class="nav-link dropdown-toggle position-relative p-2" href="#" id="notifDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php 
                        // Get unread notifications for navbar
                        $admin_id = $_SESSION['user']['id'];
                        $notifStmt = $pdo->prepare("
                            SELECT * FROM notifications 
                            WHERE (user_id = ? OR (user_id IS NULL AND metadata->>'target_audience' = 'admins'))
                            AND is_read = FALSE
                            ORDER BY created_at DESC
                            LIMIT 5
                        ");
                        $notifStmt->execute([$admin_id]);
                        $notifications = $notifStmt->fetchAll();
                        
                        if (count($notifications) > 0): 
                        ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= count($notifications) ?>
                                <span class="visually-hidden">unread notifications</span>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notifDropdown">
                        <?php if (empty($notifications)): ?>
                            <li><span class="dropdown-item-text">Aucune notification</span></li>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <li>
                                    <a class="dropdown-item" href="#">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-bell text-primary"></i>
                                            </div>
                                            <div class="flex-grow-1 ms-2">
                                                <p class="mb-0"><?= htmlspecialchars($notif['message']) ?></p>
                                                <small class="text-muted">
                                                    <?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar Navigation -->
    <div class="admin-sidebar">
        <div class="admin-profile">
            <div class="admin-avatar">
                <i class="fas fa-user-shield"></i>
            </div>
            <?php
            // Get admin info
            $admin_id = $_SESSION['user']['id'];
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE user_id = ?");
            $stmt->execute([$admin_id]);
            $admin = $stmt->fetch();
            ?>
            <h5 class="mt-3 mb-0"><?= htmlspecialchars($admin['department'] ?? 'Administrateur') ?></h5>
            <small class="text-white-50">Niveau d'accès: <?= $admin['access_level'] ?? 1 ?></small>
        </div>
        
        <ul class="nav flex-column px-3">
            <li class="nav-item">
                <a class="nav-link" href="admin-dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Tableau de bord
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage-clients.php">
                    <i class="fas fa-users"></i> Gestion Clients
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage-stations.php">
                    <i class="fas fa-train"></i> Gestion Gares
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage-tariffs.php">
                    <i class="fas fa-money-bill-wave"></i> Tarifs
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="admin-contracts.php">
                    <i class="fas fa-file-contract"></i> Contrats
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="admin-settings.php">
                    <i class="fas fa-cog"></i> Paramètres
                </a>
            </li>
        </ul>
    </div>

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
                                <label class="form-label required-field">Poids (kg)</label>
                                <input type="number" class="form-control" name="shipment_weight" value="<?= htmlspecialchars($contract['shipment_weight']) ?>" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label required-field">Nombre de Wagons</label>
                                <input type="number" class="form-control" name="wagon_count" value="<?= htmlspecialchars($contract['wagon_count']) ?>" min="1" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label required-field">Nombre de Bâches</label>
                                <input type="number" class="form-control" name="tarp_count" value="<?= htmlspecialchars($contract['tarp_count'] ?? '') ?>" min="0" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label required-field">Nombre Total d'Unités</label>
                                <input type="number" class="form-control" name="total_units" value="<?= htmlspecialchars($contract['total_units'] ?? '') ?>" min="1" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Accessoires</label>
                                <input type="text" class="form-control" name="accessories" value="<?= htmlspecialchars($contract['accessories'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-money-bill-wave me-2"></i>Détails Financiers
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Frais (TND)</label>
                                <input type="number" class="form-control" name="expenses" value="<?= htmlspecialchars($contract['expenses'] ?? '') ?>" step="0.01" min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Remboursement (TND)</label>
                                <input type="number" class="form-control" name="reimbursement" value="<?= htmlspecialchars($contract['reimbursement'] ?? '') ?>" step="0.01" min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Port Payé (TND)</label>
                                <input type="number" class="form-control" name="paid_port" value="<?= htmlspecialchars($contract['paid_port'] ?? '') ?>" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Port Dû Total (TND)</label>
                                <input type="number" class="form-control" name="total_port_due" value="<?= htmlspecialchars($contract['total_port_due'] ?? '') ?>" step="0.01" min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Part SNCF (TND)</label>
                                <input type="number" class="form-control" name="part_sncf" value="<?= htmlspecialchars($contract['part_sncf'] ?? '') ?>" step="0.01" min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Part ONCF (TND)</label>
                                <input type="number" class="form-control" name="part_oncf" value="<?= htmlspecialchars($contract['part_oncf'] ?? '') ?>" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Allocation Analytique</label>
                                <input type="text" class="form-control" name="analytical_allocation" value="<?= htmlspecialchars($contract['analytical_allocation'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-exclamation-triangle me-2"></i>Problèmes et Notes
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Problèmes Signalés</label>
                                <textarea class="form-control" name="reported_problems" rows="3"><?= htmlspecialchars($contract['reported_problems'] ?? '') ?></textarea>
                                <small class="form-text text-muted">Si un problème est signalé, le contrat sera marqué comme problématique.</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Notes Additionnelles</label>
                                <textarea class="form-control" name="notes" rows="3"><?= htmlspecialchars($contract['notes'] ?? '') ?></textarea>
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
    <script>
        // Mobile sidebar toggle
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.querySelector('.admin-sidebar').classList.toggle('show');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.admin-sidebar');
            const toggle = document.getElementById('mobileSidebarToggle');
            
            if (window.innerWidth < 992 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target) && 
                sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
            }
        });
    </script>
</body>
</html>