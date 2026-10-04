<?php
defined('ACC_LOADED') or die('Direct access denied.');

/**
 * Default chart of accounts for a Nepal construction company, seeded on
 * first install. Columns: code, name, type, is_group, parent code, system_key.
 *
 * system_key accounts are the fixed posting targets other modules use
 * (invoices → accounts_receivable + vat_output, expenses → vat_input,
 * TDS → tds_payable, …). Look them up with system_account_id().
 */
return [
    // ── Assets ────────────────────────────────────────────────────────
    ['1000', 'Assets',                                   'asset', 1, null,   null],
    ['1100', 'Current Assets',                           'asset', 1, '1000', null],
    ['1110', 'Cash in Hand',                             'asset', 0, '1100', 'cash_in_hand'],
    ['1120', 'Petty Cash – Site',                        'asset', 0, '1100', 'petty_cash'],
    ['1130', 'Bank Accounts',                            'asset', 1, '1100', 'bank_accounts_group'],
    ['1140', 'Accounts Receivable',                      'asset', 0, '1100', 'accounts_receivable'],
    ['1150', 'Retention Receivable',                     'asset', 0, '1100', 'retention_receivable'],
    ['1160', 'Advances to Suppliers & Subcontractors',   'asset', 0, '1100', 'supplier_advances'],
    ['1170', 'Staff Advances',                           'asset', 0, '1100', 'staff_advances'],
    ['1180', 'Input VAT',                                'asset', 0, '1100', 'vat_input'],
    ['1185', 'TDS Receivable',                           'asset', 0, '1100', 'tds_receivable'],
    ['1186', 'Advance Income Tax',                       'asset', 0, '1100', 'advance_income_tax'],
    ['1190', 'Inventory – Construction Materials',       'asset', 0, '1100', 'inventory'],
    ['1195', 'Security Deposits & Bid Bonds',            'asset', 0, '1100', null],
    ['1200', 'Non-Current Assets',                       'asset', 1, '1000', null],
    ['1210', 'Land & Building',                          'asset', 0, '1200', null],
    ['1220', 'Plant & Machinery',                        'asset', 0, '1200', null],
    ['1230', 'Vehicles',                                 'asset', 0, '1200', null],
    ['1240', 'Furniture & Office Equipment',             'asset', 0, '1200', null],
    ['1250', 'Computers & IT Equipment',                 'asset', 0, '1200', null],
    ['1290', 'Accumulated Depreciation',                 'asset', 0, '1200', 'accumulated_depreciation'],

    // ── Liabilities ───────────────────────────────────────────────────
    ['2000', 'Liabilities',                              'liability', 1, null,   null],
    ['2100', 'Current Liabilities',                      'liability', 1, '2000', null],
    ['2110', 'Accounts Payable',                         'liability', 0, '2100', 'accounts_payable'],
    ['2120', 'Retention Payable',                        'liability', 0, '2100', 'retention_payable'],
    ['2130', 'Mobilisation Advance from Clients',        'liability', 0, '2100', 'client_advances'],
    ['2140', 'Output VAT',                               'liability', 0, '2100', 'vat_output'],
    ['2150', 'TDS Payable',                              'liability', 0, '2100', 'tds_payable'],
    ['2170', 'Salaries Payable',                         'liability', 0, '2100', 'salaries_payable'],
    ['2180', 'Income Tax Payable',                       'liability', 0, '2100', 'income_tax_payable'],
    ['2190', 'Accrued Expenses',                         'liability', 0, '2100', null],
    ['2200', 'Long-term Liabilities',                    'liability', 1, '2000', null],
    ['2210', 'Bank Loans',                               'liability', 0, '2200', null],
    ['2220', 'Loans from Directors',                     'liability', 0, '2200', null],

    // ── Equity ────────────────────────────────────────────────────────
    ['3000', 'Equity',                                   'equity', 1, null,   null],
    ['3100', 'Share Capital',                            'equity', 0, '3000', null],
    ['3200', 'Retained Earnings',                        'equity', 0, '3000', 'retained_earnings'],
    ['3300', 'Opening Balance Equity',                   'equity', 0, '3000', 'opening_balance_equity'],

    // ── Income ────────────────────────────────────────────────────────
    ['4000', 'Income',                                   'income', 1, null,   null],
    ['4100', 'Contract Revenue',                         'income', 0, '4000', 'contract_revenue'],
    ['4200', 'Design & Consultancy Income',              'income', 0, '4000', null],
    ['4300', 'Other Income',                             'income', 0, '4000', null],
    ['4310', 'Interest Income',                          'income', 0, '4000', null],

    // ── Direct project costs ──────────────────────────────────────────
    ['5000', 'Direct Project Costs',                     'expense', 1, null,   null],
    ['5100', 'Construction Materials',                   'expense', 0, '5000', 'materials_cost'],
    ['5200', 'Site Labour & Wages',                      'expense', 0, '5000', 'labour_cost'],
    ['5300', 'Subcontractor Charges',                    'expense', 0, '5000', 'subcontractor_cost'],
    ['5400', 'Equipment & Machinery Hire',               'expense', 0, '5000', null],
    ['5500', 'Transportation & Freight',                 'expense', 0, '5000', null],
    ['5600', 'Site Expenses',                            'expense', 0, '5000', null],
    ['5700', 'Design, Testing & Permit Fees',            'expense', 0, '5000', null],

    // ── Operating expenses ────────────────────────────────────────────
    ['6000', 'Operating Expenses',                       'expense', 1, null,   null],
    ['6100', 'Salaries & Allowances',                    'expense', 0, '6000', 'salary_expense'],
    ['6200', 'Office Rent',                              'expense', 0, '6000', null],
    ['6300', 'Electricity, Water & Internet',            'expense', 0, '6000', null],
    ['6400', 'Telephone & Communication',                'expense', 0, '6000', null],
    ['6500', 'Fuel & Vehicle Maintenance',               'expense', 0, '6000', null],
    ['6600', 'Repairs & Maintenance',                    'expense', 0, '6000', null],
    ['6700', 'Audit & Professional Fees',                'expense', 0, '6000', null],
    ['6800', 'Bank Charges',                             'expense', 0, '6000', 'bank_charges'],
    ['6810', 'Interest Expense',                         'expense', 0, '6000', null],
    ['6900', 'Depreciation',                             'expense', 0, '6000', 'depreciation_expense'],
    ['6910', 'Printing & Stationery',                    'expense', 0, '6000', null],
    ['6920', 'Travel & Conveyance',                      'expense', 0, '6000', null],
    ['6930', 'Advertising & Marketing',                  'expense', 0, '6000', null],
    ['6940', 'Insurance',                                'expense', 0, '6000', null],
    ['6950', 'Taxes, Fees & Renewals',                   'expense', 0, '6000', null],
    ['6960', 'Income Tax Expense',                       'expense', 0, '6000', 'income_tax_expense'],
    ['6990', 'Miscellaneous Expenses',                   'expense', 0, '6000', null],
];
