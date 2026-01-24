<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Check permissions - only admins can clear cache
if (!is_logged_in() || !is_admin()) {
    redirect('dashboard.php');
}

// Clear OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared successfully!";
} else {
    echo "OPcache not available";
}
?>
