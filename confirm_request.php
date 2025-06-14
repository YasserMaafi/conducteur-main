<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/notification_functions.php';

// Verify client role and session
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
  header('Location: ../index.php');
  exit();
}
// Check if client_id exists in session
if (!isset($_SESSION['user']['client_id'])) {
  // Alternative: Get client_id from database if not in session
  $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
  $stmt->execute([$_SESSION['user']['id']]);
  $client = $stmt->fetch();
  
  if ($client) {
      $_SESSION['user']['client_id'] = $client['client_id'];
  } else {
      die("Error: Client account not properly configured");
  }
}




if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $requestId = intval($_POST['request_id']);
    $clientId = $_SESSION['user_id'];

    // Check ownership
    $stmt = $pdo->prepare("SELECT * FROM freight_requests WHERE id = ? AND client_id = ?");
    $stmt->execute([$requestId, $clientId]);
    $request = $stmt->fetch();

    if ($request) {
        // 1. Update request status
        $update = $pdo->prepare("UPDATE freight_requests SET status = 'client_confirmed', updated_at = NOW() WHERE id = ?");
        $update->execute([$requestId]);

        // 2. Find all admins
        $adminStmt = $pdo->query("SELECT user_id FROM admins");
        $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Send notification to each admin
        foreach ($admins as $adminId) {
// Update notification creation to use correct type
// Create one notification visible to all admins
// Create one system-wide notification for all admins
// When client confirms a request
createNotification(
    null, // System notification
    'client_confirmed',
    'Confirmation Client',
    "Le client a confirmé la demande #$requestId",
    $requestId,
    [
        'request_id' => $requestId,
        'client_id' => $clientId,
        'target_audience' => 'admins',
        'action_url' => "admin-request-details.php?id=$requestId" // Explicit action URL
    ]
);
        }

        header("Location: client-dashboard.php?success=1");
        exit;
    } else {
        header("Location: client-dashboard.php?error=not_found");
        exit;
    }
} else {
    header("Location: client-dashboard.php");
    exit;
}
