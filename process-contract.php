<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contract_id = filter_input(INPUT_POST, 'contract_id', FILTER_VALIDATE_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    
    if (!$contract_id) {
        $_SESSION['error'] = "ID de contrat invalide";
        header('Location: admin-contracts.php');
        exit();
    }
    
    if ($action === 'send_to_agent') {
        // Update contract status
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'en_cours', updated_at = NOW() WHERE contract_id = ?");
        
        if ($stmt->execute([$contract_id])) {
            // Create notification for agents
            $message = "Nouveau contrat #$contract_id assigné";
            $pdo->prepare("
                INSERT INTO notifications (user_id, message, metadata, target_audience)
                VALUES (NULL, ?, ?, 'agents')
            ")->execute([$message, json_encode(['contract_id' => $contract_id])]);
            
            $_SESSION['success'] = "Contrat envoyé à l'agent avec succès";
        } else {
            $_SESSION['error'] = "Erreur lors de la mise à jour du contrat";
        }
    }
    
    header("Location: admin-contract-details.php?id=$contract_id");
    exit();
}

header('Location: admin-contracts.php');
exit();