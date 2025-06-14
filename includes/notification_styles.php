<?php
// This file contains the CSS styles for notifications
?>
<style>
    .notification-icon {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }
    
    .notification-dropdown .dropdown-item:hover {
        background-color: #f8f9fa;
    }
    
    .notification-dropdown .notification-item.unread {
        background-color: rgba(13, 110, 253, 0.05);
        border-left: 3px solid #0d6efd;
    }
    
    .notification-dropdown .dropdown-header {
        border-bottom: 1px solid #e9ecef;
    }
</style>