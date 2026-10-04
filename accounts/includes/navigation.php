<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Sidebar menu. `key` is what each page passes as $activeNav to highlight
 * itself; `section` groups items under a small heading in the sidebar.
 */
return [
    ['key' => 'dashboard',  'label' => 'Dashboard',   'icon' => 'fa-gauge-high',           'href' => 'dashboard.php',  'section' => 'Overview'],

    ['key' => 'projects',   'label' => 'Projects',    'icon' => 'fa-helmet-safety',        'href' => 'projects.php',   'section' => 'Operations'],
    ['key' => 'clients',    'label' => 'Clients',     'icon' => 'fa-user-tie',             'href' => 'clients.php',    'section' => 'Operations'],
    ['key' => 'suppliers',  'label' => 'Suppliers',   'icon' => 'fa-truck-field',          'href' => 'suppliers.php',  'section' => 'Operations'],

    ['key' => 'invoices',   'label' => 'Invoices',    'icon' => 'fa-file-invoice-dollar',  'href' => 'invoices.php',   'section' => 'Transactions'],
    ['key' => 'expenses',   'label' => 'Expenses',    'icon' => 'fa-receipt',              'href' => 'expenses.php',   'section' => 'Transactions'],
    ['key' => 'payments',   'label' => 'Payments',    'icon' => 'fa-money-bill-transfer',  'href' => 'payments.php',   'section' => 'Transactions'],
    ['key' => 'cash-bank',  'label' => 'Cash & Bank', 'icon' => 'fa-building-columns',     'href' => 'cash-bank.php',  'section' => 'Transactions'],
    ['key' => 'accounting', 'label' => 'Accounting',  'icon' => 'fa-book',                 'href' => 'accounting.php', 'section' => 'Transactions'],

    ['key' => 'vat',        'label' => 'VAT',         'icon' => 'fa-percent',              'href' => 'vat.php',        'section' => 'Tax'],
    ['key' => 'tds',        'label' => 'TDS',         'icon' => 'fa-hand-holding-dollar',  'href' => 'tds.php',        'section' => 'Tax'],
    ['key' => 'income-tax', 'label' => 'Income Tax',  'icon' => 'fa-landmark',             'href' => 'income-tax.php', 'section' => 'Tax'],

    ['key' => 'reports',    'label' => 'Reports',     'icon' => 'fa-chart-pie',            'href' => 'reports.php',    'section' => 'Management'],
    ['key' => 'payroll',    'label' => 'Payroll',     'icon' => 'fa-people-group',         'href' => 'payroll.php',    'section' => 'Management'],
    ['key' => 'settings',   'label' => 'Settings',    'icon' => 'fa-gear',                 'href' => 'settings.php',   'section' => 'Management'],
];
