<?php
// scripts/generate-commit-msg.php

require __DIR__ . '/../vendor/autoload.php';

// --- CONFIGURACIÓN DE LOGS ---
$logFile = __DIR__ . '/../ia-commits.log';

function log_error($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// --- CARGA DE ENTORNO ---
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use GuzzleHttp\Client;

// --- CONFIGURACIÓN DE API ---
// Intentamos obtener la key de $_ENV, $_SERVER o getenv()
$apiKey = $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');

// Usamos gemini-2.0-flash que está disponible en v1beta
$model = 'gemini-2.0-flash';
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";

if (!$apiKey) {
    log_error("Debug: __DIR__ is " . __DIR__);
    log_error("Debug: Expected .env at " . realpath(__DIR__ . '/..') . "/.env");
    log_error("Debug: File exists? " . (file_exists(__DIR__ . '/../.env') ? 'YES' : 'NO'));
    log_error("Debug: Env vars keys: " . implode(', ', array_keys($_ENV)));
    log_error('Error: GEMINI_API_KEY no encontrada en el archivo .env o no está configurada.');
    exit(0);
}

// 1. Obtener los cambios (diff)
$diff = shell_exec('git diff --staged');

if (empty(trim($diff))) {
    exit(0); // No hay cambios, salimos.
}

// 2. Prompt Técnico para la IA
$prompt = "Actúa como un desarrollador experto. Basado en el siguiente 'diff' de git, genera un mensaje de commit conciso que siga estrictamente el estándar de Conventional Commits (type(scope): description).
Reglas:
- Solo devuelve el mensaje de commit.
- No uses markdown ni bloques de código.
- Máximo 100 caracteres para la primera línea.
- Si hay cambios importantes, usa un cuerpo de mensaje breve.

Diff:
" . substr($diff, 0, 8000); // Truncar para seguridad

$client = new Client();
$commitMsgFile = $argv[1] ?? null;

try {
    // Llamada a la API de Google Gemini
    $response = $client->post($apiUrl, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 200,
            ]
        ],
        'timeout' => 10
    ]);

    $body = json_decode($response->getBody(), true);

    if (isset($body['error'])) {
        $errorMessage = $body['error']['message'] ?? 'Error desconocido en la API.';
        log_error("Error de la API de Gemini: " . $errorMessage);
        exit(0);
    }

    $generatedMessage = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($generatedMessage && $commitMsgFile) {
        $generatedMessage = trim($generatedMessage);
        $originalContent = file_exists($commitMsgFile) ? file_get_contents($commitMsgFile) : '';
        file_put_contents($commitMsgFile, $generatedMessage . "\n\n# ---------------------------------------------------\n" . $originalContent);
    } elseif (!$generatedMessage) {
        log_error("La API no devolvió un mensaje generado. Respuesta recibida: " . json_encode($body));
    }

} catch (\Exception $e) {
    log_error("Excepción capturada: " . $e->getMessage());
}

exit(0);
