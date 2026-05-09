<?php

require_once __DIR__ . '/../app/helpers/auth.php';

logoutUser();

header("Location: login.php");
exit;