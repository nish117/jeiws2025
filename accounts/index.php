<?php
// www.jeiws.com/accounts lands here.
require __DIR__ . '/includes/bootstrap.php';
redirect(current_user() ? 'dashboard.php' : 'login.php');
