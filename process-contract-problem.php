<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contract_id = filter_input(INPUT_POST, 'contract_id', FILTER_VALIDATE_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if (!$contract_id) {
        $_SESSION['error'] = "ID de contrat invalide";
        header('Location: admin-contracts.php');
        exit();
    }
    
    // Get contract details to access agent_id
    $stmt = $pdo->prepare("SELECT agent_id FROM contracts WHERE contract_id = ?");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch();
    
    if (!$contract) {
        $_SESSION['error'] = "Contrat non trouvé";
        header('Location: admin-contracts.php');
        exit();
    }
    
    // Get agent's user_id for notification
    $stmt = $pdo->prepare("SELECT user_id FROM agents WHERE agent_id = ?");
    $stmt->execute([$contract['agent_id']]);
    $agent = $stmt->fetch();
    
    if ($action === 'fix_issue') {
        // Update contract status back to en_cours
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'draft', notes = COALESCE(notes, '') || E'\n[Admin: ' || ? || ']', updated_at = NOW() WHERE contract_id = ?");
        
        if ($stmt->execute([$notes, $contract_id])) {
            // Create notification for agent
            if ($agent) {
                $title = "Problème résolu";
                $message = "Le problème signalé pour le contrat #$contract_id a été résolu.";
                $metadata = [
                    'contract_id' => $contract_id,
                    'link' => "agent-contract-details.php?id=$contract_id"
                ];
                
                createNotification(
                    $agent['user_id'],
                    'problem_resolved',
                    $title,
                    $message,
                    null,
                    $metadata
                );
            }
            
            $_SESSION['success'] = "Problème résolu et contrat envoyé à l'agent avec succès";
        } else {
            $_SESSION['error'] = "Erreur lors de la mise à jour du contrat";
        }
    } elseif ($action === 'cancel_contract') {
        // Update contract status to annulé
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'annulé', notes = COALESCE(notes, '') || E'\n[Admin: ' || ? || ']', updated_at = NOW() WHERE contract_id = ?");
        
        if ($stmt->execute([$notes, $contract_id])) {
            // Create notification for agent
            if ($agent) {
                $title = "Contrat annulé";
                $message = "Le contrat #$contract_id a été annulé suite au problème signalé.";
                $metadata = [
                    'contract_id' => $contract_id,
                    'link' => "agent-contract-details.php?id=$contract_id"
                ];
                
                createNotification(
                    $agent['user_id'],
                    'contract_cancelled',
                    $title,
                    $message,
                    null,
                    $metadata
                );
            }
            
            $_SESSION['success'] = "Contrat annulé avec succès";
        } else {
            $_SESSION['error'] = "Erreur lors de l'annulation du contrat";
        }
    }
    
    header("Location: admin-contract-details.php?id=$contract_id");
    exit();
}

header('Location: admin-contracts.php');
exit();