<?php

return [
    'disk' => env('KNOWLEDGE_DISK', 'local'),
    'max_upload_kb' => (int) env('KNOWLEDGE_MAX_UPLOAD_KB', 2048),
    'chunk_size' => (int) env('KNOWLEDGE_CHUNK_SIZE', 1000),
    'chunk_overlap' => (int) env('KNOWLEDGE_CHUNK_OVERLAP', 100),
    'preview_length' => (int) env('KNOWLEDGE_PREVIEW_LENGTH', 1500),
    'process_async' => env('KNOWLEDGE_PROCESS_ASYNC', false),
    'generate_embeddings' => env('KNOWLEDGE_GENERATE_EMBEDDINGS', true),
    'allowed_mime_types' => [
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
    ],
    'allowed_extensions' => ['txt', 'md', 'csv', 'json'],
];
