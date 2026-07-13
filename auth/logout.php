<?php
require_once __DIR__ . '/../includes/auth.php';
logout_user();
header('Location: /cricket/auth/login.php');
exit;
