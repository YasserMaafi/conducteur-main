<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// DEBUG: Log all POST data
error_log("Received POST data: " . print_r($_POST, true));
file_put_contents('debug.log', print_r($_POST, true), FILE_APPEND);

if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    error_log("Unauthorized access attempt");
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. Get request and client details
        $stmt = $pdo->prepare("
            SELECT fr.*, u.user_id as client_user_id, u.email as client_email,
                   g1.libelle as origin_name, g2.libelle as destination_name,
                   m.description as merchandise_desc
            FROM freight_requests fr
            JOIN clients c ON fr.client_id = c.client_id
            JOIN users u ON c.user_id = u.user_id
            JOIN gares g1 ON fr.gare_depart = g1.id_gare
            JOIN gares g2 ON fr.gare_arrivee = g2.id_gare
            LEFT JOIN merchandise m ON fr.merchandise_id = m.merchandise_id
            WHERE fr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();

        if (!$request) {
            throw new Exception("Demande introuvable");
        }

        // 2. Process based on action
        if ($action === 'confirm') {
            // Validate confirmation data
            $required = ['wagons', 'eta', 'price'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Tous les champs sont obligatoires");
                }
            }

            // Update request status
            $stmt = $pdo->prepare("
                UPDATE freight_requests 
                SET status = 'confirmed', 
                    admin_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $notes = "Confirmé le " . date('Y-m-d H:i:s') . " par l'admin " . $_SESSION['user']['username'];
            $stmt->execute([$notes, $request_id]);
            
            // Create contract
            $stmt = $pdo->prepare("
                INSERT INTO contracts (
                    gare_expéditrice, gare_destinataire,
                    sender_client, merchandise_description, 
                    shipment_weight, status, wagon_count,
                    paid_port, shipment_date, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $request['gare_depart'],
                $request['gare_arrivee'],
                $request['client_id'],
                $request['merchandise_desc'] ?? $request['description'],
                $request['quantity'],
                'pending_client_approval',
                $_POST['wagons'],
                $_POST['price'],
                $_POST['eta']
            ]);
            $contract_id = $pdo->lastInsertId();

            // Notification message
            $message = "Votre demande #FR-$request_id a été confirmée\n\n";
            $message .= "Détails:\n";
            $message .= "• Trajet: {$request['origin_name']} → {$request['destination_name']}\n";
            $message .= "• Marchandise: {$request['merchandise_desc']} ({$request['quantity']} kg)\n";
            $message .= "• Wagons alloués: {$_POST['wagons']}\n";
            $message .= "• Date estimée: {$_POST['eta']}\n";
            $message .= "• Prix: {$_POST['price']}€ ({$request['mode_paiement']})\n\n";
            $message .= "Merci de votre confiance.";

        } elseif ($action === 'deny') {
            if (empty($_POST['reason'])) {
                throw new Exception("Veuillez spécifier une raison");
            }

            // Update request status
            $stmt = $pdo->prepare("
                UPDATE freight_requests 
                SET status = 'rejected', 
                    admin_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $notes = "Refusé le " . date('Y-m-d H:i:s') . "\nRaison: {$_POST['reason']}";
            $stmt->execute([$notes, $request_id]);

            // Notification message
            $message = "Votre demande #FR-$request_id a été refusée\n\n";
            $message .= "Raison du refus:\n";
            $message .= $_POST['reason'] . "\n\n";
            $message .= "Pour plus d'informations, contactez notre service client.";
        }

        // 3. Create notification
        $stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, 
                related_request_id, related_contract_id, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $metadata = [
            'action' => $action,
            'request_id' => $request_id,
            'admin_id' => $_SESSION['user']['id'],
            'timestamp' => date('c')
        ];
        $stmt->execute([
            $request['client_user_id'],
            ($action === 'confirm') ? 'request_confirmed' : 'request_rejected',
            ($action === 'confirm') ? 'Demande confirmée' : 'Demande refusée',
            $message,
            $request_id,
            ($action === 'confirm') ? $contract_id : null,
            json_encode($metadata)
        ]);

        $pdo->commit();
        $_SESSION['success'] = "Opération réussie! La notification a été envoyée au client.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    redirect('admin-dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = $_POST['request_id'];
    $status = $_POST['status'];
    $admin_notes = $_POST['admin_notes'];

    // Update the freight request
    $stmt = $pdo->prepare("UPDATE freight_requests SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $admin_notes, $request_id]);

    // Get the client user ID
    $stmt = $pdo->prepare("
        SELECT u.user_id 
        FROM freight_requests fr
        JOIN clients c ON fr.client_id = c.client_id
        JOIN users u ON c.user_id = u.user_id
        WHERE fr.id = ?
    ");
    $stmt->execute([$request_id]);
    $client_user_id = $stmt->fetchColumn();

    // Send appropriate notification
    if ($status === 'accepted') {
        createNotification(
            $client_user_id,
            'request_approved',
            'Demande Acceptée',
            'Votre demande a été acceptée par l\'administration.',
            $request_id,
            ['admin_notes' => $admin_notes]
        );
    } elseif ($status === 'rejected') {
        createNotification(
            $client_user_id,
            'request_rejected',
            'Demande Refusée',
            'Votre demande a été refusée. Motif : ' . ($admin_notes ?: 'Non spécifié.'),
            $request_id,
            ['admin_notes' => $admin_notes]
        );
    }

    header("Location: admin-dashboard.php?msg=updated");
    exit;
}
