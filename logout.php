<?php
require_once 'includes/auth.php';

lm_cerrar_sesion();
header('Location: login.php');
exit;

