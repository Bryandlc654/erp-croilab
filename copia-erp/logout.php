<?php
require_once __DIR__ . '/auth.php';
unset($_SESSION['client_id']);
header('Location: login.php');
exit;
