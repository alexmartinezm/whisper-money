<?php

return [
    'default' => 'getting-started',
    'fallback_locale' => 'en',

    'locales' => [
        'en' => 'English',
        'es' => 'Español',
    ],

    'toc' => [
        'placeholder' => '{{TOC}}',
        'levels' => [2, 3],
        'title' => [
            'en' => 'On this page',
            'es' => 'En esta página',
        ],
    ],

    'pages' => [
        'getting-started' => [
            'title' => [
                'en' => 'Getting started',
                'es' => 'Primeros pasos',
            ],
            'description' => [
                'en' => 'Learn the basic Whisper Money workflow.',
                'es' => 'Aprende el flujo básico de Whisper Money.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/getting-started.md'),
                'es' => resource_path('docs/documentation/es/getting-started.md'),
            ],
        ],
        'accounts' => [
            'title' => [
                'en' => 'Accounts',
                'es' => 'Cuentas',
            ],
            'description' => [
                'en' => 'Understand accounts, account types, and balance tracking.',
                'es' => 'Entiende las cuentas, los tipos de cuenta y el seguimiento de saldos.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/accounts.md'),
                'es' => resource_path('docs/documentation/es/accounts.md'),
            ],
        ],
        'transactions' => [
            'title' => [
                'en' => 'Transactions',
                'es' => 'Transacciones',
            ],
            'description' => [
                'en' => 'Learn how transactions, filters, categories, and labels work.',
                'es' => 'Aprende cómo funcionan las transacciones, filtros, categorías y etiquetas.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/transactions.md'),
                'es' => resource_path('docs/documentation/es/transactions.md'),
            ],
        ],
        'categories' => [
            'title' => [
                'en' => 'Categories',
                'es' => 'Categorías',
            ],
            'description' => [
                'en' => 'Learn how categories work in Whisper Money.',
                'es' => 'Aprende cómo funcionan las categorías en Whisper Money.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/categories.md'),
                'es' => resource_path('docs/documentation/es/categories.md'),
            ],
        ],
        'automation-rules' => [
            'title' => [
                'en' => 'Automation rules',
                'es' => 'Reglas de automatización',
            ],
            'description' => [
                'en' => 'Use rules to categorize and label repeated transactions automatically.',
                'es' => 'Usa reglas para categorizar y etiquetar transacciones repetidas automáticamente.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/automation-rules.md'),
                'es' => resource_path('docs/documentation/es/automation-rules.md'),
            ],
        ],
        'cashflow' => [
            'title' => [
                'en' => 'Cashflow',
                'es' => 'Flujo de efectivo',
            ],
            'description' => [
                'en' => 'Understand income, expenses, net cashflow, and savings rate.',
                'es' => 'Entiende ingresos, gastos, flujo neto y tasa de ahorro.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/cashflow.md'),
                'es' => resource_path('docs/documentation/es/cashflow.md'),
            ],
        ],
        'budgets' => [
            'title' => [
                'en' => 'Budgets',
                'es' => 'Presupuestos',
            ],
            'description' => [
                'en' => 'Track planned spending by category or label.',
                'es' => 'Controla el gasto planificado por categoría o etiqueta.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/budgets.md'),
                'es' => resource_path('docs/documentation/es/budgets.md'),
            ],
        ],
        'imports' => [
            'title' => [
                'en' => 'Imports',
                'es' => 'Importaciones',
            ],
            'description' => [
                'en' => 'Import transactions and balances from bank files.',
                'es' => 'Importa transacciones y saldos desde archivos bancarios.',
            ],
            'file' => [
                'en' => resource_path('docs/documentation/en/imports.md'),
                'es' => resource_path('docs/documentation/es/imports.md'),
            ],
        ],
    ],
];
