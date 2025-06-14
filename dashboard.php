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
$ngrok_url = 'https://2353-41-230-77-145.ngrok-free.app ';

$driver_id = $_SESSION['user']['id']; // Updated to use new session structure
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interface Conducteur | SNCF</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #e41f1f;
            --primary-dark: #c21717;
            --secondary-color: #2c3e50;
            --light-gray: #f5f5f5;
            --medium-gray: #e0e0e0;
            --dark-gray: #7f8c8d;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--light-gray);
            color: #333;
            line-height: 1.6;
        }
        
        .dashboard-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        .dashboard-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-header h1 {
            font-size: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info span {
            font-weight: 500;
        }
        
        .dashboard-content {
            display: flex;
            flex-direction: column;
            padding: 2rem;
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
        }
        
        .card h2 {
            color: var(--secondary-color);
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--medium-gray);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .train-type-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--medium-gray);
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .train-type-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .train-status {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 0.75rem;
            background-color: var(--light-gray);
            border-radius: 4px;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .status-ready {
            background-color: var(--info-color);
        }
        
        .status-active {
            background-color: var(--success-color);
        }
        
        .status-paused {
            background-color: var(--danger-color);
        }
        
        .speed-display {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--secondary-color);
            margin: 0.5rem 0;
            display: flex;
            align-items: baseline;
            gap: 5px;
        }
        
        .speed-unit {
            font-size: 1rem;
            color: var(--dark-gray);
            font-weight: normal;
        }
        
        .gps-status {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .gps-active {
            color: var(--success-color);
        }
        
        .gps-inactive {
            color: var(--danger-color);
        }
        
        #last-update {
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-logout {
            background-color: transparent;
            color: white;
            border: 1px solid white;
        }
        
        .btn-logout:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .info-card {
            background-color: var(--light-gray);
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid var(--primary-color);
        }
        
        .info-card h3 {
            font-size: 0.9rem;
            color: var(--dark-gray);
            margin-bottom: 0.5rem;
        }
        
        .info-card p {
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        @media (min-width: 768px) {
            .dashboard-content {
                flex-direction: row;
            }
            
            .card {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1><i class="fas fa-train"></i> SNCF - Interface Conducteur</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($_SESSION['user']['username']); ?></span>
                <a href="logout.php" class="btn btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Déconnexion
                </a>
            </div>
        </header>
        
        <div class="dashboard-content">
            <div class="card">
                <h2>Informations du train</h2>
                
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
                
                <div class="info-grid">
                    <div class="info-card">
                        <h3>Vitesse actuelle</h3>
                        <div class="speed-display">
                            <span id="current-speed">0</span>
                            <span class="speed-unit">km/h</span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h3>Statut GPS</h3>
                        <div id="gps-status" class="gps-status gps-inactive">
                            <i class="fas fa-satellite-dish"></i> <span>Non actif</span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h3>Dernière mise à jour</h3>
                        <p id="last-update">-</p>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="startTracking()">
                        <i class="fas fa-play"></i> Démarrer
                    </button>
                    <button class="btn btn-outline" onclick="stopTracking()" style="display:none;">
                        <i class="fas fa-stop"></i> Arrêter
                    </button>
                </div>
            </div>
            
        </div>
    </div>

    <script>
        // Variables globales
        let watchId = null;
        let isTracking = false;
        let positionHistory = [];
        const MAX_HISTORY = 5;
        
        // Fonction pour mettre à jour le statut du train
        function updateTrainStatus(status) {
            const indicator = document.getElementById('status-indicator');
            const text = document.getElementById('status-text');
            
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
                const gpsStatus = document.getElementById('gps-status');
                gpsStatus.className = 'gps-status gps-active';
                gpsStatus.innerHTML = '<i class="fas fa-satellite-dish"></i> <span>En cours...</span>';
                
                updateTrainStatus('active');
                
                const options = {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                };
                
                watchId = navigator.geolocation.watchPosition(
                    (position) => handlePositionUpdate(position, trainType),
                    handlePositionError,
                    options
                );
                
                isTracking = true;
                document.querySelector('.btn-primary').style.display = 'none';
                document.querySelector('.btn-outline').style.display = 'inline-block';
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
            const gpsStatus = document.getElementById('gps-status');
            gpsStatus.className = 'gps-status gps-inactive';
            gpsStatus.innerHTML = '<i class="fas fa-satellite-dish"></i> <span>Non actif</span>';
            
            document.querySelector('.btn-primary').style.display = 'inline-block';
            document.querySelector('.btn-outline').style.display = 'none';
            
            updateTrainStatus('paused');
        }
        
        // Fonction pour gérer les mises à jour de position
        function handlePositionUpdate(position, trainType) {
            const gpsStatus = document.getElementById('gps-status');
            gpsStatus.className = 'gps-status gps-active';
            gpsStatus.innerHTML = '<i class="fas fa-satellite-dish"></i> <span>Actif</span>';
            
            positionHistory.push({
                timestamp: position.timestamp,
                coords: {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude
                }
            });
            
            if (positionHistory.length > MAX_HISTORY) {
                positionHistory.shift();
            }
            
            if (positionHistory.length >= 2) {
                const latest = positionHistory[positionHistory.length - 1];
                const previous = positionHistory[positionHistory.length - 2];
                
                const timeDiff = (latest.timestamp - previous.timestamp) / 1000;
                const distance = calculateDistance(
                    previous.coords.latitude,
                    previous.coords.longitude,
                    latest.coords.latitude,
                    latest.coords.longitude
                );
                
                const speed = timeDiff > 0 ? (distance / (timeDiff / 3600)) : 0;
                updateSpeedDisplay(speed);
                
                sendPositionData(
                    latest.coords.latitude,
                    latest.coords.longitude,
                    speed,
                    position.timestamp,
                    trainType
                );
            } else if (position.coords.speed !== null) {
                const speed = position.coords.speed * 3.6;
                updateSpeedDisplay(speed);
                
                sendPositionData(
                    position.coords.latitude,
                    position.coords.longitude,
                    speed,
                    position.timestamp,
                    trainType
                );
            }
            
            const now = new Date();
            document.getElementById('last-update').textContent = now.toLocaleTimeString();
        }
        
        // Fonction pour calculer la distance entre deux points (en km)
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371;
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
            
            if (speed > 100) {
                speedElement.style.color = 'var(--danger-color)';
            } else if (speed > 50) {
                speedElement.style.color = 'var(--warning-color)';
            } else {
                speedElement.style.color = 'var(--success-color)';
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
                    errorMessage = "Permission refusée";
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMessage = "Position indisponible";
                    break;
                case error.TIMEOUT:
                    errorMessage = "Temps écoulé";
                    break;
                case error.UNKNOWN_ERROR:
                    errorMessage = "Erreur inconnue";
                    break;
            }
            
            const gpsStatus = document.getElementById('gps-status');
            gpsStatus.className = 'gps-status gps-inactive';
            gpsStatus.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <span>Erreur: ${errorMessage}</span>`;
        }
        
        // Définir le statut initial au chargement de la page
        window.onload = function() {
            updateTrainStatus('ready');
        };
    </script>
</body>
</html>