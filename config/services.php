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

    'wecom' => [
        'corp_id' => env('WECOM_CORP_ID'),          // 企业ID，全局共享

        'apps' => [
            'agent' => [                             // 自建应用
                'secret' => env('WECOM_AGENT_SECRET'),
                'token' => env('WECOM_AGENT_TOKEN', ''),
                'aes_key' => env('WECOM_AGENT_AES_KEY', ''),
                'id' => env('WECOM_AGENT_ID'),
            ],
            'contact' => [                           // 通讯录应用
                'secret' => env('WECOM_CONTACT_SECRET'),
                'token' => env('WECOM_CONTACT_TOKEN', ''),
                'aes_key' => env('WECOM_CONTACT_AES_KEY', ''),
                'id' => env('WECOM_CONTACT_ID', ''),
            ],
            'bot' => [                           // 智能机器人
                'token' => env('WECOM_BOT_TOKEN', ''),
                'aes_key' => env('WECOM_BOT_AES_KEY', ''),
                'id' => env('WECOM_BOT_ID', ''),
            ],
        ],
    ],

];
