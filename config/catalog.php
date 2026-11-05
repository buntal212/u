<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Catalog Source Mode
    |--------------------------------------------------------------------------
    | Supported modes:
    | - 'auto': use dynamic ASU_SOURCES if present, otherwise fallback to mock
    | - 'dynamic': strictly use ASU_SOURCES (empty sources if none configured)
    | - 'mock': explicitly use mock adapters
    */
    'mode' => env('CATALOG_SOURCE_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | ASU Sources JSON Configuration
    |--------------------------------------------------------------------------
    | A JSON string mapping source keys to source configurations.
    | Example:
    | {
    |   "larissama": {
    |     "url": "http://larissama-api:8000",
    |     "token": "${LARISSAMA_TOKEN}",
    |     "timeout": 2.0
    |   }
    | }
    */
    'sources' => env('ASU_SOURCES'),

    /*
    |--------------------------------------------------------------------------
    | Search Timeouts
    |--------------------------------------------------------------------------
    */
    'per_source_timeout' => (float) env('CATALOG_PER_SOURCE_TIMEOUT', 2.0),
    'global_timeout' => (float) env('CATALOG_GLOBAL_TIMEOUT', 5.0),
];
