<?php
// scripts/verify-commit.php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- CONFIGURATION ---
$apiKey = $_ENV['GEMINI_API_KEY'] ?? null;
$model = 'gemini-2.0-flash';
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$apiKey";
// ---------------------

if (!$apiKey) {
    echo "️ERROR: GEMINI_API_KEY environment variable is not configured. AI verification skipped.\n";
    exit(0);
}

// 1. Get the commit message
$commitMsgFile = $argv[1] ?? null;
if (!$commitMsgFile || !file_exists($commitMsgFile)) {
    echo "WARNING: Could not find commit message file. Verification skipped.\n";
    exit(0);
}

$message = trim(file_get_contents($commitMsgFile));

if (empty($message)) {
    // Allow empty commit if the flow permits it
    exit(0);
}

// 2. Technical prompt for AI
$prompt = "Evaluate if the following commit message strictly follows the Conventional Commits standard (type(scope): description). 
Valid types are: feat, fix, docs, style, refactor, perf, test, build, ci, chore. 
Respond ONLY with a JSON object. 
If valid, 'valid' is true and 'reason' is null. 
If invalid, 'valid' is false and 'reason' explains the error concisely in English.
Message to evaluate: '$message'";

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

    // Clean markdown code blocks if AI adds them
    $text = preg_replace('/^```json\s*|\s*```$/', '', $text);

    $result = json_decode($text, true);

    if (isset($result['valid']) && $result['valid'] === false) {
        $reason = $result['reason'] ?? 'The format is incorrect.';
        echo "AI VALIDATION ERROR: The commit message does NOT meet the standard.\n";
        echo "AI reason: $reason\n";
        exit(1);
    }

} catch (RequestException $e) {
    echo "WARNING: Failed to connect to AI API. Verification skipped.\n";
    exit(0);
} catch (\Exception $e) {
    echo "WARNING: Unexpected error in AI verification. Verification skipped.\n";
    exit(0);
}

exit(0);