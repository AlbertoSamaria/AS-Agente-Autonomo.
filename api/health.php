<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/ai/Ollama.php';
require_once dirname(__DIR__) . '/services/ai/LlamaCpp.php';

try {
    $provider = strtolower((string) env('LLM_PROVIDER', 'ollama'));
    $providers = [
        'ollama' => (new Ollama())->health(),
        'llama.cpp' => (new LlamaCpp())->health(),
    ];
    json_response([
        'success' => true,
        'app' => 'SA',
        'version' => SA_VERSION,
        'provider' => $provider,
        'providers' => $providers,
        'ollama' => $providers['ollama'],
    ]);
} catch (Throwable $error) {
    json_response([
        'success' => false,
        'app' => 'SA',
        'version' => SA_VERSION,
        'error' => $error->getMessage(),
    ], 500);
}
