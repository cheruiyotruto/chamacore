<?php

return [
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],

    'mpesa' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
        'callback_secret' => env('MPESA_CALLBACK_SECRET'),

        // B2B / B2C money-out credentials. The security credential is the
        // pre-encrypted initiator password generated with the Daraja certificate.
        'b2b_shortcode' => env('MPESA_B2B_SHORTCODE'),
        'b2b_initiator' => env('MPESA_B2B_INITIATOR'),
        'b2b_security_credential' => env('MPESA_B2B_SECURITY_CREDENTIAL'),
        'b2b_result_url' => env('MPESA_B2B_RESULT_URL'),
        'b2b_queue_url' => env('MPESA_B2B_QUEUE_URL'),
        'b2b_callback_secret' => env('MPESA_B2B_CALLBACK_SECRET'),
    ],

    'bank_payouts' => [
        'endpoint' => env('BANK_PAYOUT_ENDPOINT'),
        'token' => env('BANK_PAYOUT_TOKEN'),
        'callback_url' => env('BANK_PAYOUT_CALLBACK_URL'),
        'callback_secret' => env('BANK_PAYOUT_CALLBACK_SECRET'),
    ],

    /*
    | Airtime / M-Pesa float fulfilment. The default "manual" driver reserves
    | wallet funds and leaves the purchase pending for a treasurer to fulfil.
    | Set FLOAT_DRIVER=http and the endpoint/token to post purchases to an
    | airtime provider. Callbacks are signed with FLOAT_CALLBACK_SECRET when set.
    */
    'float' => [
        'driver' => env('FLOAT_DRIVER', 'manual'),
        'endpoint' => env('FLOAT_ENDPOINT'),
        'token' => env('FLOAT_TOKEN'),
        'callback_url' => env('FLOAT_CALLBACK_URL'),
        'callback_secret' => env('FLOAT_CALLBACK_SECRET'),
        'network' => env('FLOAT_NETWORK', 'mpesa'),
        'timeout' => (int) env('FLOAT_TIMEOUT', 30),
    ],

    'sms' => [
        // Never silently write messages (which can contain invite tokens) to
        // logs in production; an explicit driver is required there.
        'driver' => env('SMS_DRIVER', env('APP_ENV') === 'production' ? 'http' : 'log'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'CHAMACORE'),
        'endpoint' => env('SMS_ENDPOINT', 'https://api.africastalking.com/version1/messaging'),
    ],

    'push' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'project_id' => env('FCM_PROJECT_ID'),
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', env('APP_ENV') === 'production' ? 'http' : 'log'),
        'api_key' => env('WHATSAPP_API_KEY'),
        'from' => env('WHATSAPP_FROM'),
        'endpoint' => env('WHATSAPP_ENDPOINT'),
    ],
];
