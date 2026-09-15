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

    // チャットワーク。token は「鍵」なのでコードに書かず .env の CHATWORK_TOKEN に置く。
    //
    // ⚠ 部屋の既定値は**持たない**（2026-09-15 に消した）。
    //   それまで部屋番号を2つ既定値として書いていたが、これは GAS版から
    //   引き継いだ古い番号で、**いまは誰も入っていない部屋**だった。
    //   そのため「テスト送信完了」と出るのに、どこにも届かない（誰も見ていない部屋に
    //   投げていた）という状態になっていた。**送れたのに届かない**のがいちばん困る。
    //   決めていなければ送らない＝画面に「送り先が未設定です」と出るほうが安全。
    //
    // ⚠ 送り先は 共通設定 →「チャットワークの送り先」から決める（正本＝App\Support\ChatworkRooms）。
    //   .env でも上書きできるが、通常は画面で設定する（部屋IDは鍵ではないため）。
    'chatwork' => [
        'token' => env('CHATWORK_TOKEN'),
        'room' => env('CHATWORK_ROOM_ID', ''),
        'test_room' => env('CHATWORK_TEST_ROOM_ID', ''),
    ],

];
