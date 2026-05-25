<?php

return [
    'default' => 'categories',
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
    ],
];
