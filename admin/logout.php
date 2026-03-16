<?php
require_once __DIR__ . '/../config/database.php';
session_destroy();
header('Location: ../admin_login.php');
exit;

