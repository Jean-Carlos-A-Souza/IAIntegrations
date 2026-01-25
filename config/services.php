<?php

return [
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-5-nano'),
        'chat_model' => env('OPENAI_CHAT_MODEL', env('OPENAI_MODEL', 'gpt-5-nano')),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
    ],
];
