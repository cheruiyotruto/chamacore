<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform behaviour
    |--------------------------------------------------------------------------
    */
    'currency' => env('APP_CURRENCY', 'KES'),
    'currency_symbol' => 'KES',
    'timezone' => env('APP_TIMEZONE', 'Africa/Nairobi'),

    /*
    | Currencies that organisations may select. Only ISO codes with exactly two
    | decimal places are listed because Money stores all amounts in minor units
    | (100 per major unit). Super admins enable or disable these at runtime.
    */
    'currencies' => [
        'KES' => ['name' => 'Kenyan Shilling', 'symbol' => 'KSh'],
        'TZS' => ['name' => 'Tanzanian Shilling', 'symbol' => 'TSh'],
        'UGX' => ['name' => 'Ugandan Shilling', 'symbol' => 'USh'],
        'RWF' => ['name' => 'Rwandan Franc', 'symbol' => 'FRw'],
        'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R'],
        'NGN' => ['name' => 'Nigerian Naira', 'symbol' => '₦'],
        'GHS' => ['name' => 'Ghanaian Cedi', 'symbol' => '₵'],
        'ZMW' => ['name' => 'Zambian Kwacha', 'symbol' => 'ZK'],
        'EGP' => ['name' => 'Egyptian Pound', 'symbol' => 'E£'],
        'MAD' => ['name' => 'Moroccan Dirham', 'symbol' => 'DH'],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$'],
        'EUR' => ['name' => 'Euro', 'symbol' => '€'],
        'GBP' => ['name' => 'British Pound', 'symbol' => '£'],
        'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$'],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$'],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$'],
        'CHF' => ['name' => 'Swiss Franc', 'symbol' => 'CHF'],
        'SEK' => ['name' => 'Swedish Krona', 'symbol' => 'kr'],
        'NOK' => ['name' => 'Norwegian Krone', 'symbol' => 'kr'],
        'DKK' => ['name' => 'Danish Krone', 'symbol' => 'kr'],
        'PLN' => ['name' => 'Polish Zloty', 'symbol' => 'zł'],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹'],
        'PKR' => ['name' => 'Pakistani Rupee', 'symbol' => '₨'],
        'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'R$'],
        'MXN' => ['name' => 'Mexican Peso', 'symbol' => 'Mex$'],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'S$'],
        'AED' => ['name' => 'UAE Dirham', 'symbol' => 'د.إ'],
        'SAR' => ['name' => 'Saudi Riyal', 'symbol' => '﷼'],
        'CNY' => ['name' => 'Chinese Yuan', 'symbol' => '¥'],
        'PHP' => ['name' => 'Philippine Peso', 'symbol' => '₱'],
        'THB' => ['name' => 'Thai Baht', 'symbol' => '฿'],
    ],

    'default_enabled_currencies' => [
        'KES', 'TZS', 'UGX', 'RWF', 'ZAR', 'USD', 'EUR', 'GBP',
    ],

    'trial_days' => 30,
    'default_group_status' => 'active',

    /*
    | Financial defaults. Groups may override these in their settings.
    */
    'financial' => [
        'default_penalty_rate_bps' => 200,          // 2% of overdue amount
        'default_penalty_grace_days' => 3,
        'default_guarantee_multiplier_bps' => 30000, // 3x
        'minimum_loan_amount' => 100000,             // KES 1,000
        'default_allocation_policy' => ['penalties', 'fees', 'interest', 'principal'],
        'default_day_count' => 'actual_365',
        'default_accrual_mode' => 'effective',
    ],

    /*
    | M-Pesa float purchases. Packages are major units (KES) and are converted
    | to minor units by the service. Members may also enter any amount inside
    | the configured range.
    */
    'float' => [
        'packages' => [50, 100, 200, 500, 1000, 2000, 5000],
        'min_amount' => 10,
        'max_amount' => 10000,
    ],

    /*
    | Member privacy: users with these roles may only query their own member
    | rows, enforced by the MemberScope global scope and policies.
    */
    'member_roles' => ['member'],

    'roles' => [
        'super_admin' => 'Super Administrator',
        'group_admin' => 'Group Administrator',
        'chairperson' => 'Chairperson',
        'treasurer' => 'Treasurer',
        'secretary' => 'Secretary',
        'accountant' => 'Accountant',
        'teller' => 'Teller',
        'loan_officer' => 'Loan Officer',
        'credit_committee' => 'Credit Committee',
        'supervisory_committee' => 'Supervisory Committee',
        'member' => 'Member',
    ],

    // Most privileged first. Used to prevent a staff member assigning a role
    // above their own level.
    'role_hierarchy' => [
        'group_admin', 'chairperson', 'treasurer', 'secretary', 'accountant',
        'teller', 'loan_officer', 'credit_committee', 'supervisory_committee', 'member',
    ],

    'role_permissions' => [
        'group_admin' => ['*'],
        'chairperson' => [
            'portal.view', 'dashboard.view', 'members.view', 'members.update', 'members.approve', 'loans.view', 'loans.approve',
            'savings.view', 'investments.view', 'mgr.view', 'meetings.view', 'meetings.manage',
            'governance.view', 'governance.manage', 'reports.view', 'documents.view',
            'welfare.view', 'welfare.approve', 'notifications.view',
        ],
        'treasurer' => [
            'portal.view', 'dashboard.view', 'members.view', 'savings.view', 'savings.deposit', 'savings.withdraw',
            'investments.view', 'investments.deposit', 'investments.manage',
            'loans.view', 'loans.disburse', 'wallet.view', 'reports.view',
            'accounting.view', 'accounting.journal', 'mgr.view', 'mgr.manage',
            'payments.view', 'payments.initiate', 'bills.view', 'bills.manage', 'documents.view', 'meetings.view',
            'payouts.view', 'payouts.manage',
            'float_purchases.view', 'float_purchases.manage',
            'teller.view', 'teller.operate', 'teller.reconcile',
        ],
        'teller' => [
            'portal.view', 'dashboard.view', 'members.view',
            'savings.view', 'savings.deposit', 'savings.withdraw',
            'loans.view', 'investments.view', 'investments.deposit',
            'wallet.view', 'mgr.view', 'documents.view',
            'teller.view', 'teller.operate', 'teller.reconcile',
        ],
        'secretary' => [
            'portal.view', 'dashboard.view', 'members.view', 'members.create', 'members.update', 'meetings.view', 'meetings.manage',
            'governance.view', 'governance.manage', 'documents.view', 'documents.manage',
            'reports.view', 'notifications.view', 'crm.view',
        ],
        'accountant' => [
            'portal.view', 'dashboard.view', 'members.view', 'savings.view', 'savings.deposit', 'savings.withdraw',
            'investments.view', 'investments.deposit', 'investments.manage',
            'loans.view', 'loans.disburse', 'wallet.view', 'wallet.reconcile', 'accounting.manage',
            'accounting.view', 'accounting.journal', 'reports.view', 'reports.export',
            'payments.view', 'payments.initiate', 'bills.view', 'bills.manage', 'documents.view', 'meetings.view',
            'payouts.view', 'payouts.manage',
            'float_purchases.view', 'float_purchases.manage',
            'teller.view', 'teller.operate', 'teller.reconcile',
        ],
        'loan_officer' => [
            'portal.view', 'dashboard.view', 'members.view', 'loans.view', 'loans.create', 'loans.approve',
            'loans.disburse', 'savings.view', 'investments.view', 'reports.view', 'documents.view', 'meetings.view',
        ],
        'credit_committee' => ['portal.view', 'dashboard.view', 'members.view', 'loans.view', 'loans.approve', 'reports.view'],
        'supervisory_committee' => ['portal.view', 'dashboard.view', 'members.view', 'loans.view', 'audit.view', 'reports.view', 'documents.view'],
        'member' => [
            'portal.view', 'portal.savings', 'portal.loans', 'portal.wallet',
            'portal.float', 'portal.documents', 'portal.support',
        ],
    ],

    'permissions' => [
        'dashboard.view',
        'members.view', 'members.create', 'members.update', 'members.approve', 'members.terminate', 'members.export',
        'savings.view', 'savings.deposit', 'savings.withdraw', 'savings.manage',
        'investments.view', 'investments.deposit', 'investments.manage',
        'loans.view', 'loans.create', 'loans.approve', 'loans.disburse',
        'loans.restructure', 'loans.write_off',
        'wallet.view', 'wallet.adjust', 'wallet.transfer', 'wallet.reconcile',
        'payments.view', 'payments.initiate',
        'bills.view', 'bills.manage',
        'payouts.view', 'payouts.manage',
        'float_purchases.view', 'float_purchases.manage',
        'teller.view', 'teller.operate', 'teller.reconcile',
        'accounting.view', 'accounting.journal', 'accounting.manage',
        'reports.view', 'reports.export', 'mgr.view', 'mgr.manage',
        'meetings.view', 'meetings.manage',
        'governance.view', 'governance.manage',
        'documents.view', 'documents.manage',
        'crm.view', 'crm.manage',
        'welfare.view', 'welfare.manage', 'welfare.approve',
        'audit.view',
        'settings.view', 'settings.manage',
        'subscription.view', 'subscription.manage',
        'notifications.view', 'notifications.manage',
        'portal.view', 'portal.savings', 'portal.loans', 'portal.wallet',
        'portal.documents', 'portal.support',
    ],

    /*
    | Document upload limits. Sizes are in kilobytes and MIME types are matched
    | exactly against the uploaded file's detected MIME type.
    */
    'documents' => [
        'max_size_kb' => (int) env('DOCUMENTS_MAX_SIZE_KB', 10240),
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
            'video/mp4',
            'video/webm',
            'video/quicktime',
        ],
    ],

    'document_types' => [
        'national_id' => 'National ID / Passport',
        'passport_photo' => 'Passport photograph',
        'face_video' => 'Face verification video',
        'biometric' => 'Biometric capture',
        'membership_form' => 'Membership application',
        'loan_application' => 'Loan application',
        'loan_agreement' => 'Loan agreement',
        'guarantor_form' => 'Guarantor form',
        'meeting_minutes' => 'Meeting minutes',
        'constitution' => 'Group constitution',
        'receipt' => 'Receipt',
        'financial_report' => 'Financial report',
        'welfare_claim' => 'Welfare claim',
        'other' => 'Other',
    ],

    'subscription_plans' => [
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'price' => 250000,
            'interval' => 'monthly',
            'trial_days' => 30,
            'max_members' => 50,
            'max_groups' => 1,
            'modules' => ['core', 'savings', 'mgr', 'loans'],
        ],
        [
            'name' => 'Starter Savings Circle Basic',
            'slug' => 'starter_savings_circle_basic',
            'price' => 100000,
            'interval' => 'monthly',
            'trial_days' => 30,
            'max_members' => 100,
            'max_groups' => 1,
            'modules' => [
                'core', 'savings', 'mgr', 'loans', 'wallet',
                'single_loan', 'wallet_members_officials',
            ],
        ],
        [
            'name' => 'Growth',
            'slug' => 'growth',
            'price' => 750000,
            'interval' => 'monthly',
            'trial_days' => 30,
            'max_members' => 300,
            'max_groups' => 1,
            'modules' => ['core', 'savings', 'mgr', 'loans', 'wallet', 'accounting', 'crm'],
        ],
        [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'price' => 2500000,
            'interval' => 'monthly',
            'trial_days' => 30,
            'max_members' => 5000,
            'max_groups' => 10,
            'modules' => ['*'],
        ],
        [
            'name' => 'Platform Managed Free',
            'slug' => 'platform_managed_free',
            'price' => 0,
            'interval' => 'monthly',
            'trial_days' => 0,
            'max_members' => 1000,
            'max_groups' => 1,
            'modules' => [
                'core', 'savings', 'mgr', 'loans', 'wallet',
                'platform_managed', 'platform_charges',
            ],
        ],
    ],
];
