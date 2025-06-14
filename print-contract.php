<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

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
$stmt = $pdo->prepare("SELECT a.*, u.email FROM agents a JOIN users u ON a.user_id = u.user_id WHERE a.user_id = ?");
$stmt->execute([$_SESSION['user']['id']]);
$agent = $stmt->fetch();

// Get contract details with all related information
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name AS client_name, cl.client_id, cl.account_code,
           g1.libelle AS origin, g1.code_gare AS origin_code,
           g2.libelle AS destination, g2.code_gare AS destination_code,
           m.description AS merchandise_type, m.code AS merchandise_code,
           c.merchandise_description AS request_description,
           fr.recipient_name, fr.recipient_contact,
           a.username AS admin_username,
           t.train_number,
           (SELECT COUNT(*) FROM payments WHERE contract_id = c.contract_id) as payment_count,
           (SELECT SUM(amount) FROM payments WHERE contract_id = c.contract_id) as total_paid
    FROM contracts c
    JOIN clients cl ON c.sender_client = cl.client_id
    JOIN gares g1 ON c.gare_expéditrice = g1.id_gare
    JOIN gares g2 ON c.gare_destinataire = g2.id_gare
    LEFT JOIN merchandise m ON c.merchandise_description = m.description
    LEFT JOIN freight_requests fr ON fr.id = c.freight_request_id
    LEFT JOIN users a ON a.user_id = c.draft_created_by
    LEFT JOIN trains t ON t.train_id = c.train_id
    WHERE c.contract_id = ? AND c.agent_id = ?
");
$stmt->execute([$contract_id, $agent['agent_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    $_SESSION['error'] = "Contrat non trouvé ou accès non autorisé";
    redirect('agent-dashboard.php');
}

// Get payment history
$payments = $pdo->prepare("
    SELECT p.*, u.username as recorded_by
    FROM payments p
    LEFT JOIN users u ON u.user_id = p.client_id
    WHERE p.contract_id = ?
    ORDER BY p.payment_date DESC
");
$payments->execute([$contract_id]);
$payment_history = $payments->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrat #<?= $contract_id ?> | SNCFT</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20mm;
            font-size: 12pt;
            line-height: 1.5;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #1a3c8f;
            padding-bottom: 20px;
        }
        
        .logo {
            max-width: 200px;
            margin-bottom: 10px;
        }
        
        .contract-title {
            font-size: 24pt;
            color: #1a3c8f;
            margin: 10px 0;
        }
        
        .contract-number {
            font-size: 14pt;
            color: #666;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 16pt;
            color: #1a3c8f;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .info-item {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: bold;
            color: #666;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 11pt;
        }
        
        .signature-section {
            margin-top: 50px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 50px;
        }
        
        .signature-box {
            border-top: 1px solid #ddd;
            padding-top: 10px;
            text-align: center;
        }
        
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 10pt;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .status-badge.draft {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-badge.validé {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-badge.en_cours {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-badge.in_transit {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-badge.completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-badge.cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        @media print {
            body {
                padding: 0;
            }
            
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Print Button (visible only on screen) -->
    <div class="no-print" style="position: fixed; top: 20px; right: 20px; z-index: 1000;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #1a3c8f; color: white; border: none; border-radius: 5px; cursor: pointer;">
            Imprimer le contrat
        </button>
    </div>

    <!-- Contract Header -->
    <div class="header">
        <img src="assets/images/sncft-logo.png" alt="SNCFT Logo" class="logo">
        <h1 class="contract-title">Contrat de Transport</h1>
        <div class="contract-number">N° CT-<?= $contract_id ?></div>
        <div class="status-badge <?= $contract['status'] ?>">
            <?= ucfirst(str_replace('_', ' ', $contract['status'])) ?>
        </div>
    </div>

    <!-- Client Information -->
    <div class="section">
        <h2 class="section-title">Informations Client</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Client Expéditeur</div>
                <div class="info-value"><?= htmlspecialchars($contract['client_name']) ?></div>
                <div class="info-value">Code: <?= htmlspecialchars($contract['account_code']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Client Destinataire</div>
                <div class="info-value"><?= htmlspecialchars($contract['recipient_name'] ?? $contract['client_name']) ?></div>
                <?php if ($contract['recipient_contact']): ?>
                    <div class="info-value">Contact: <?= htmlspecialchars($contract['recipient_contact']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Contract Details -->
    <div class="section">
        <h2 class="section-title">Détails du Contrat</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Type de Transaction</div>
                <div class="info-value"><?= htmlspecialchars(ucfirst($contract['transaction_type'])) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Mode de Paiement</div>
                <div class="info-value"><?= htmlspecialchars($contract['payment_mode']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Gare d'Origine</div>
                <div class="info-value">
                    <?= htmlspecialchars($contract['origin']) ?>
                    (<?= htmlspecialchars($contract['origin_code']) ?>)
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Gare de Destination</div>
                <div class="info-value">
                    <?= htmlspecialchars($contract['destination']) ?>
                    (<?= htmlspecialchars($contract['destination_code']) ?>)
                </div>
            </div>
        </div>
    </div>

    <!-- Shipping Details -->
    <div class="section">
        <h2 class="section-title">Détails d'Expédition</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Marchandise</div>
                <div class="info-value">
                    <?= htmlspecialchars($contract['merchandise_type'] ?? $contract['request_description']) ?>
                    <?php if ($contract['merchandise_code']): ?>
                        (<?= htmlspecialchars($contract['merchandise_code']) ?>)
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Date d'Expédition</div>
                <div class="info-value"><?= date('d/m/Y', strtotime($contract['shipment_date'])) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Poids</div>
                <div class="info-value"><?= number_format($contract['shipment_weight'], 2) ?> kg</div>
            </div>
            <div class="info-item">
                <div class="info-label">Nombre de Wagons</div>
                <div class="info-value"><?= $contract['wagon_count'] ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Nombre de Bâches</div>
                <div class="info-value"><?= $contract['tarp_count'] ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Nombre d'Unités</div>
                <div class="info-value"><?= $contract['total_units'] ?></div>
            </div>
        </div>
        <?php if ($contract['accessories']): ?>
            <div class="info-item">
                <div class="info-label">Accessoires</div>
                <div class="info-value"><?= htmlspecialchars($contract['accessories']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Financial Details -->
    <div class="section">
        <h2 class="section-title">Détails Financiers</h2>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Port Dû Total</div>
                <div class="info-value"><?= number_format($contract['total_port_due'], 2) ?> €</div>
            </div>
            <div class="info-item">
                <div class="info-label">Port Payé</div>
                <div class="info-value"><?= number_format($contract['paid_port'], 2) ?> €</div>
            </div>
            <div class="info-item">
                <div class="info-label">Total Payé</div>
                <div class="info-value"><?= number_format($contract['total_paid'] ?? 0, 2) ?> €</div>
            </div>
            <div class="info-item">
                <div class="info-label">Frais</div>
                <div class="info-value"><?= number_format($contract['expenses'], 2) ?> €</div>
            </div>
            <div class="info-item">
                <div class="info-label">Remboursement</div>
                <div class="info-value"><?= number_format($contract['reimbursement'], 2) ?> €</div>
            </div>
            <div class="info-item">
                <div class="info-label">Allocation Analytique</div>
                <div class="info-value"><?= htmlspecialchars($contract['analytical_allocation'] ?? 'Non spécifié') ?></div>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <?php if (!empty($payment_history)): ?>
    <div class="section">
        <h2 class="section-title">Historique des Paiements</h2>
        <div class="info-grid">
            <?php foreach ($payment_history as $payment): ?>
                <div class="info-item">
                    <div class="info-label">Paiement du <?= date('d/m/Y', strtotime($payment['payment_date'])) ?></div>
                    <div class="info-value">
                        <?= number_format($payment['amount'], 2) ?> €
                        (<?= htmlspecialchars($payment['payment_method']) ?>)
                        <?php if ($payment['reference_number']): ?>
                            <br>Ref: <?= htmlspecialchars($payment['reference_number']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="info-label">Signature du Client</div>
            <div style="height: 100px;"></div>
            <div class="info-value"><?= htmlspecialchars($contract['client_name']) ?></div>
        </div>
        <div class="signature-box">
            <div class="info-label">Signature de l'Agent SNCFT</div>
            <div style="height: 100px;"></div>
            <div class="info-value"><?= htmlspecialchars($agent['badge_number']) ?></div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>SNCFT - Société Nationale des Chemins de Fer Tunisiens</p>
        <p>Contrat généré le <?= date('d/m/Y H:i') ?></p>
    </div>
</body>
</html> 