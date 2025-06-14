// assets/js/script.js
document.addEventListener('DOMContentLoaded', function() {
    // Charger les messages au démarrage
    if (document.getElementById('messages-container')) {
        fetchMessages();
        
        // Vérifier les nouveaux messages périodiquement
        setInterval(fetchMessages, 3000);
    }
});

function fetchMessages() {
    fetch('get.php?receiver=driver')
    .then(response => {
        if (!response.ok) {
            throw new Error('Erreur réseau');
        }
        return response.json();
    })
    .then(messages => {
        const container = document.getElementById('messages-container');
        if (!container) return;
        
        container.innerHTML = '';
        
        messages.forEach(msg => {
            const messageDiv = document.createElement('div');
            messageDiv.className = msg.sender === 'control' ? 
                'message control-message' : 'message driver-message';
            
            const time = new Date(msg.timestamp);
            const timeStr = time.toLocaleTimeString('fr-FR', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            messageDiv.innerHTML = `
                <strong>${msg.sender === 'control' ? 'Contrôle' : 'Vous'}</strong>
                <span class="timestamp">${timeStr}</span>
                <div>${msg.message}</div>
            `;
            
            container.appendChild(messageDiv);
        });
        
        container.scrollTop = container.scrollHeight;
    })
    .catch(error => {
        console.error('Error fetching messages:', error);
    });
}

function sendMessage() {
    const messageInput = document.getElementById('message-input');
    if (!messageInput) return;
    
    const message = messageInput.value.trim();
    if (!message) return;
    
    fetch('send.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'sender=driver&message=' + encodeURIComponent(message)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Erreur réseau');
        }
        return response.text();
    })
    .then(data => {
        console.log('Message sent:', data);
        messageInput.value = '';
        fetchMessages(); // Actualiser les messages
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Erreur lors de l\'envoi du message');
    });
}

// Permettre d'envoyer avec Ctrl+Enter
const messageInput = document.getElementById('message-input');
if (messageInput) {
    messageInput.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') {
            sendMessage();
        }
    });
}