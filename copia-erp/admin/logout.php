<?php
require_once __DIR__ . '/../auth.php';
unset($_SESSION['admin_id']);
header('Location: login.php');
exit;
