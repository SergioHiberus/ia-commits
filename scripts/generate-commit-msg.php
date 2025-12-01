<?php
// scripts/generate-commit-msg.php

// Ruta relativa al directorio 'vendor' desde 'scripts'
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- CONFIGURACIÓN ---
$apiKey = $_ENV['GEMINI_API_KEY'] ?? null;
$model = 'gemini-2.0-flash';
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";


if (!$apiKey) {
    // Si falta la clave, simplemente no generamos el borrador.
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
                'temperature' => 0.2, // Baja temperatura para respuestas más deterministas
                'maxOutputTokens' => 200,
            ]
        ],
        'timeout' => 10 // Timeout de 10s para no bloquear el flujo mucho tiempo
    ]);

    $body = json_decode($response->getBody(), true);

    // Extraer el texto de la respuesta de Gemini
    $generatedMessage = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($generatedMessage && $commitMsgFile) {
        $generatedMessage = trim($generatedMessage);

        // 4. Inserción: Anteponer el mensaje generado
        $header = "# 🤖 Sugerencia de IA (edita o borra según necesites):\n";
        $footer = "\n# ---------------------------------------------------\n";

        // Leer contenido original (comentarios de git, etc.)
        $originalContent = file_exists($commitMsgFile) ? file_get_contents($commitMsgFile) : '';

        // Escribir: Sugerencia + Separador + Original
        file_put_contents($commitMsgFile, $generatedMessage . $footer . $originalContent);
    }

} catch (\Exception $e) {
    // Silencio en caso de error para no interrumpir el flujo del usuario
    // Podríamos loguear a un archivo si fuera necesario
}

exit(0);