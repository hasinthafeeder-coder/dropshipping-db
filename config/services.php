<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Curfox (Royal Express) location master sync
    |--------------------------------------------------------------------------
    |
    | Used by courier:sync-locations for provider-level state/city import.
    | Never commit a real CURFOX_TOKEN value.
    |
    */
    'curfox' => [
        'base_url' => env('CURFOX_BASE_URL', 'https://v2-dashboards.api.curfox.com'),
        'token' => env('CURFOX_TOKEN'),
        'tenant' => env('CURFOX_TENANT', 'royalexpress'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TransExpress location master sync
    |--------------------------------------------------------------------------
    |
    | Used by courier:sync-locations for provider-level district/city import.
    | Location endpoints currently require no authentication.
    |
    */
    'transexpress' => [
        'base_url' => env('TRANSEXPRESS_BASE_URL', 'https://portal.transexpress.lk/api'),
    ],

];
