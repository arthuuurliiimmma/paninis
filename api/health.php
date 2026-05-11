<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'message' => 'API do checkout ativa.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
