<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php');
    exit;
}

session_unset();
session_destroy();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

header('Location: login.php');
exit;