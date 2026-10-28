<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Logging
    |--------------------------------------------------------------------------
    |
    | Master switch. When false, no outgoing request is logged, whatever the
    | allowed list below says.
    |
    */

    'enabled' => env('OZIOMA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Register Globally
    |--------------------------------------------------------------------------
    |
    | When true, the logger is added as a global middleware on Laravel's HTTP
    | client, so every Http::get()/post()/... call is checked against the
    | allowed list. Set it to false to attach it yourself, per request:
    |
    |     Http::withMiddleware(OutgoingRequestLogger::make())->get(...)
    |
    */

    'register_globally' => env('OZIOMA_GLOBAL', true),

    /*
    |--------------------------------------------------------------------------
    | Allowed URLs
    |--------------------------------------------------------------------------
    |
    | Only requests whose URL matches one of these entries are logged. Each
    | entry is a host with an optional path; the scheme (http:// or https://)
    | and the query string are ignored when matching.
    |
    | An entry matches its URL exactly, unless it ends with "*", in which case
    | it matches every URL that starts with it:
    |
    |     'api.paystack.co/transaction/verify'  only that path
    |     'api.paystack.co/transaction/*'       every path under /transaction
    |     'api.paystack.co/*'                   every path on api.paystack.co
    |     '*'                                   every outgoing request
    |
    | An empty list logs nothing.
    |
    */

    'allowed' => [
        // 'api.paystack.co/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The channel entries are written to. If no channel with this name exists
    | in config/logging.php, the package registers a daily channel for it
    | using the settings under "default_channel".
    |
    */

    'channel' => env('OZIOMA_CHANNEL', 'outgoing_requests'),

    'default_channel' => [
        'driver' => 'daily',
        'path' => storage_path('logs/outgoing-requests.log'),
        'level' => env('OZIOMA_LEVEL', 'info'),
        'days' => env('OZIOMA_DAYS', 7),
        'replace_placeholders' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    |
    | Request headers and body fields (JSON or form-encoded, at any depth)
    | whose names appear here are replaced with the mask value before being
    | logged. Matching is case-insensitive.
    |
    */

    'mask' => '[MASKED]',

    'sensitive_headers' => [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'secret-key',
    ],

    'sensitive_body_fields' => [
        'password',
        'secret',
        'secret-key',
        'token',
        'key',
        'api_key',
        'access_token',
        'refresh_token',
        'private_key',
        'card_number',
        'cvv',
        'pin',
        'otp',
    ],

];
