<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/pricing_functions.php';

header('Content-Type: application/json');

// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
$origin = filter_input(INPUT_GET, 'origin', FILTER_VALIDATE_INT);
$destination = filter_input(INPUT_GET, 'destination', FILTER_VALIDATE_INT);
$wagon_count = filter_input(INPUT_GET, 'wagon_count', FILTER_VALIDATE_INT, [
    'options' => ['default' => 1, 'min_range' => 1]
]);

if (!$client_id || !$origin || !$destination) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify the requesting admin has access to this client's data
try {
    $stmt = $pdo->prepare("
        SELECT 1 FROM clients 
        WHERE client_id = ? 
        AND EXISTS (SELECT 1 FROM admins WHERE user_id = ?)
    ");
    $stmt->execute([$client_id, $_SESSION['user']['id']]);
    
    if (!$stmt->fetch()) {
        throw new Exception("Access denied to client data");
    }
    
    $result = calculateFreightPrice($client_id, $origin, $destination, $wagon_count);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}