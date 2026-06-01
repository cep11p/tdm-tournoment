<?php

return [
    'version_prefix' => env('API_VERSION_PREFIX', 'v1'),

    'pagination' => [
        'default_per_page' => (int) env('API_DEFAULT_PER_PAGE', 15),
        'max_per_page' => (int) env('API_MAX_PER_PAGE', 100),
    ],
];
