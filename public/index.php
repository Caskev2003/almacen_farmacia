<?php
require_once __DIR__ . '/../app/helpers/auth.php';

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

header("Location: login.php");
exit;