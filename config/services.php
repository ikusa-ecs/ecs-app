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

    // チャットワーク（人数確定リマインド用）。
    // token は「鍵」なのでコードに書かず .env の CHATWORK_TOKEN に置く。
    // room / test_room は機密ではないので既定値を持たせておく（.env で上書き可）。
    'chatwork' => [
        'token' => env('CHATWORK_TOKEN'),
        'room' => env('CHATWORK_ROOM_ID', '320609834'),
        'test_room' => env('CHATWORK_TEST_ROOM_ID', '412985590'),
    ],

];
