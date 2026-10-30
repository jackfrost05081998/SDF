<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (logged_in()) {
    audit((int) current_user()['id'], 'Logged out');
}

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
