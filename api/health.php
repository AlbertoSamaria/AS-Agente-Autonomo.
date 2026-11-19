<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/ai/Ollama.php';
require_once dirname(__DIR__) . '/services/ai/LlamaCpp.php';

try {
    $provider = strtolower((string) env('LLM_PROVIDER', 'ollama'));
    $fallbackEnabled = filter_var(
        env('LLM_FALLBACK_ENABLED', 'true'),
        FILTER_VALIDATE_BOOLEAN
    );
    $fallbackProvider = strtolower((string) env('LLM_FALLBACK_PROVIDER', ''));

    $providers = [
        'ollama' => (new Ollama())->health(),
        'llama.cpp' => (new LlamaCpp())->health(),
        'gemini' => [
            'cloud' => true,
            'configured' => trim((string) env('GEMINI_API_KEY', '')) !== '',
            'model' => (string) env('GEMINI_MODEL', 'gemini-3.8-flash'),
        ],
        'groq' => [
            'cloud' => true,
            'configured' => trim((string) env('GROQ_API_KEY', '')) !== '',
            'model' => (string) env('GROQ_MODEL', 'openai/gpt-oss-20b'),
        ],
    ];

    json_response([
        'success' => true,
        'app' => 'SA',
        'version' => SA_VERSION,
        'provider' => $provider,
        'fallback_enabled' => $fallbackEnabled,
        'fallback_provider' => $fallbackProvider,
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
