 <!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Localisation des Gares Tunisiennes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <link rel="stylesheet" href="style.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <button class="close-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="sidebar-content">
        <div class="menu-section">
                <div class="menu-title" onclick="tog('dashboard-section')">
                    <span><i class="fas fa-tachometer-alt"></i> Dashboard</span>
                </div>
            </div>
            <div class="form-group">
                    <button class="btn btn-primary" style="width: 100%;" onclick="openCommunicationPanel()">
                        <i class="fas fa-comments"></i> Communication avec le train
                    </button>
            </div>
            <div class="modal" id="communication-modal">
                <div class="modal-header">
                    <div class="modal-title">Communication avec le train</div>
                    <button class="modal-close" onclick="hideCommunicationPanel()">&times;</button>
                </div>
                <div style="padding: 15px;">
                    <div id="messages-container" style="height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 15px; background-color: #f9f9f9;">
                    </div>
                    <div class="form-group">
                        <label for="message-input">Message à envoyer:</label>
                        <textarea id="message-input" class="form-control" rows="3" style="width: 100%;"></textarea>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <button class="btn btn-secondary" onclick="hideCommunicationPanel()">
                            <i class="fas fa-times"></i> Fermer
                        </button>
                        <button class="btn btn-primary" onclick="sendMessage()">
                            <i class="fas fa-paper-plane"></i> Envoyer
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Nouveau menu Gérer les Trains -->
            <div class="menu-section">
                <div class="menu-title" onclick="toggleMenu('manage-trains')">
                    <span><i class="fas fa-train"></i> Gérer les Trains</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="menu-items" id="manage-trains">
                    <a href="#" class="menu-item" onclick="showAddTrainModal(); return false;">
                        <i class="fas fa-plus-circle"></i> Filtrage du train
                    </a>
                    <a href="#" class="menu-item" onclick="showTrainList(); return false;">
                        <i class="fas fa-list"></i> Modifier un train
                    </a>
                    <a href="#" class="menu-item" onclick="showTrainSchedule(); return false;">
                        <i class="fas fa-calendar-alt"></i> Horaires des trains
                    </a>
                </div>
            </div>
            <!-- Nouveau menu Gérer les Gares -->
            <div class="menu-section">
    <div class="menu-title" onclick="toggleMenu('manage-stations')">
        <span><i class="fas fa-building"></i> Gérer les Gares</span>
        <i class="fas fa-chevron-down"></i>
    </div>
    <div class="menu-items" id="manage-stations">
        <a href="#" class="menu-item" onclick="showAddStationModal(); return false;">
            <i class="fas fa-plus-circle"></i> Ajouter une gare
        </a>
        <a href="#" class="menu-item" onclick="showDeleteStationModal(); return false;">
            <i class="fas fa-trash-alt"></i> Supprimer une gare
        </a>
        <a href="#" class="menu-item" onclick="showEditStationModal(); return false;">
            <i class="fas fa-edit"></i> Modifier une gare
        </a>
        <a href="#" class="menu-item" onclick="showStationStats(); return false;">
            <i class="fas fa-chart-bar"></i> Historique des gestions du gares
        </a>
    </div>
</div>
<div class="menu-section">
    <div class="menu-title" onclick="toggleMenu('manage-drivers')">
        <span><i class="fas fa-users"></i> Gérer les Conducteurs</span>
        <i class="fas fa-chevron-down"></i>
    </div>
    <div class="menu-items" id="manage-drivers">
        <a href="#" class="menu-item" onclick="showAddDriverModal(); return false;">
            <i class="fas fa-plus-circle"></i> Ajouter un conducteur
        </a>
        <a href="#" class="menu-item" onclick="showDeleteDriverModal(); return false;">
            <i class="fas fa-trash-alt"></i> Supprimer un conducteur
        </a>
        <a href="#" class="menu-item" onclick="showDriverList(); return false;">
            <i class="fas fa-list"></i> Liste des conducteurs
        </a>
        <a href="#" class="menu-item" onclick="showDriverStats(); return false;">
            <i class="fas fa-chart-bar"></i> Statistiques des conducteurs
        </a>
    </div>
</div>
            <!-- Train Information Section -->
            <div class="menu-section">
                <div class="menu-title" onclick="toggleMenu('train-info')">
                    <span><i class="fas fa-info-circle"></i> Informations du Train</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="menu-items active" id="train-info">
                    <div class="train-info">
                        <div class="info-label">Statut du train</div>
                        <div class="info-value" id="train-status">Arrêté</div>
                    </div>
                    
                    <div class="train-info">
                        <div class="info-label">Position actuelle</div>
                        <div class="info-value" id="train-position">-</div>
                    </div>
                    <div class="train-info">
                        <div class="info-label">Prochaine gare</div>
                        <div class="info-value" id="next-station">-</div>
                    </div>
                    <div class="train-info">
                        <div class="info-label">Vitesse actuelle</div>
                        <div class="info-value" id="current-speed">0 km/h</div>
                    </div>
                </div>
            </div>
            <!-- Station Info Panel -->

            <div id="station-info-panel" class="station-info-panel">
        <button class="close-panel" onclick="hideStationInfo()">&times;</button>
        <div id="station-photo-container">
            <img id="station-photo" src="" alt="Photo de la gare">
        </div>
        <h3 id="station-name">Nom de la station</h3>
        <div class="station-info-row">
            <span class="station-info-label">Type:</span>
            <span class="station-info-value" id="station-type">Gare</span>
        </div>
        <div class="station-info-row">
            <span class="station-info-label">Secteur:</span>
            <span class="station-info-value" id="station-sector">Nord</span>
        </div>
        <div class="station-info-row">
            <span class="station-info-label">Prochain train:</span>
            <span class="station-info-value" id="station-next-train">-</span>
        </div>
        <div class="station-info-row">
            <span class="station-info-label">Heure arrivée:</span>
            <span class="station-info-value" id="station-arrival">-</span>
        </div>
        <div class="station-info-row">
            <span class="station-info-label">Heure départ:</span>
            <span class="station-info-value" id="station-departure">-</span>
        </div>
        <div id="station-additional-info" style="margin-top: 10px; font-size: 14px;"></div>
        <div style="margin-top: 15px; display: flex; justify-content: space-between;">
            <button class="btn btn-small" onclick="showEditStationModalForCurrent()">
                <i class="fas fa-edit"></i> Modifier
            </button>
            <button class="btn btn-small btn-danger" onclick="confirmDeleteCurrentStation()">
                <i class="fas fa-trash-alt"></i> Supprimer
            </button>
        </div>
    </div>

            <!-- Map Controls Section -->
            <div class="menu-section">
                <div class="menu-title" onclick="toggleMenu('map-controls')">
                    <span><i class="fas fa-map-marked-alt"></i> Contrôles de la Carte</span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="menu-items" id="map-controls">
                    <a href="#" class="menu-item" onclick="toggleMarkers(); return false;">
                        <i class="fas fa-map-pin"></i> Afficher/Masquer les gares
                    </a>
                    <a href="#" class="menu-item" onclick="toggleRailwayMenu(); return false;">
                        <i class="fas fa-route"></i> Afficher les lignes ferroviaires
                    </a>
                </div>
            </div>
        </div>
    </div>
    <!-- Navbar -->
    <div class="navbar">
        <div class="datetime" id="datetime"></div>
        <input type="text" id="search-input" placeholder="Rechercher une gare..." onkeyup="searchStation()" />
        <div class="icons">
            <button class="icon-button" onclick="showAbout()">
                <i class="fas fa-info-circle"></i>
            </button>
            <button class="icon-button" onclick="toggleMarkers()">
                <i class="fas fa-map-pin"></i>
            </button>
            <button class="icon-button" onclick="toggleRailwayMenu()">
                <i class="fas fa-route"></i>
            </button>
            <a href="client-dashboard.php" class="icon-button">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
    <!-- Map -->
    <div id="map"></div>
    
    <!-- Railway Menu -->
    <div id="railway-menu">
        <button class="railway-btn" onclick="toggleRailway('blue')">Tunis-Bizerte</button>
        <button class="railway-btn" onclick="toggleRailway('red')">Tunis-Gobaa</button>
        <button class="railway-btn" onclick="toggleRailway('green')">Tunis-Ghardimaou</button>
        <button class="railway-btn" onclick="toggleRailway('black')">Tunis-Cedria</button>
        <button class="railway-btn" onclick="toggleRailway('aqua')">Tunis-Dahmani</button>
        <button class="railway-btn" onclick="toggleRailway('yellow')">Tunis-Nabeul</button>
        <button class="railway-btn" onclick="toggleRailway('purple')">Tunis-Sousse</button>
        <button class="railway-btn" onclick="toggleRailway('brown')">Tunis Bougatfa</button>
    </div>
    <!-- About Modal -->
    <div class="modal-overlay" id="modal-overlay" onclick="hideAbout()"></div>
    <div class="modal" id="about-modal">
        <div class="modal-header">
            <div class="modal-title">À Propos</div>
            <button class="modal-close" onclick="hideAbout()">&times;</button>
        </div>
        <p>Ce site permet de localiser différentes trains et gares ferroviaires en Tunisie et d'afficher leurs informations sur une carte interactive.</p>
        <p>Les données affichées proviennent de sources publiques et sont mises à jour régulièrement.</p>
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-primary" onclick="hideAbout()">Fermer</button>
        </div>
    </div>
     <!-- Dashboard Modal -->
    <div class="modal" id="dashboard-modal">
        <div class="modal-header">
            <div class="modal-title">Tableau de Bord SNCFT</div>
            <button class="modal-close" onclick="hideDashboard()">&times;</button>
        </div>
        <div class="dashboard-content">
            <div class="metrics-container">
                <div class="metric-card">
                    <div class="metric-value" id="total-stations">0</div>
                    <div class="metric-label">Gares Ferroviaires</div>
                    <div class="metric-icon"><i class="fas fa-train"></i></div>
                </div>
                <div class="metric-card">
                    <div class="metric-value" id="active-trains">0</div>
                    <div class="metric-label">Trains en Circulation</div>
                    <div class="metric-icon"><i class="fas fa-subway"></i></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal pour ajouter une gare -->
    <div class="modal" id="add-station-modal">
        <div class="modal-header">
            <div class="modal-title">Ajouter une nouvelle gare</div>
            <button class="modal-close" onclick="hideAddStationModal()">&times;</button>
        </div>
        <div style="padding: 15px;">
            <div class="form-group">
                <label for="station-name">Nom de la gare:</label>
                <input type="text" id="station-name-input" class="form-control" placeholder="Entrez le nom de la gare">
            </div>
            <div class="form-group">
                <label for="station-type">Type de gare:</label>
                <select id="station-type-input" class="form-control">
                    <option value="Gare principale">Gare principale</option>
                    <option value="Station">Station</option>
                </select>
            </div>
            <div class="form-group">
                <label for="station-sector">Secteur:</label>
                <select id="station-sector-input" class="form-control">
                    <option value="Tunis-Bizerte">Tunis-Bizerte</option>
                    <option value="Tunis-Gobaa">Tunis-Gobaa</option>
                    <option value="Tunis-Ghardimaou">Tunis-Ghardimaou</option>
                    <option value="Tunis-Cedria">Tunis-Cedria</option>
                    <option value="Tunis-Dahmani">Tunis-Dahmani</option>
                    <option value="Tunis-Nabeul">Tunis-Nabeul</option>
                    <option value="Tunis-Sousse">Tunis-Sousse</option>
                    <option value="Tunis Bougatfa">Tunis Bougatfa</option>
                </select>
            </div>
            <div class="form-group">
                <label for="station-coordinates">Coordonnées (Latitude, Longitude):</label>
                <div style="display: flex; gap: 10px;">
                    <input type="number" step="any" id="station-latitude-input" class="form-control" placeholder="Latitude">
                    <input type="number" step="any" id="station-longitude-input" class="form-control" placeholder="Longitude">
                </div>
            </div>
            <div class="form-group">
                <label for="station-photo">Photo de la gare:</label>
                <input type="file" id="station-photo-input" class="form-control" accept="image/*" onchange="previewPhoto('station-photo-input', 'add-photo-preview')">
                <img id="add-photo-preview" class="photo-preview" alt="Aperçu de la photo">
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <button class="btn btn-secondary" onclick="hideAddStationModal()"><i class="fas fa-times"></i> Annuler</button>
                <button class="btn btn-primary" onclick="addStation()"><i class="fas fa-plus-circle"></i> Ajouter</button>
            </div>
        </div>
    </div>

<!-- Modal pour supprimer une gare -->
<div class="modal" id="delete-station-modal">
    <div class="modal-header">
        <div class="modal-title">Supprimer une gare</div>
        <button class="modal-close" onclick="hideDeleteStationModal()">&times;</button>
    </div>
    <div style="padding: 15px;">
        <div class="form-group">
            <label for="delete-station-name">Sélectionnez la gare à supprimer:</label>
            <select id="delete-station-name" class="form-control">
                <!-- Les options seront ajoutées dynamiquement -->
            </select>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 20px;">
            <button class="btn btn-secondary" onclick="hideDeleteStationModal()">
                <i class="fas fa-times"></i> Annuler
            </button>
            <button class="btn btn-danger" onclick="deleteStation()">
                <i class="fas fa-trash-alt"></i> Supprimer
            </button>
        </div>
    </div>
</div>

<div class="modal" id="edit-station-modal">
        <div class="modal-header">
            <div class="modal-title">Modifier une gare</div>
            <button class="modal-close" onclick="hideEditStationModal()">&times;</button>
        </div>
        <div style="padding: 15px;">
            <div class="form-group">
                <label for="edit-station-name">Sélectionnez la gare à modifier:</label>
                <select id="edit-station-name" class="form-control" onchange="loadStationData()">
                    <!-- Les options seront ajoutées dynamiquement -->
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit-station-new-name">Nouveau nom:</label>
                <input type="text" id="edit-station-new-name" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="edit-station-type">Type de gare:</label>
                <select id="edit-station-type" class="form-control">
                    <option value="Gare principale">Gare principale</option>
                    <option value="Station">Station</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit-station-sector">Secteur:</label>
                <select id="edit-station-sector" class="form-control">
                    <option value="Tunis-Bizerte">Tunis-Bizerte</option>
                    <option value="Tunis-Gobaa">Tunis-Gobaa</option>
                    <option value="Tunis-Ghardimaou">Tunis-Ghardimaou</option>
                    <option value="Tunis-Cedria">Tunis-Cedria</option>
                    <option value="Tunis-Dahmani">Tunis-Dahmani</option>
                    <option value="Tunis-Nabeul">Tunis-Nabeul</option>
                    <option value="Tunis-Sousse">Tunis-Sousse</option>
                    <option value="Tunis Bougatfa">Tunis Bougatfa</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Photo actuelle:</label>
                <div id="current-photo-container" style="margin: 5px 0;">
                    <p>Aucune photo disponible</p>
                </div>
                <label for="edit-station-photo">Changer la photo:</label>
                <input type="file" id="edit-station-photo-input" class="form-control" accept="image/*" onchange="previewPhoto('edit-station-photo-input', 'edit-photo-preview')">
                <img id="edit-photo-preview" class="photo-preview" alt="Aperçu de la nouvelle photo">
            </div>
            
            <div class="form-group">
                <label>Coordonnées actuelles:</label>
                <div id="current-coords" style="margin: 5px 0; font-style: italic;"></div>
                <button class="btn btn-small" onclick="updateStationCoords()">
                    <i class="fas fa-map-marker-alt"></i> Mettre à jour les coordonnées
                </button>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <button class="btn btn-secondary" onclick="hideEditStationModal()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button class="btn btn-primary" onclick="updateStation()">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>

<div class="modal" id="history-modal">
    <div class="modal-header">
        <div class="modal-title">Historique des modifications</div>
        <button class="modal-close" onclick="hideHistoryModal()">&times;</button>
    </div>
    <div style="padding: 15px;">
        <div id="history-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 15px;">
            <!-- Les entrées d'historique seront ajoutées ici -->
        </div>
        <button class="btn btn-primary" onclick="exportHistoryToPDF()">
            <i class="fas fa-download"></i> Exporter en PDF
        </button>
    </div>
</div>
<!-- Modal pour la liste des conducteurs -->
<div class="modal" id="driver-list-modal">
    <div class="modal-header">
        <div class="modal-title">Liste des Conducteurs</div>
        <button class="modal-close" onclick="hideDriverList()">&times;</button>
    </div>
    <div class="modal-body">
        <!-- Barre de recherche -->
        <div class="search-bar">
            <input type="text" id="driver-search-input" placeholder="Rechercher un conducteur..." onkeyup="filterDrivers()" />
            <i class="fas fa-search"></i>
        </div>

        <!-- Tableau des conducteurs -->
        <div class="table-container">
            <table id="driver-table">
                <thead>
                    <tr>
                        <th>Nom d'utilisateur</th>
                        <th>Email</th>
                        <th>Date d'inscription</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="driver-list-container">
                    <!-- Les entrées des conducteurs seront ajoutées ici dynamiquement -->
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="hideDriverList()">
            <i class="fas fa-times"></i> Fermer
        </button>
        <button class="btn btn-primary" onclick="exportDriverListToPDF()">
            <i class="fas fa-download"></i> Exporter en PDF
        </button>
    </div>
</div>
<div class="modal" id="add-driver-modal">
    <div class="modal-header">
        <div class="modal-title">Ajouter un conducteur</div>
        <button class="modal-close" onclick="hideAddDriverModal()">&times;</button>
    </div>
    <div style="padding: 15px;">
        <div class="form-group">
            <label for="driver-username">Nom d'utilisateur:</label>
            <input type="text" id="driver-username-input" class="form-control" placeholder="Entrez le nom d'utilisateur">
        </div>
        <div class="form-group">
            <label for="driver-password">Mot de passe:</label>
            <input type="password" id="driver-password-input" class="form-control" placeholder="Entrez le mot de passe">
        </div>
        <div class="form-group">
            <label for="driver-email">Email:</label>
            <input type="email" id="driver-email-input" class="form-control" placeholder="Entrez l'email">
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 20px;">
            <button class="btn btn-secondary" onclick="hideAddDriverModal()">
                <i class="fas fa-times"></i> Annuler
            </button>
            <button class="btn btn-primary" onclick="addDriver()">
                <i class="fas fa-plus-circle"></i> Ajouter
            </button>
        </div>
    </div>
</div>
<div class="modal" id="delete-driver-modal">
    <div class="modal-header">
        <div class="modal-title">Supprimer un conducteur</div>
        <button class="modal-close" onclick="hideDeleteDriverModal()">&times;</button>
    </div>
    <div style="padding: 15px;">
        <div class="form-group">
            <label for="delete-driver-name">Sélectionnez le conducteur à supprimer:</label>
            <select id="delete-driver-name" class="form-control">
                <!-- Les options seront ajoutées dynamiquement -->
            </select>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 20px;">
            <button class="btn btn-secondary" onclick="hideDeleteDriverModal()">
                <i class="fas fa-times"></i> Annuler
            </button>
            <button class="btn btn-danger" onclick="deleteDriver()">
                <i class="fas fa-trash-alt"></i> Supprimer
            </button>
        </div>
    </div>
</div>
<!-- Modal pour filtrer les trains -->
<div class="modal" id="filter-train-modal">
    <div class="modal-header">
        <div class="modal-title">Filtrer les Trains</div>
        <button class="modal-close" onclick="hideFilterTrainModal()">&times;</button>
    </div>
    <div style="padding: 15px;">
        <div class="form-group">
            <label for="train-type-filter">Filtrer par type de train:</label>
            <select id="train-type-filter" class="form-control" onchange="filterTrainsByType()">
                <option value="all">Tous les types</option>
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
        <div id="filtered-trains-container" style="margin-top: 15px;">
            <!-- Les trains filtrés seront affichés ici -->
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
            <button class="btn btn-secondary" onclick="hideFilterTrainModal()">
                <i class="fas fa-times"></i> Fermer
            </button>
        </div>
    </div>
</div>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Variables globales
        var map;
        var markers = [];
        var stations = [];
        var railwayLines = {};
        var railwayPolylines = {};
        var trainMarker;
        var trainAnimationInterval;
        var currentTrainPositionIndex = 0;
        var currentTrainLine = [];
        var currentTrainSpeed = 60;
        var isTrainMoving = false;
        var stationHistory = [];
        
        // Initialisation de l'application
        function init() {
            initMap();
            loadStations();
            loadRailwayLines();
            updateDateTime();
            setInterval(updateDateTime, 1000);
            initTrainInfo();
            window.selectedTrain = null; // Initialiser la variable
            initMapClickForCoordinates(); // Ajoutez cette ligne
        }
        
        // Initialisation de la carte
        function initMap() {
            map = L.map('map').setView([36.8083, 10.1528], 12);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            map.on('click', function() {
    hideStationInfo();
});
        }
        function loadStations() {
    // Essayez de charger depuis le localStorage
    const savedStations = loadStationsFromLocalStorage();
    
    if (savedStations && savedStations.length > 0) {
        stations = savedStations;
    } else {
        // Sinon, chargez les stations par défaut
        stations = [
            { name: 'Gare de Tunis', coords: [36.7950, 10.1805], info: 'Place de Barcelone', sector: 'Tunis', type: 'Gare principale' },
            { name: 'Gare de Manouba', coords: [36.8159, 10.1012], info: 'Manouba', sector: 'Tunis-Bizerte Tunis-Ghardimaou', type: 'Gare principale' },
 { name: 'Gare de Jdaida', coords: [36.8509, 9.9332], info: 'Jdaida', sector: 'Tunis-Bizerte Tunis-Ghardimaou', type: 'Station' },
 { name: 'Gare de Tinja', coords: [37.1595, 9.7575], info: 'Tinja' , sector: 'Bizerte', type: 'Station' },
 { name: 'Gare de Chawat', coords: [36.8900, 9.9445], info: 'Chawat' , sector: 'Bizerte', type: 'Station' },
{ name: 'Gare de Mateur', coords: [37.0380, 9.6845], info: 'Mateur', sector: 'Bizerte', type: 'Station' },
{ name: 'Gare de Sidi Othmen', coords: [36.9590, 9.9250], info: 'Sidi othmen' , sector: 'Bizerte', type: 'Station'},
{ name: 'Gare de Ain Ghlel', coords: [37.0230, 9.8345], info: 'Ain Ghlel', sector: 'Bizerte', type: 'Station' },
{ name: 'Gare de Bizerte', coords: [37.2660, 9.8660], info: 'Bizerte', sector: 'Bizerte', type: 'Station' },
{ name: 'Gare de Béja', coords: [36.7250, 9.1900], info: 'Béja', sector: 'Tunis-Ghardimaou', type: 'Gare principale' },
{ name: 'Gare de Tebourba', coords: [36.8290, 9.8450], info: 'Tebourba', sector: 'Tunis-Ghardimaou', type: 'Station' },
 { name: 'Gare de Jendouba', coords: [36.5011, 8.7770], info: 'Jendouba', sector: 'Tunis-Ghardimaou', type: 'Gare principale' },
{ name: 'Gare de Ghardimaou', coords: [36.4480, 8.4370], info: 'Ghardimaou' ,sector: 'Tunis-Ghardimaou', type: 'Gare principale' },
 { name: 'Gare de Bourj Toumi', coords: [36.7565, 9.7200], info: 'Bourj Toumi', sector: 'Tunis-Ghardimaou', type: 'Station' },
 { name: 'Gare de Oued Zarga', coords: [36.6730, 9.4260], info: 'Oued Zarga' , sector: 'Tunis-Ghardimaou', type: 'Station' },
 { name: 'Gare de Oued Mliz', coords: [36.4690, 8.5497], info: 'Oued Mliz', sector: 'Tunis-Ghardimaou', type: 'Station' },
{ name: 'Gare de Medjez el Bab', coords: [36.6650, 9.6060], info: 'Medjez el Bab' , sector: 'Tunis-Ghardimaou', type: 'Station' },
 { name: 'Gare de Sousse', coords: [35.8300, 10.6385], info: 'Sousse' },
 { name: 'Gare de Mahdia', coords: [35.5005, 11.0640], info: 'Mahdia' },
 { name: 'Gare de Sfax', coords: [34.7350, 10.7667], info: 'Sfax' },
 { name: 'Gare de Gabès', coords: [33.8840, 10.1000], info: 'Gabès' },
 { name: 'Gare de Gafsa', coords: [34.3940, 8.8030], info: 'Gafsa' },
 { name: 'Gare de Nabeul', coords: [36.4510, 10.735], info: 'Nabeul' },
 { name: 'Gare du Kef', coords: [36.1667, 8.7040], info: 'Le Kef' },
{ name: 'Gare de Dahmani', coords: [35.9452, 8.8290], info: 'Dahmani' },
 { name: 'Gare de Kalâa Khasba', coords: [35.6600, 8.5910], info: 'Kalâa Khasba' },
 { name: 'Gare de Gaâfour', coords: [36.3220, 9.3260], info: 'Gaâfour' },
 { name: 'Station Eraouadha', coords: [36.8010, 10.1480], info: 'Eraouadha' },
 { name: 'Station Le Bardo', coords: [36.8070, 10.1350], info: 'Le Bardo' },
{ name: 'Station Mellacine', coords: [36.7960, 10.1559], info: 'Mellacine' },
 { name: 'Station Sida Manoubia', coords: [36.7866, 10.1654], info: 'Saida Manoubia' },
 { name: 'Station Les orangers', coords: [36.8180, 10.0855], info: 'Les orangers' },
 { name: 'Station Gobaa', coords: [36.8217, 10.0640], info: 'Gobaa' },
 { name: 'Station Jebel Jelloud', coords: [36.7720, 10.2090], info: 'Jebel Jelloud' },
 { name: 'Gare de Radès', coords: [36.7685, 10.2710], info: 'Radès' },
{ name: 'Station Ezzahra', coords: [36.7490, 10.3041], info: 'Ezzahra' },
 { name: 'Gare de Hammam Lif', coords: [36.7290, 10.3367], info: 'Hammam Lif' },
 { name: 'Station Borj Cédria', coords: [36.7037, 10.3970], info: 'Borj Cédria' },
 { name: 'Gare de Bir Bouregba', coords: [36.4301, 10.5743], info: 'Bir Bouregba' },
 { name: 'Gare de Bou Argoub', coords: [36.5309, 10.5515], info: 'Bou Argoub' },
 { name: 'Gare de Hammamet', coords: [36.4073, 10.6128], info: 'Hammamet' },
 { name: 'Gare de Grombalia', coords: [36.5959, 10.4980], info: 'Grombalia' },
{ name: "Station Cité Eriadh", coords: [36.6995, 10.4150], info: 'Cité Eriadh'},
{ name: "Station Kalaa Kobra", coords: [35.8680, 10.5530], info: 'Kalaa Kobra'},
 { name: "Station Naassen", coords: [36.7030, 10.2325], info: 'Nassen'},
 { name: "Station Bir Kassa", coords: [36.7399, 10.2290], info:'Bir Kassa'},
 { name: "Station Cheylus", coords: [36.5553, 10.0600], info: 'Cheylus'},
 { name: "Station Pont de Fahs", coords: [36.3765, 9.9020], info: 'Pont de Fahs' },
 { name: "Station Bou Arada", coords: [36.3570, 9.6260], info :'Bou Arada' },
 { name: "Station Sidi Bourouis", coords: [36.1751, 9.1250], info :'Sidi Bourouis' },
 { name: "Station Sidi Ayad", coords: [36.3511, 9.3910], info : 'Sidi Ayad' },
 { name: "Station Le Sers", coords: [36.0759, 9.0232], info :'Le Sers' },
{ name: "Station El aroussa", coords: [36.3810, 9.4547], info :'El aroussa' },
 { name: 'Gare de Le Krib', coords: [36.2540, 9.1860], info: 'Le Krib' },
 { name: 'Gare de Le khwet', coords: [36.2550, 9.2548], info: 'Le Khwet' },
{ name: "Les Zouarines", coords: [36.0230, 8.9029], info:'Les Zouarines' },
 { name: "Ain Masria", coords: [35.9122, 8.7393], info: 'Ain Masria' },
{ name: "Oudna", coords: [36.6253, 10.1510], info:'Oudna' },
 { name: "Khlidia", coords: [36.6462, 10.1945], info:'Khlidia' },
{ name: "depot", coords: [36.7875, 10.1897]},
{ name: "Gare Megrine", coords: [36.7683, 10.2340], info:'Megrine' },
 { name: "Gare de Fondok Jdid", coords: [36.6690, 10.4464] },
 { name: "Samech", coords: [36.6253, 10.4611], info:'Samech' },
 { name: "Bouficha", coords: [36.3025, 10.4510], info:'Bouficha' },
{ name: "Enfidha", coords: [36.1305, 10.3836], info:'Enfidha'},
 { name: "Gare Kalaa Soghra", coords: [35.8218, 10.5715], info:'Kalaa Soghra' },
 {name: "Smenja", coords:[36.4573, 10.0235], info:'Smenja'},
 { name: "Enajah", coords: [36.7930, 10.1547], info: 'Enajah' },
 { name: "Tayaran (Zouhour 1)", coords: [36.7920, 10.1390], info: 'Tayaran (Zouhour 1)' },
{ name: "Zouhour 2", coords: [36.7878, 10.1276], info: 'Zouhour 2' },
{ name: "Hrayriya", coords: [36.7840, 10.1155], info: 'Hrayriya' },
{ name: "Bougatfa (Sidi Hssine)", coords: [36.7800, 10.1019], info: 'Bougatfa (Sidi Hssine)' },
 ];
    }

    // Créez les marqueurs pour toutes les stations
    stations.forEach(function(station) {
        var marker = L.circleMarker(station.coords, {
            radius: 6,
            fillColor: "#3498db",
            color: "#fff",
            weight: 1,
            opacity: 1,
            fillOpacity: 0.8
        }).bindPopup('<b>' + station.name + '</b><br>' + station.info);
        
        marker.on('click', function() {
            showStationInfo(station);
        });
        
        markers.push(marker);
        marker.addTo(map);
    });
}
        

        
        // Chargement des lignes ferroviaires
        function loadRailwayLines() {
            railwayLines = {
                blue: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1805],
                    [36.7866, 10.1754], 
                    [36.7850, 10.1700],
                    [36.7866, 10.1654], //Saida
                    [36.7900, 10.1632],
                    [36.7960, 10.1559], //malacine
                    [36.8010, 10.1480], //eraouadha
                    [36.8070, 10.1350], //bardo
                    [36.8159, 10.1012], // Gare de Manouba
                    [36.8180, 10.0855], //les orangers
                    [36.8217, 10.0640], //terminus gobaa
                    [36.8509, 9.9332],  // Gare de Jdaida
                    [36.8900, 9.9445],  // Gare de Chawat
                    [36.9590, 9.9250],  
                    [37.0230, 9.8345],  // 7 3wiet
                    [37.0200, 9.8000],
                    [37.0080, 9.7500],
                    [37.0380, 9.6845],  // Gare de Mateur
                    [37.1595, 9.7575],  // Gare de Tinja
                    [37.2450, 9.8117],   // Gare de Msida
                    [37.2660, 9.8660]   // Gare de Bizerte
                ],
                red: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1805],
                    [36.7866, 10.1754], 
                    [36.7850, 10.1700],
                    [36.7866, 10.1654], //Saida
                    [36.7900, 10.1632],
                    [36.7960, 10.1559], //malacine
                    [36.8010, 10.1480], //eraouadha
                    [36.8070, 10.1350], //bardo
                    [36.8159, 10.1012], // Gare de Manouba
                    [36.8180, 10.0855], //les orangers
                    [36.8217, 10.0640], //gobaa
                ],
                green: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1805],
                    [36.7866, 10.1754], 
                    [36.7850, 10.1700],
                    [36.7866, 10.1654], //Saida
                    [36.7900, 10.1632],
                    [36.7960, 10.1559], //malacine
                    [36.8010, 10.1480], //eraouadha
                    [36.8070, 10.1350], //bardo
                    [36.8159, 10.1012], // Gare de Manouba
                    [36.8180, 10.0855], //les orangers
                    [36.8217, 10.0640], //terminus gobaa
                    [36.8509, 9.9332],  // Gare de Jdaida
                    [36.8290, 9.8450], //tborba
                    [36.7565, 9.7200],
                    [36.6650, 9.6060],
                    [36.6730, 9.4260], //oued zarga
                    [36.7000, 9.4000],
                    [36.7700, 9.3060],
                    [36.7585, 9.2060],
                    [36.7250, 9.1900], //beja
                    [36.6000, 9.1900],
                    [36.5800, 9.0000],
                    [36.6000, 8.8900],
                    [36.5011, 8.7770], //jendouba
                    [36.4485, 8.6700],
                    [36.4690, 8.5497], //oued mliz
                    [36.4480, 8.4370], //ghardimaou
                ],
                black: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1815],
                    [36.7875, 10.1897],
                    [36.7720, 10.2090], //jbal jloud
                    [36.7683, 10.2340],
                    [36.7685, 10.2710], //rades
                    [36.7490, 10.3041], //ezzahra
                    [36.7290, 10.3367], //hammam lif
                    [36.7030, 10.4000], //borj cedria
                    [36.6995, 10.4150]
                ],
                aqua: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1815],
                    [36.7875, 10.1897],
                    [36.7720, 10.2090], // Jbal Jloud
                    [36.7399, 10.2290], // Bir Kassa
                    [36.7030, 10.2325], // Nassen
                    [36.6462, 10.1945], //khlidia
                    [36.6253, 10.1510], //oudna
                    [36.5553, 10.0600], // Cheylus
                    [36.5303, 10.0215],
                    [36.4573, 10.0235],//smenja
                    [36.3765, 9.9020],  // Fahs
                    [36.3570, 9.6260],  // Bouarada
                    [36.3810, 9.4547],
                    [36.3511, 9.3910], //sidiayad
                    [36.3220, 9.3260],  // Gaafour
                    [36.2550, 9.2548], //khwet
                    [36.2540, 9.1860],
                    [36.1751, 9.1250], //sidi bourouis
                    [36.0759, 9.0232],  // Sers
                    [36.0230, 8.9029],
                    [35.9452, 8.8290],  // Dahmani
                ],
                yellow: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1815],
                    [36.7875, 10.1897],
                    [36.7866, 10.1902],
                    [36.7720, 10.2090], //jbal jloud
                    [36.7683, 10.2340],
                    [36.7685, 10.2710], //rades
                    [36.7490, 10.3041], //ezzahra
                    [36.7290, 10.3367], //hammam lif
                    [36.7030, 10.4000], //borj cedria
                    [36.6690, 10.4464], //fondok jdid
                    [36.6253, 10.4611], //samech
                    [36.5959, 10.4980],//grombalia
                    [36.5309, 10.5515],//bouargoub
                    [36.4301, 10.5743],//bir bouregba
                    [36.4073, 10.6128],//hammamet
                    [36.4510, 10.735],//nabeul
                ],
                purple: [
                    [36.7950, 10.1805], // Gare de Tunis
                    [36.7900, 10.1815],
                    [36.7875, 10.1897],
                    [36.7866, 10.1902],
                    [36.7720, 10.2090], //jbal jloud
                    [36.7683, 10.2340],
                    [36.7685, 10.2710], //rades
                    [36.7490, 10.3041], //ezzahra
                    [36.7290, 10.3367], //hammam lif
                    [36.7030, 10.4000], //borj cedria
                    [36.6690, 10.4464], //fondok jdid
                    [36.6253, 10.4611], //samech
                    [36.5959, 10.4980],//grombalia
                    [36.5309, 10.5515],//bouargoub
                    [36.4301, 10.5743],//bir bouregba
                    [36.3025, 10.4510],//bouficha
                    [36.1305, 10.3836],//enfidha
                    [35.8680, 10.5530],//kalaa kobra
                    [35.8218, 10.5715],//kalaa soghra
                    [35.8300, 10.6385],//sousse
                ],
                brown: [
        [36.7950, 10.1805], // Gare de Tunis
        [36.7900, 10.1805],
        [36.7866, 10.1754], 
        [36.7850, 10.1705],
        [36.7866, 10.1654], // Saida Manoubia
        [36.7900, 10.1632],
        [36.7930, 10.1547], // Enajah
        [36.7940, 10.1530],
        [36.7947, 10.1490],
        [36.7920, 10.1390], // Tayaran (Zouhour 1)
        [36.7878, 10.1276], // Zouhour 2
        [36.7840, 10.1155], // Hrayriya
        [36.7800, 10.1019]  // Bougatfa (Sidi Hssine)
    ],
            };
            
            // Création des polylignes pour chaque ligne
            for (var color in railwayLines) {
                railwayPolylines[color] = L.polyline(railwayLines[color], {
                    color: color,
                    weight: 4,
                    opacity: 0.7,
                    dashArray: '10, 10'
                });
            }
        }
        
        // Afficher/masquer les marqueurs des gares
        function toggleMarkers() {
            if (map.hasLayer(markers[0])) {
                markers.forEach(marker => map.removeLayer(marker));
            } else {
                markers.forEach(marker => marker.addTo(map));
            }
        }
        
        // Afficher/masquer le menu des lignes ferroviaires
        function toggleRailwayMenu() {
            var menu = document.getElementById('railway-menu');
            menu.classList.toggle('active');
        }
        
        // Afficher/masquer une ligne ferroviaire spécifique
        function toggleRailway(color) {
            if (railwayPolylines[color]) {
                if (map.hasLayer(railwayPolylines[color])) {
                    map.removeLayer(railwayPolylines[color]);
                } else {
                    railwayPolylines[color].addTo(map);
                }
            }
        }
        
        // Rechercher une gare
        function searchStation() {
            var input = document.getElementById('search-input').value.toLowerCase();
            var found = stations.find(station => station.name.toLowerCase().includes(input));
            
            if (found) {
                map.setView(found.coords, 15);
                
                // Ouvrir le popup de la gare trouvée
                markers.forEach(marker => {
                    if (marker.getLatLng().equals(found.coords)) {
                        marker.openPopup();
                    }
                });
            }
        }
        
        // Afficher/masquer la sidebar
        function toggleSidebar() {
            var sidebar = document.querySelector('.sidebar');
            var map = document.getElementById('map');
            var navbar = document.querySelector('.navbar');
            
            if (sidebar.style.transform === 'translateX(-300px)') {
                sidebar.style.transform = 'translateX(0)';
                map.style.left = '300px';
                navbar.style.left = '300px';
            } else {
                sidebar.style.transform = 'translateX(-300px)';
                map.style.left = '0';
                navbar.style.left = '0';
            }
        }
        
        // Afficher/masquer un sous-menu
        function toggleMenu(menuId) {
            var menu = document.getElementById(menuId);
            menu.classList.toggle('active');
            
            // Animer l'icône de chevron
            var chevron = menu.previousElementSibling.querySelector('.fa-chevron-down');
            chevron.classList.toggle('rotate');
        }
        
        // Afficher la modal "À propos"
        function showAbout() {
            document.getElementById('modal-overlay').classList.add('active');
            document.getElementById('about-modal').classList.add('active');
        }
        
        // Masquer la modal "À propos"
        function hideAbout() {
            document.getElementById('modal-overlay').classList.remove('active');
            document.getElementById('about-modal').classList.remove('active');
        }
        // Afficher la modal Dashboard
// Fonctions Dashboard
function showDashboard() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('dashboard-modal').classList.add('active');
    updateDashboardMetrics();
}

function hideDashboard() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('dashboard-modal').classList.remove('active');
}

// Mettre à jour les positions des conducteurs périodiquement et mettre à jour le dashboard
function updateDriverPositionsAndDashboard() {
    updateDriverPositions().then(activeDrivers => {
        // Mettre à jour le compteur dans le dashboard
        document.getElementById('active-trains').textContent = activeDrivers;
        
        // Si le dashboard est ouvert, rafraîchir toutes les métriques
        if (document.getElementById('dashboard-modal').classList.contains('active')) {
            updateDashboardMetrics();
        }
    });
}

// Remplacer l'ancien setInterval par le nouveau
setInterval(updateDriverPositionsAndDashboard, 5000);

// Charger les positions au démarrage
updateDriverPositionsAndDashboard();

function updateDashboardMetrics() {
    // Mettre à jour les métriques
    document.getElementById('total-stations').textContent = stations.length;
    
    // Le nombre de trains actifs est maintenant géré par updateDriverPositionsAndDashboard
    
    // Statistiques factices (à remplacer par des données réelles)
    document.getElementById('departures-today').textContent = Math.floor(Math.random() * 50) + 20;
    document.getElementById('delayed-trains').textContent = Math.floor(Math.random() * 5);
    document.getElementById('on-time').textContent = (100 - Math.floor(Math.random() * 5)) + '%';
    
    // Initialiser le graphique (si Chart.js est inclus)
    if (typeof Chart !== 'undefined') {
        initTrainTypeChart();
    }
}

function initTrainTypeChart() {
    var ctx = document.getElementById('trainTypeChart').getContext('2d');
    var chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['GT26-101', 'GT26-102', 'GT26-103', 'GT26-104', 'GT26-105', 'GT26-106', 'GT26-107', 'GT26-108', 'CC21000-201', 'CC21000-202', 'CC21000-203', 'BB1200-301', 'BB1200-302', 'BB1200-303', 'BB1200-304'],
            datasets: [{
                data: [35, 25, 15, 10, 10, 5],
                backgroundColor: [
                    '#3498db',
                    '#e74c3c',
                    '#2ecc71',
                    '#f39c12',
                    '#9b59b6',
                    '#1abc9c'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: {
                position: 'right'
            }
        }
    });
}
        
        // Mettre à jour la date et l'heure
        function updateDateTime() {
            var now = new Date();
            var options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            document.getElementById('datetime').textContent = now.toLocaleDateString('fr-FR', options);
        }
        // Fonctions pour la communication
function openCommunicationPanel() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('communication-modal').classList.add('active');
    
    // Charger les messages existants
    fetchMessages();
}

function hideCommunicationPanel() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('communication-modal').classList.remove('active');
}

function sendMessage() {
    var message = document.getElementById('message-input').value;
    if (!message.trim()) return;
    
    // Ajouter le message à l'interface immédiatement
    addMessageToInterface('Vous', message, new Date());
    
    // Envoyer le message au serveur
    fetch('send.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'sender=control&message=' + encodeURIComponent(message)
    })
    .then(response => response.text())
    .then(data => {
        console.log('Message sent:', data);
        document.getElementById('message-input').value = '';
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function fetchMessages() {
    fetch('get.php?receiver=control')
    .then(response => response.json())
    .then(messages => {
        var container = document.getElementById('messages-container');
        container.innerHTML = '';
        
        messages.forEach(msg => {
            addMessageToInterface(msg.sender, msg.message, new Date(msg.timestamp));
        });
    })
    .catch(error => {
        console.error('Error fetching messages:', error);
    });
}

function addMessageToInterface(sender, message, timestamp) {
    var container = document.getElementById('messages-container');
    var messageDiv = document.createElement('div');
    messageDiv.style.marginBottom = '10px';
    messageDiv.style.padding = '8px';
    messageDiv.style.backgroundColor = sender === 'Vous' ? '#e3f2fd' : '#f1f1f1';
    messageDiv.style.borderRadius = '5px';
    messageDiv.style.color = 'black'; // ✅ Texte en noir

    var timeStr = timestamp.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    messageDiv.innerHTML = `<strong>${sender}</strong> (${timeStr}):<br>${message}`;

    container.appendChild(messageDiv);
    container.scrollTop = container.scrollHeight;
}
// Variables pour suivre les conducteurs
var driverMarkers = {};

function updateDriverPositions() {
    return fetch('get_positions.php')
        .then(response => response.json())
        .then(drivers => {
            // Supprimer les marqueurs inactifs
            for (var driverId in driverMarkers) {
                if (!drivers.some(d => d.id == driverId)) {
                    map.removeLayer(driverMarkers[driverId]);
                    delete driverMarkers[driverId];
                    if (selectedTrain && selectedTrain.id == driverId) {
                        selectedTrain = null;
                        initTrainInfo(); // Réinitialiser si le train sélectionné est supprimé
                    }
                }
            }

            // Mettre à jour ou créer les marqueurs
            drivers.forEach(driver => {
                var position = [driver.lat, driver.lng];
                
                if (!driverMarkers[driver.id]) {
                    createTrainMarker(driver, position);
                } else {
                    updateExistingMarker(driver, position);
                }

                // Mettre à jour les infos si c'est le train sélectionné
                if (selectedTrain && selectedTrain.id === driver.id) {
                    updateTrainInfo(driver);
                }
            });
            
            return drivers.length;
        })
        .catch(error => {
            console.error('Error:', error);
            return 0;
        });
}
function createTrainMarker(driver, position) {
    var driverIcon = L.divIcon({
        className: 'driver-marker',
        html: '<i class="fas fa-train" style="color: white; font-size: 14px;"></i>',
        iconSize: [26, 26]
    });
    
    driverMarkers[driver.id] = L.marker(position, {
        icon: driverIcon,
        zIndexOffset: 1000
    }).bindPopup(`
        <b>${driver.name}</b><br>
        Type: ${driver.train_type}<br>
        Vitesse: ${driver.speed} km/h<br>
        Dernière mise à jour: ${new Date(driver.timestamp * 1000).toLocaleTimeString()}
    `).addTo(map);
    
    driverMarkers[driver.id].on('click', function() {
        selectedTrain = driver;
        updateTrainInfo(driver);
    });
}

// Nouvelle fonction pour mettre à jour un marqueur existant
function updateExistingMarker(driver, position) {
    driverMarkers[driver.id].setLatLng(position);
    driverMarkers[driver.id].setPopupContent(`
        <b>${driver.name}</b><br>
        Type: ${driver.train_type}<br>
        Vitesse: ${driver.speed} km/h<br>
        Dernière mise à jour: ${new Date(driver.timestamp * 1000).toLocaleTimeString()}
    `);
}

// Fonction améliorée pour mettre à jour les infos du train
function updateTrainInfo(driver) {
    if (!driver) return;
    
    // Mettre à jour toutes les informations
    document.getElementById('train-status').textContent = driver.speed > 0 ? 'En service' : 'Arrêté';
    document.getElementById('current-speed').textContent = `${driver.speed} km/h`;
    
    const nearestStation = findNearestStation([driver.lat, driver.lng]);
    document.getElementById('train-position').textContent = nearestStation ? nearestStation.name : '-';
    
    const nextStation = findNextStation([driver.lat, driver.lng], driver.speed, driver.direction);
    document.getElementById('next-station').textContent = nextStation ? nextStation.name : '-';
}

// Initialisation des infos train
function initTrainInfo() {
    document.getElementById('train-status').textContent = '-';
    document.getElementById('train-position').textContent = '-';
    document.getElementById('next-station').textContent = '-';
    document.getElementById('current-speed').textContent = '-';
}

// Rafraîchissement automatique
setInterval(() => {
    updateDriverPositions().then(count => {
        document.getElementById('active-trains').textContent = count;
    });
}, 5000);

// Fonction pour mettre à jour les informations du train dans la sidebar
function updateTrainInfo(driver) {
    // Mettre à jour le statut du train
    const status = driver.speed > 0 ? 'En service' : 'Arrêté';
    document.getElementById('train-status').textContent = status;
    
    // Mettre à jour la vitesse actuelle
    document.getElementById('current-speed').textContent = `${driver.speed} km/h`;
    
    // Trouver la station la plus proche
    const nearestStation = findNearestStation([driver.lat, driver.lng]);
    document.getElementById('train-position').textContent = nearestStation ? nearestStation.name : '-';
    
    // Trouver la prochaine station (version améliorée)
    const nextStation = findNextStation([driver.lat, driver.lng], driver.speed, driver.direction);
    document.getElementById('next-station').textContent = nextStation ? nextStation.name : '-';
    
    // Stocker les informations du train sélectionné
    window.selectedTrain = driver;
}

// Fonction améliorée pour trouver la prochaine station
function findNextStation(position, speed, direction) {
    if (speed <= 0) return null;
    
    const nearestStation = findNearestStation(position);
    if (!nearestStation) return null;
    
    // Trouver l'index de la station la plus proche
    const nearestIndex = stations.findIndex(s => s.name === nearestStation.name);
    
    // Déterminer la prochaine station selon la direction
    if (direction === 'north' || direction === 'west') {
        return stations[nearestIndex - 1] || null;
    } else {
        return stations[nearestIndex + 1] || null;
    }
}

// Mettre à jour les positions des conducteurs périodiquement
setInterval(updateDriverPositions, 5000);

// Charger les positions au démarrage
updateDriverPositions();

// Vérifier les nouveaux messages périodiquement
setInterval(fetchMessages, 5000); // Toutes les 5 secondes
        
        // Initialiser l'application au chargement
        window.onload = init;
        function tog(menuId) {
    showDashboard(); // Affiche le dashboard au lieu de basculer un menu
}
function showStationInfo(station) {
    const panel = document.getElementById('station-info-panel');
    const now = new Date();
    const photoContainer = document.getElementById('station-photo');
    
    // Afficher la photo si elle existe
    if (station.photo) {
        photoContainer.src = station.photo;
        photoContainer.style.display = 'block';
    } else {
        photoContainer.style.display = 'none';
    }
    
    // Générer des heures aléatoires pour la démo
    const nextHour = new Date(now.getTime() + Math.floor(Math.random() * 60) * 60000);
    const arrivalTime = new Date(nextHour.getTime() - Math.floor(Math.random() * 10) * 60000);
    const departureTime = new Date(nextHour.getTime() + Math.floor(Math.random() * 10) * 60000);
    
    // Mettre à jour le panel avec les informations
    document.getElementById('station-name').textContent = station.name;
    document.getElementById('station-type').textContent = station.type || 'Station';
    document.getElementById('station-sector').textContent = station.sector || 'Tunis';
    document.getElementById('station-next-train').textContent = nextHour.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
    document.getElementById('station-arrival').textContent = arrivalTime.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
    document.getElementById('station-departure').textContent = departureTime.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
    
    // Info supplémentaire
    document.getElementById('station-additional-info').textContent = station.info || "Informations supplémentaires non disponibles";
    
    // Afficher le panel
    panel.style.display = 'block';
}

// Masquer le panel d'information
function hideStationInfo() {
    document.getElementById('station-info-panel').style.display = 'none';
}
// Initialiser les informations du train
function initTrainInfo() {
    document.getElementById('train-status').textContent = 'Inconnu';
    document.getElementById('train-position').textContent = '-';
    document.getElementById('next-station').textContent = '-';
    document.getElementById('current-speed').textContent = '0 km/h';
}
// Rafraîchir les informations du train sélectionné toutes les 5 secondes
setInterval(() => {
    if (window.selectedTrain) {
        // Recharger les positions et mettre à jour les infos
        fetch('get_positions.php')
            .then(response => response.json())
            .then(drivers => {
                const updatedDriver = drivers.find(d => d.id === window.selectedTrain.id);
                if (updatedDriver) {
                    updateTrainInfo(updatedDriver);
                }
            });
    }
}, 5000);
driverMarkers[driver.id].on('click', function() {
    window.selectedTrain = driver; // Stocker le train sélectionné
    updateTrainInfo(driver);
});

// Afficher le modal pour ajouter une gare
function showAddStationModal() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('add-station-modal').style.display = 'block';
}

// Masquer le modal pour ajouter une gare
function hideAddStationModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('add-station-modal').style.display = 'none';
}

// Afficher le modal pour supprimer une gare
function showDeleteStationModal() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('delete-station-modal').style.display = 'block';
}

// Masquer le modal pour supprimer une gare
function hideDeleteStationModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('delete-station-modal').style.display = 'none';
}

// Afficher le modal pour modifier une gare
function showEditStationModal() {
    // Remplir la liste déroulante avec les noms des gares
    const select = document.getElementById('edit-station-name');
    select.innerHTML = ''; // Vider la liste

    // Ajouter chaque gare comme option
    stations.forEach(station => {
        const option = document.createElement('option');
        option.value = station.name;
        option.textContent = station.name;
        select.appendChild(option);
    });

    // Afficher le modal
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('edit-station-modal').style.display = 'block';

    // Charger les données de la première gare par défaut
    loadStationData();
}

// Masquer le modal pour modifier une gare
function hideEditStationModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('edit-station-modal').style.display = 'none';
}

// Afficher le modal pour la liste des gares
function showStationList() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('station-list-modal').style.display = 'block';
}

// Masquer le modal pour la liste des gares
function hideStationList() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('station-list-modal').style.display = 'none';
}

function addStation() {
    const name = document.getElementById('station-name-input').value.trim();
    const type = document.getElementById('station-type-input').value;
    const sector = document.getElementById('station-sector-input').value;
    const latitude = parseFloat(document.getElementById('station-latitude-input').value);
    const longitude = parseFloat(document.getElementById('station-longitude-input').value);
    const photoInput = document.getElementById('station-photo-input');
    
    if (!name) {
        alert("Veuillez entrer un nom pour la gare.");
        return;
    }
    if (isNaN(latitude) || isNaN(longitude)) {
        alert("Veuillez entrer des coordonnées valides.");
        return;
    }
    
    // Gestion de la photo
    let photoUrl = '';
    if (photoInput.files.length > 0) {
        const file = photoInput.files[0];
        photoUrl = URL.createObjectURL(file);
    }

    const newStation = {
        name: name,
        coords: [latitude, longitude],
        info: `Gare ${type} - Ligne ${sector}`,
        sector: sector,
        type: type,
        photo: photoUrl
    };

    stations.push(newStation);
    saveStationsToLocalStorage();

    const marker = L.circleMarker(newStation.coords, {
        radius: 6,
        fillColor: "#3498db",
        color: "#fff",
        weight: 1,
        opacity: 1,
        fillOpacity: 0.8
    }).bindPopup(`<b>${newStation.name}</b><br>${newStation.info}`);
    marker.on('click', function() {
        showStationInfo(newStation);
    });
    markers.push(marker);
    marker.addTo(map);

    hideAddStationModal();
    document.getElementById('station-name-input').value = '';
    document.getElementById('station-latitude-input').value = '';
    document.getElementById('station-longitude-input').value = '';

    document.getElementById('total-stations').textContent = stations.length;

    alert(`La gare "${name}" a été ajoutée avec succès!`);
    map.setView(newStation.coords, 15);

    // Enregistrer l'action dans l'historique
    stationHistory.push({
        date: new Date(),
        action: "Ajout",
        details: `Gare ajoutée : ${name} (${type}, ${sector})`
    });
}

function initMapClickForCoordinates() {
    map.on('click', function(e) {
        if (isUpdatingCoords) {
            // Si nous sommes en mode de mise à jour des coordonnées, appeler la fonction correspondante
            updateStationCoords();
        } else if (document.getElementById('add-station-modal').style.display === 'block') {
            // Sinon, remplir les champs de coordonnées dans le modal d'ajout de gare
            document.getElementById('station-latitude-input').value = e.latlng.lat.toFixed(6);
            document.getElementById('station-longitude-input').value = e.latlng.lng.toFixed(6);
        }
    });
}

function saveStationsToLocalStorage() {
    localStorage.setItem('railwayStations', JSON.stringify(stations));
}

// Charge les stations depuis le localStorage
function loadStationsFromLocalStorage() {
    const savedStations = localStorage.getItem('railwayStations');
    if (savedStations) {
        return JSON.parse(savedStations);
    }
    return null;
}
// Afficher le modal pour supprimer une gare
function showDeleteStationModal() {
    // Remplir la liste déroulante avec les noms des gares
    const select = document.getElementById('delete-station-name');
    select.innerHTML = ''; // Vider la liste
    
    // Ajouter chaque gare comme option
    stations.forEach(station => {
        const option = document.createElement('option');
        option.value = station.name;
        option.textContent = station.name;
        select.appendChild(option);
    });
    
    // Afficher le modal
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('delete-station-modal').style.display = 'block';
}

function deleteStation() {
    const stationName = document.getElementById('delete-station-name').value;
    if (!stationName) {
        alert("Veuillez sélectionner une gare à supprimer.");
        return;
    }

    const index = stations.findIndex(station => station.name === stationName);
    if (index === -1) {
        alert("Gare non trouvée!");
        return;
    }

    const deletedStation = stations[index];
    map.removeLayer(markers[index]);
    markers.splice(index, 1);
    stations.splice(index, 1);
    saveStationsToLocalStorage();

    document.getElementById('total-stations').textContent = stations.length;
    hideDeleteStationModal();
    alert(`La gare "${stationName}" a été supprimée avec succès.`);

    // Enregistrer l'action dans l'historique
    stationHistory.push({
        date: new Date(),
        action: "Suppression",
        details: `Gare supprimée : ${stationName}`
    });
}

function loadStationData() {
    const stationName = document.getElementById('edit-station-name').value;
    if (!stationName) return;

    // Trouver la gare correspondante
    const station = stations.find(s => s.name === stationName);
    if (!station) return;

    // Remplir les champs du formulaire avec les données de la gare
    document.getElementById('edit-station-new-name').value = station.name;
    document.getElementById('edit-station-type').value = station.type || 'Station';
    document.getElementById('edit-station-sector').value = station.sector || 'Tunis';
    document.getElementById('current-coords').textContent = `Lat: ${station.coords[0]}, Lng: ${station.coords[1]}`;

    // Gestion de la photo
    const photoContainer = document.getElementById('current-photo-container');
    const editPhotoPreview = document.getElementById('edit-photo-preview');
    
    if (station.photo) {
        photoContainer.innerHTML = `<img src="${station.photo}" alt="Photo actuelle">`;
    } else {
        photoContainer.innerHTML = '<p>Aucune photo disponible</p>';
    }
    
    // Réinitialiser l'aperçu de la nouvelle photo
    document.getElementById('edit-station-photo-input').value = '';
    editPhotoPreview.style.display = 'none';
    editPhotoPreview.src = '';
}

let isUpdatingCoords = false; // Variable globale pour suivre l'état de mise à jour des coordonnées

function updateStationCoords() {
    isUpdatingCoords = true; // Activer le mode de mise à jour des coordonnées
    alert("Cliquez sur la carte pour définir les nouvelles coordonnées de la gare.");

    map.once('click', function(e) {
        const newLat = e.latlng.lat.toFixed(6);
        const newLng = e.latlng.lng.toFixed(6);

        // Mettre à jour les coordonnées affichées dans le modal
        document.getElementById('current-coords').textContent = `Lat: ${newLat}, Lng: ${newLng}`;

        isUpdatingCoords = false; // Désactiver le mode de mise à jour des coordonnées
    });
}

function updateStation() {
    const oldName = document.getElementById('edit-station-name').value;
    const newName = document.getElementById('edit-station-new-name').value.trim();
    const newType = document.getElementById('edit-station-type').value;
    const newSector = document.getElementById('edit-station-sector').value;
    const photoInput = document.getElementById('edit-station-photo-input');
    const currentCoordsText = document.getElementById('current-coords').textContent;
    const coordsMatch = currentCoordsText.match(/Lat: (-?\d+\.\d+), Lng: (-?\d+\.\d+)/);

    // Validation des entrées
    if (!newName) {
        alert("Veuillez entrer un nom pour la gare.");
        return;
    }
    if (!coordsMatch) {
        alert("Les coordonnées ne sont pas valides.");
        return;
    }

    const newLat = parseFloat(coordsMatch[1]);
    const newLng = parseFloat(coordsMatch[2]);

    // Vérifier si la gare existe
    const index = stations.findIndex(station => station.name === oldName);
    if (index === -1) {
        alert("Gare non trouvée!");
        return;
    }

    // Vérifier si le nouveau nom est déjà utilisé par une autre gare
    if (newName !== oldName && stations.some(s => s.name === newName)) {
        alert("Une gare avec ce nom existe déjà.");
        return;
    }

    // Préparer les détails pour l'historique
    const changes = [];
    if (stations[index].name !== newName) changes.push(`nom: ${stations[index].name} → ${newName}`);
    if (stations[index].type !== newType) changes.push(`type: ${stations[index].type} → ${newType}`);
    if (stations[index].sector !== newSector) changes.push(`secteur: ${stations[index].sector} → ${newSector}`);
    if (stations[index].coords[0] !== newLat || stations[index].coords[1] !== newLng) {
        changes.push(`coords: [${stations[index].coords}] → [${newLat},${newLng}]`);
    }

    // Mettre à jour les propriétés de la gare
    stations[index].name = newName;
    stations[index].type = newType;
    stations[index].sector = newSector;
    stations[index].coords = [newLat, newLng];
    stations[index].info = `Gare ${newType} - Ligne ${newSector}`;

    // Gestion de la photo
    if (photoInput.files.length > 0) {
        const file = photoInput.files[0];
        const reader = new FileReader();
        
        reader.onload = function(e) {
            stations[index].photo = e.target.result;
            completeStationUpdate(index, oldName, newName, newLat, newLng, changes);
        };
        
        reader.readAsDataURL(file);
    } else {
        completeStationUpdate(index, oldName, newName, newLat, newLng, changes);
    }
}

function completeStationUpdate(index, oldName, newName, newLat, newLng, changes) {
    // Mettre à jour le marqueur sur la carte
    map.removeLayer(markers[index]);
    
    const newMarker = L.circleMarker([newLat, newLng], {
        radius: 6,
        fillColor: "#3498db",
        color: "#fff",
        weight: 1,
        opacity: 1,
        fillOpacity: 0.8
    }).bindPopup(`<b>${newName}</b><br>${stations[index].info}`);
    
    newMarker.on('click', function() {
        showStationInfo(stations[index]);
    });
    
    markers[index] = newMarker;
    newMarker.addTo(map);

    // Sauvegarder les modifications
    saveStationsToLocalStorage();

    // Mettre à jour le compteur de gares (si dans le dashboard)
    if (document.getElementById('total-stations')) {
        document.getElementById('total-stations').textContent = stations.length;
    }

    // Fermer le modal
    hideEditStationModal();

    // Afficher un message de confirmation
    alert(`La gare "${oldName}" a été mise à jour avec succès.`);

    // Enregistrer dans l'historique si des changements ont été faits
    if (changes.length > 0) {
        stationHistory.push({
            date: new Date(),
            action: "Mise à jour",
            details: `Gare "${oldName}" modifiée: ${changes.join(', ')}`
        });
    }

    // Si c'était la gare actuellement affichée, mettre à jour son affichage
    if (currentStation && currentStation.name === oldName) {
        showStationInfo(stations[index]);
    }
}

map.on('click', function(e) {
    if (isUpdatingCoords) {
        updateStationCoords();
    } else if (document.getElementById('add-station-modal').style.display === 'block') {
        document.getElementById('station-latitude-input').value = e.latlng.lat.toFixed(6);
        document.getElementById('station-longitude-input').value = e.latlng.lng.toFixed(6);
    }
});
function showStationStats() {
    const container = document.getElementById('history-container');
    container.innerHTML = ''; // Vider le conteneur

    if (stationHistory.length === 0) {
        container.innerHTML = '<p>Aucune modification enregistrée.</p>';
        return;
    }

    stationHistory.forEach(entry => {
        const entryDiv = document.createElement('div');
        entryDiv.style.marginBottom = '10px';
        entryDiv.style.padding = '8px';
        entryDiv.style.backgroundColor = '#f9f9f9';
        entryDiv.style.borderRadius = '5px';
        entryDiv.innerHTML = `
            <strong>${entry.action}</strong> - ${entry.date.toLocaleString()}<br>
            ${entry.details}
        `;
        container.appendChild(entryDiv);
    });

    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('history-modal').style.display = 'block';
}

function hideHistoryModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('history-modal').style.display = 'none';
}
function exportHistoryToPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        doc.text("Historique des modifications des gares", 10, 10);
        let yPos = 20;

        stationHistory.forEach((entry, index) => {
            doc.text(`${index + 1}. ${entry.action} - ${entry.date.toLocaleString()}: ${entry.details}`, 10, yPos);
            yPos += 10;
        });

        doc.save("historique_gares.pdf");
    }
    // Afficher la liste des conducteurs
function showDriverList() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('driver-list-modal').style.display = 'block';
    loadDriverList();
}

// Masquer la liste des conducteurs
function hideDriverList() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('driver-list-modal').style.display = 'none';
}

// Charger la liste des conducteurs depuis le serveur
function loadDriverList() {
    fetch('get_drivers.php')
        .then(response => response.json())
        .then(drivers => {
            const container = document.getElementById('driver-list-container');
            container.innerHTML = ''; // Vider le conteneur
            if (drivers.length === 0) {
                container.innerHTML = '<p>Aucun conducteur enregistré.</p>';
                return;
            }
            drivers.forEach(driver => {
                const driverDiv = document.createElement('div');
                driverDiv.style.marginBottom = '10px';
                driverDiv.style.padding = '8px';
                driverDiv.style.backgroundColor = '#f9f9f9';
                driverDiv.style.borderRadius = '5px';
                driverDiv.innerHTML = `
                    <strong>${driver.username}</strong> (${driver.email})<br>
                    Inscription: ${new Date(driver.created_at).toLocaleDateString()}
                `;
                container.appendChild(driverDiv);
            });
        })
        .catch(error => {
            console.error('Error fetching drivers:', error);
        });
}

// Exporter la liste des conducteurs en PDF
function exportDriverListToPDF() {
    fetch('get_drivers.php')
        .then(response => response.json())
        .then(drivers => {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            doc.text("Liste des Conducteurs", 10, 10);
            let yPos = 20;
            drivers.forEach((driver, index) => {
                doc.text(`${index + 1}. ${driver.username} (${driver.email}) - Inscription: ${new Date(driver.created_at).toLocaleDateString()}`, 10, yPos);
                yPos += 10;
            });
            doc.save("liste_conducteurs.pdf");
        })
        .catch(error => {
            console.error('Error exporting drivers to PDF:', error);
        });
}
// Filtrer les conducteurs dans la table
function filterDrivers() {
    const input = document.getElementById('driver-search-input').value.toLowerCase();
    const rows = document.querySelectorAll('#driver-table tbody tr');
    rows.forEach(row => {
        const username = row.cells[0].textContent.toLowerCase();
        const email = row.cells[1].textContent.toLowerCase();
        if (username.includes(input) || email.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Charger la liste des conducteurs depuis le serveur
function loadDriverList() {
    fetch('get_drivers.php')
        .then(response => response.json())
        .then(drivers => {
            const container = document.getElementById('driver-list-container');
            container.innerHTML = ''; // Vider le conteneur
            if (drivers.length === 0) {
                container.innerHTML = '<tr><td colspan="4">Aucun conducteur enregistré.</td></tr>';
                return;
            }
            drivers.forEach(driver => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${driver.username}</td>
                    <td>${driver.email}</td>
                    <td>${new Date(driver.created_at).toLocaleDateString()}</td>
                    <td>
                        <button class="btn btn-small btn-info" onclick="viewDriverDetails('${driver.username}')">
                            <i class="fas fa-eye"></i> Détails
                        </button>
                    </td>
                `;
                container.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Error fetching drivers:', error);
        });
}

// Afficher les détails d'un conducteur
function viewDriverDetails(username) {
    alert(`Afficher les détails du conducteur : ${username}`);
    // Implémentez cette fonction pour afficher les détails dans une nouvelle modal
}
// Afficher le modal pour ajouter un conducteur
function showAddDriverModal() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('add-driver-modal').style.display = 'block';
}

// Masquer le modal pour ajouter un conducteur
function hideAddDriverModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('add-driver-modal').style.display = 'none';
}

// Ajouter un conducteur dans la base de données
function addDriver() {
    const username = document.getElementById('driver-username-input').value.trim();
    const password = document.getElementById('driver-password-input').value.trim();
    const email = document.getElementById('driver-email-input').value.trim();

    if (!username || !password || !email) {
        alert("Veuillez remplir tous les champs.");
        return;
    }

    fetch('add_driver.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password, email })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Le conducteur "${username}" a été ajouté avec succès.`);
                hideAddDriverModal();
            } else {
                alert("Erreur lors de l'ajout du conducteur : " + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("Une erreur est survenue lors de l'ajout du conducteur.");
        });
}

// Afficher le modal pour supprimer un conducteur
function showDeleteDriverModal() {
    fetch('get_drivers.php')
        .then(response => response.json())
        .then(drivers => {
            const select = document.getElementById('delete-driver-name');
            select.innerHTML = ''; // Vider la liste
            drivers.forEach(driver => {
                const option = document.createElement('option');
                option.value = driver.username;
                option.textContent = `${driver.username} (${driver.email})`;
                select.appendChild(option);
            });
            document.getElementById('modal-overlay').classList.add('active');
            document.getElementById('delete-driver-modal').style.display = 'block';
        })
        .catch(error => {
            console.error('Error fetching drivers:', error);
        });
}

// Masquer le modal pour supprimer un conducteur
function hideDeleteDriverModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('delete-driver-modal').style.display = 'none';
}

// Supprimer un conducteur de la base de données
function deleteDriver() {
    const username = document.getElementById('delete-driver-name').value;

    if (!username) {
        alert("Veuillez sélectionner un conducteur à supprimer.");
        return;
    }

    fetch('delete_driver.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Le conducteur "${username}" a été supprimé avec succès.`);
                hideDeleteDriverModal();
            } else {
                alert("Erreur lors de la suppression du conducteur : " + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert("Une erreur est survenue lors de la suppression du conducteur.");
        });
}
function getBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}
// Afficher le modal de filtrage des trains
function showAddTrainModal() {
    document.getElementById('modal-overlay').classList.add('active');
    document.getElementById('filter-train-modal').style.display = 'block';
    filterTrainsByType(); // Afficher tous les trains par défaut
}

// Masquer le modal de filtrage des trains
function hideFilterTrainModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.getElementById('filter-train-modal').style.display = 'none';
    resetTrainFilters(); // Réinitialiser les filtres quand on ferme
}

// Filtrer les trains par type
function filterTrainsByType() {
    const type = document.getElementById('train-type-filter').value;
    const container = document.getElementById('filtered-trains-container');
    
    // Si "Tous les types" est sélectionné, afficher tous les trains
    if (type === 'all') {
        resetTrainFilters();
        container.innerHTML = '<p>Aucun filtre appliqué - affichage de tous les trains.</p>';
        return;
    }
    
    // Filtrer les marqueurs de train
    let filteredCount = 0;
    for (const driverId in driverMarkers) {
        const marker = driverMarkers[driverId];
        const trainType = marker.options.trainType || 'Inconnu';
        
        if (trainType === type) {
            map.addLayer(marker);
            filteredCount++;
        } else {
            map.removeLayer(marker);
        }
    }
    
    // Afficher le résultat dans le modal
    if (filteredCount > 0) {
        container.innerHTML = `<p>${filteredCount} train(s) de type ${type} trouvé(s).</p>`;
    } else {
        container.innerHTML = `<p>Aucun train de type ${type} trouvé.</p>`;
    }
}

// Réinitialiser les filtres de train
function resetTrainFilters() {
    for (const driverId in driverMarkers) {
        map.addLayer(driverMarkers[driverId]);
    }
    document.getElementById('filtered-trains-container').innerHTML = '';
    document.getElementById('train-type-filter').value = 'all';
}

// Modifier la fonction createTrainMarker pour inclure le type de train
function createTrainMarker(driver, position) {
    var driverIcon = L.divIcon({
        className: 'driver-marker',
        html: '<i class="fas fa-train" style="color: white; font-size: 14px;"></i>',
        iconSize: [26, 26]
    });
    
    driverMarkers[driver.id] = L.marker(position, {
        icon: driverIcon,
        zIndexOffset: 1000,
        trainType: driver.train_type || 'Inconnu' // Stocker le type de train dans les options du marqueur
    }).bindPopup(`
        <b>${driver.name}</b><br>
        Type: ${driver.train_type || 'Inconnu'}<br>
        Vitesse: ${driver.speed} km/h<br>
        Dernière mise à jour: ${new Date(driver.timestamp * 1000).toLocaleTimeString()}
    `).addTo(map);
    
    driverMarkers[driver.id].on('click', function() {
        selectedTrain = driver;
        updateTrainInfo(driver);
    });
}
    </script>
</body>
</html>