<?php
require __DIR__ . '/../src/bootstrap.php';
session_start_safe();
$_SESSION = [];
session_destroy();
redirect('/login.php');
