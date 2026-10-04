<?php
require __DIR__ . '/includes/bootstrap.php';

// POST-only with CSRF so a third-party page can't sign users out via an <img> tag.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (current_user()) audit('logout', 'user', (int)$_SESSION['acc_user_id']);
    logout_user();
    flash('success', 'You have been signed out.');
}
redirect('login.php');
