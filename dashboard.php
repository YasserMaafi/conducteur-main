<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Check if user is a driver
if ($_SESSION['user']['role'] !== 'driver') {
    die("Accès réservé aux conducteurs");
}

$ngrok_url = 'https://f021-2c0f-f290-3080-c26d-a85a-d71f-9993-f0f8.ngrok-free.app';
$driver_id = $_SESSION['user']['id']; // Updated to use new session structure
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interface Conducteur</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .speed-display {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin: 10px 0;
        }
        .speed-unit {
            font-size: 1rem;
            color: #7f8c8d;
        }
        .gps-status {
            margin-top: 10px;
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        .gps-active {
            color: #27ae60;
        }
        .gps-inactive {
            color:rgb(238, 17, 17);
        }
        .train-type-select {
            margin-bottom: 15px;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ddd;
            width: 100%;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-ready {
            background-color: #3498db; /* Bleu pour Prêt à départ */
        }
        .status-active {
            background-color: #2ecc71; /* Vert pour En service */
        }
        .status-paused {
            background-color:rgb(250, 7, 7); /* rouge pour En pause */
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1><i class="fas fa-train"></i> Interface Conducteur</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($_SESSION['user']['username']); ?></span>
                <a href="logout.php" class="btn btn-logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
            </div>
        </header>
        
        <div class="dashboard-content">
            <div class="card">
                <h2>Messages</h2>
                <div id="messages-container">
                    <!-- Messages will appear here -->
                </div>
                
                <div class="message-form">
                    <textarea id="message-input" placeholder="Tapez votre message ici..."></textarea>
                    <button onclick="sendMessage()" class="btn btn-send">
                        <i class="fas fa-paper-plane"></i> Envoyer
                    </button>
                </div>
            </div>
            
            <div class="card">
                <h2>Statut du train</h2>
                
                <div class="form-group">
                    <label>Type de train</label>
                    <select id="train-type" class="train-type-select">
                        <option value="">-- Sélectionnez le type de train --</option>
                        <option value="GT26-101">GT26-101</option>
                        <option value="GT26-102">GT26-102</option>
                        <option value="GT26-103">GT26-103</option>
                        <option value="GT26-104">GT26-104</option>
                        <option value="GT26-105">GT26-105</option>
                        <option value="GT26-106">GT26-106</option>
                        <option value="GT26-107">GT26-107</option>
                        <option value="GT26-108">GT26-108</option>
                        <option value="CC21000-201">CC21000-201</option>
                        <option value="CC21000-202">CC21000-202</option>
                        <option value="CC21000-203">CC21000-203</option>
                        <option value="BB1200-301">BB1200-301</option>
                        <option value="BB1200-302">BB1200-302</option>
                        <option value="BB1200-303">BB1200-303</option>
                        <option value="BB1200-304">BB1200-304</option>
                    </select>
                </div>
                
                <div class="train-status">
                    <div id="status-indicator" class="status-indicator status-ready"></div>
                    <span id="status-text">Prêt à départ</span>
                </div>
                
                <div class="form-group">
                    <label>Vitesse actuelle</label>
                    <div class="speed-display">
                        <span id="current-speed">0</span>
                        <span class="speed-unit">km/h</span>
                    </div>
                    <div id="gps-status" class="gps-status gps-inactive">
                        <i class="fas fa-satellite-dish"></i> GPS: Non actif
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Dernière mise à jour</label>
                    <div id="last-update">-</div>
                </div>
                
                <button class="btn btn-status" onclick="startTracking()">
                    <i class="fas fa-play"></i> Démarrer le suivi
                </button>
                <button class="btn btn-stop" onclick="stopTracking()" style="display:none;">
                    <i class="fas fa-stop"></i> Arrêter le suivi
                </button>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let watchId = null;
        let lastPosition = null;
        let lastTimestamp = null;
        let isTracking = false;
        let positionHistory = [];
        const MAX_HISTORY = 5; // Nombre de positions à conserver pour le calcul
        
        // Fonction pour mettre à jour le statut du train
        function updateTrainStatus(status) {
            const indicator = document.getElementById('status-indicator');
            const text = document.getElementById('status-text');
            
            // Supprimer toutes les classes de statut
            indicator.classList.remove('status-ready', 'status-active', 'status-paused');
            
            switch(status) {
                case 'ready':
                    indicator.classList.add('status-ready');
                    text.textContent = 'Prêt à départ';
                    break;
                case 'active':
                    indicator.classList.add('status-active');
                    text.textContent = 'En service';
                    break;
                case 'paused':
                    indicator.classList.add('status-paused');
                    text.textContent = 'En pause';
                    break;
            }
            
            // Envoyer le statut au serveur
            sendStatusToServer(status);
        }
        
        // Fonction pour envoyer le statut au serveur
        function sendStatusToServer(status) {
            const data = {
                driver_id: <?php echo $driver_id; ?>,
                status: status,
                timestamp: new Date().toISOString()
            };
            
            fetch('update_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Erreur:', data.error);
                } else {
                    console.log('Statut mis à jour:', data.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
            });
        }
        
        // Fonction pour démarrer le suivi
        function startTracking() {
            const trainType = document.getElementById('train-type').value;
            if (!trainType) {
                alert("Veuillez sélectionner le type de train");
                return;
            }
            
            if (isTracking) return;
            
            if (navigator.geolocation) {
                document.getElementById('gps-status').className = 'gps-status gps-active';
                document.getElementById('gps-status').innerHTML = '<i class="fas fa-satellite-dish"></i> GPS: En cours...';
                
                // Mettre à jour le statut du train
                updateTrainStatus('active');
                
                // Options pour une haute précision
                const options = {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                };
                
                // Démarrer le watchPosition pour des mises à jour continues
                watchId = navigator.geolocation.watchPosition(
                    (position) => handlePositionUpdate(position, trainType),
                    handlePositionError,
                    options
                );
                
                isTracking = true;
                document.querySelector('.btn-status').style.display = 'none';
                document.querySelector('.btn-stop').style.display = 'inline-block';
                
                console.log("Suivi GPS démarré pour le train de type: " + trainType);
            } else {
                alert("La géolocalisation n'est pas supportée par votre navigateur.");
            }
        }
        
        // Fonction pour arrêter le suivi
        function stopTracking() {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            
            isTracking = false;
            document.getElementById('gps-status').className = 'gps-status gps-inactive';
            document.getElementById('gps-status').innerHTML = '<i class="fas fa-satellite-dish"></i> GPS: Non actif';
            
            document.querySelector('.btn-status').style.display = 'inline-block';
            document.querySelector('.btn-stop').style.display = 'none';
            
            // Mettre à jour le statut du train
            updateTrainStatus('paused');
            
            console.log("Suivi GPS arrêté");
        }
        
        // Fonction pour gérer les mises à jour de position
        function handlePositionUpdate(position, trainType) {
            console.log("Nouvelle position:", position);
            
            // Mettre à jour le statut GPS
            document.getElementById('gps-status').className = 'gps-status gps-active';
            document.getElementById('gps-status').innerHTML = '<i class="fas fa-satellite-dish"></i> GPS: Actif';
            
            // Ajouter la position à l'historique
            positionHistory.push({
                timestamp: position.timestamp,
                coords: {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude
                }
            });
            
            // Garder seulement les dernières positions
            if (positionHistory.length > MAX_HISTORY) {
                positionHistory.shift();
            }
            
            // Calculer la vitesse si nous avons assez de données
            if (positionHistory.length >= 2) {
                const latest = positionHistory[positionHistory.length - 1];
                const previous = positionHistory[positionHistory.length - 2];
                
                const timeDiff = (latest.timestamp - previous.timestamp) / 1000; // en secondes
                const distance = calculateDistance(
                    previous.coords.latitude,
                    previous.coords.longitude,
                    latest.coords.latitude,
                    latest.coords.longitude
                );
                
                // Vitesse en km/h (distance en km, temps en heures)
                const speed = timeDiff > 0 ? (distance / (timeDiff / 3600)) : 0;
                
                // Mettre à jour l'affichage
                updateSpeedDisplay(speed);
                
                // Envoyer les données au serveur
                sendPositionData(
                    latest.coords.latitude,
                    latest.coords.longitude,
                    speed,
                    position.timestamp,
                    trainType
                );
            } else if (position.coords.speed !== null) {
                // Si le navigateur fournit directement la vitesse
                const speed = position.coords.speed * 3.6; // conversion m/s en km/h
                updateSpeedDisplay(speed);
                
                // Envoyer les données au serveur
                sendPositionData(
                    position.coords.latitude,
                    position.coords.longitude,
                    speed,
                    position.timestamp,
                    trainType
                );
            }
            
            // Mettre à jour l'heure de la dernière mise à jour
            const now = new Date();
            document.getElementById('last-update').textContent = now.toLocaleTimeString();
        }
        
        // Fonction pour calculer la distance entre deux points (en km)
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Rayon de la Terre en km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
                Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        }
        
        // Fonction pour mettre à jour l'affichage de la vitesse
        function updateSpeedDisplay(speed) {
            const speedElement = document.getElementById('current-speed');
            speedElement.textContent = Math.round(speed);
            
            // Changer la couleur en fonction de la vitesse
            if (speed > 100) {
                speedElement.style.color = '#e74c3c'; // Rouge pour haute vitesse
            } else if (speed > 50) {
                speedElement.style.color = '#f39c12'; // Orange pour vitesse moyenne
            } else {
                speedElement.style.color = '#2ecc71'; // Vert pour basse vitesse
            }
        }
        
        // Fonction pour envoyer les données de position au serveur
        function sendPositionData(lat, lng, speed, timestamp, trainType) {
    const data = {
        driver_id: <?php echo $driver_id; ?>,
        driver_name: "<?php echo htmlspecialchars($_SESSION['user']['username']); ?>",
        lat: lat,
        lng: lng,
        speed: speed,
        timestamp: new Date(timestamp).toISOString(),
        train_type: trainType
    };
    
    fetch('update_position.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Erreur:', data.error);
        } else {
            console.log('Succès:', data.message);
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
    });
}


        
        // Fonction pour gérer les erreurs de géolocalisation
        function handlePositionError(error) {
            console.error('Erreur GPS:', error);
            
            let errorMessage = '';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    errorMessage = "Vous avez refusé la demande de géolocalisation.";
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMessage = "Les informations de localisation ne sont pas disponibles.";
                    break;
                case error.TIMEOUT:
                    errorMessage = "La demande de localisation a expiré.";
                    break;
                case error.UNKNOWN_ERROR:
                    errorMessage = "Une erreur inconnue s'est produite.";
                    break;
            }
            
            document.getElementById('gps-status').className = 'gps-status gps-inactive';
            document.getElementById('gps-status').innerHTML = `<i class="fas fa-exclamation-triangle"></i> GPS: Erreur - ${errorMessage}`;
        }
        
        // Fonction pour envoyer un message
        function sendMessage() {
            var message = document.getElementById('message-input').value;
            if (!message.trim()) return;
            
            fetch('send.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'sender=driver_<?php echo $driver_id; ?>&message=' + encodeURIComponent(message)
            })
            .then(response => response.text())
            .then(data => {
                console.log('Message sent:', data);
                document.getElementById('message-input').value = '';
                fetchMessages();
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        
        // Fonction pour récupérer les messages
        function fetchMessages() {
            fetch('get.php?receiver=driver_<?php echo $driver_id; ?>')
            .then(response => response.json())
            .then(messages => {
                var container = document.getElementById('messages-container');
                container.innerHTML = '';
                
                messages.forEach(msg => {
                    var messageDiv = document.createElement('div');
                    messageDiv.className = 'message';
                    messageDiv.innerHTML = `<strong>${msg.sender}</strong>: ${msg.message}`;
                    container.appendChild(messageDiv);
                });
                container.scrollTop = container.scrollHeight;
            })
            .catch(error => {
                console.error('Error fetching messages:', error);
            });
        }
        
        // Démarrer automatiquement le suivi au chargement de la page
        window.onload = function() {
            fetchMessages();
            
            // Définir le statut initial
            updateTrainStatus('ready');
            
            // Vérifier les nouveaux messages toutes les 5 secondes
            setInterval(fetchMessages, 5000);
        };
    </script>
</body>
</html>