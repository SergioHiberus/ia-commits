<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Start\n";
require __DIR__ . '/../vendor/autoload.php';
echo "Autoload loaded\n";

echo "Dir: " . __DIR__ . "\n";
echo "Target: " . realpath(__DIR__ . '/..') . "\n";
echo "File exists: " . (file_exists(__DIR__ . '/../.env') ? 'YES' : 'NO') . "\n";
echo "Content length: " . filesize(__DIR__ . '/../.env') . "\n";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
echo "Dotenv loaded\n";

$key = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');
echo "Key: " . ($key ? substr($key, 0, 5) . '...' : 'NULL') . "\n";
