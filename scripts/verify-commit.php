<?php
// scripts/verify-commit.php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- CONFIGURACIÓN ---
$apiKey = $_ENV['GEMINI_API_KEY'] ?? null;
$model = 'gemini-2.0-flash';
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";
// ---------------------

if (!$apiKey) {
    echo "⚠️ ERROR: La variable de entorno GEMINI_API_KEY no está configurada. Verificación de IA omitida.\n";
    exit(0);
}

// 1. Obtener el mensaje de commit
$commitMsgFile = $argv[1] ?? null;
if (!$commitMsgFile || !file_exists($commitMsgFile)) {
    echo "⚠️ ADVERTENCIA: No se pudo encontrar el archivo del mensaje de commit. Verificación omitida.\n";
    exit(0);
}

$message = trim(file_get_contents($commitMsgFile));

if (empty($message)) {
    // Permitir commit vacío si el flujo lo permite
    exit(0);
}

// 2. Prompt Técnico para la IA
$prompt = "Evalúa si el siguiente mensaje de commit sigue estrictamente el estándar de Conventional Commits (tipo(scope): descripción). 
Los tipos válidos son: feat, fix, docs, style, refactor, perf, test, build, ci, chore. 
Responde ÚNICAMENTE con un objeto JSON. 
Si es válido, 'valid' es true y 'reason' es nulo. 
Si es inválido, 'valid' es false y 'reason' explica el error concisamente en español.
Mensaje a evaluar: '$message'";

$client = new Client();

try {
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
                'temperature' => 0.1,
                'responseMimeType' => 'application/json'
            ]
        ],
        'timeout' => 10
    ]);

    $body = json_decode($response->getBody(), true);
    $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

    // Limpiar bloques de código markdown si la IA los añade
    $text = preg_replace('/^```json\s*|\s*```$/', '', $text);

    $result = json_decode($text, true);

    if (isset($result['valid']) && $result['valid'] === false) {
        $reason = $result['reason'] ?? 'El formato es incorrecto.';
        echo "🚨 ERROR DE VALIDACIÓN POR IA: El mensaje de commit NO cumple el estándar.\n";
        echo "Razón de la IA: $reason\n";
        exit(1);
    }

} catch (RequestException $e) {
    echo "⚠️ ADVERTENCIA: Fallo al conectar con la API de IA. Verificación omitida.\n";
    exit(0);
} catch (\Exception $e) {
    echo "⚠️ ADVERTENCIA: Error inesperado en la verificación de IA. Verificación omitida.\n";
    exit(0);
}

exit(0);