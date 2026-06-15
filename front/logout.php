<?php
require_once 'includes/auth.php';

lm_cerrar_sesion();
header('Location: ' . lm_url('login.php'));
exit;

