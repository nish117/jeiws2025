<?php
// Supplier bills are the expenses records — they live on the Invoices page's Purchase bills tab.
require __DIR__ . '/includes/bootstrap.php';
require_login();
redirect('invoices.php?type=purchase');
