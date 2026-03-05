<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 默认 AI 驱动
    |--------------------------------------------------------------------------
    |
    | 支持: "ollama", "anthropic", "openai", "deepseek"
    | 可通过 AI_DRIVER 环境变量切换
    |
    */
    'default' => env('AI_DRIVER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | AI 驱动配置
    |--------------------------------------------------------------------------
    */
    'drivers' => [

        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'model' => env('OLLAMA_MODEL', 'qwen3:8b'),
            'api_key' => env('OLLAMA_API_KEY', ''),
            'max_tokens' => (int) env('OLLAMA_MAX_TOKENS', 4096),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        ],

        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'api_key' => env('OPENAI_API_KEY', ''),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 4096),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        ],

        'deepseek' => [
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'max_tokens' => (int) env('DEEPSEEK_MAX_TOKENS', 4096),
            'timeout' => (int) env('DEEPSEEK_TIMEOUT', 60),
        ],

    ],

];
