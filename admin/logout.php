<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Logout user
session_destroy();
redirect('login.php');
