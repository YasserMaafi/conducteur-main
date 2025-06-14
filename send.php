<?php
// send_message.php
session_start();

$sender = $_POST['sender'] ?? '';
$message = $_POST['message'] ?? '';

if (empty($sender)) {
    die('Sender not specified');
}

if (empty($message)) {
    die('Message is empty');
}

// Enregistrer le message dans un fichier ou une base de données
// Ici, nous utilisons un simple fichier JSON pour l'exemple
$messages = [];
if (file_exists('messages.json')) {
    $messages = json_decode(file_get_contents('messages.json'), true);
}

$receiver = $sender === 'control' ? 'driver' : 'control';

$messages[] = [
    'sender' => $sender,
    'receiver' => $receiver,
    'message' => $message,
    'timestamp' => date('Y-m-d H:i:s')
];

file_put_contents('messages.json', json_encode($messages));

echo 'Message sent successfully';
?>