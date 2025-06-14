<?php
// get.php
header('Content-Type: application/json');

$receiver = $_GET['receiver'] ?? '';

if (empty($receiver)) {
    die(json_encode(['error' => 'Receiver not specified']));
}

$messages = [];
if (file_exists('messages.json')) {
    $allMessages = json_decode(file_get_contents('messages.json'), true);
    
    // Filtrer les messages pour ce destinataire
    foreach ($allMessages as $msg) {
        if ($msg['receiver'] === $receiver || $msg['sender'] === $receiver) {
            $messages[] = $msg;
        }
    }
}

echo json_encode($messages);
?>