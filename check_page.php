<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'admin@test.com')->first();
$token = $user->createToken('test')->plainTextToken;

$client = Illuminate\Support\Facades\Http::withToken($token)->timeout(30);

echo "=== Engine ===\n";
$resp = $client->get('http://localhost:8000/api/captcha-algorithm/engine');
echo $resp->body() . "\n\n";

echo "=== Versions ===\n";
$resp2 = $client->get('http://localhost:8000/api/captcha-algorithm/versions');
echo $resp2->body() . "\n\n";

echo "=== Progress ===\n";
$resp3 = $client->get('http://localhost:8000/api/captcha-algorithm/progress');
echo $resp3->body() . "\n\n";

echo "=== History ===\n";
$resp4 = $client->get('http://localhost:8000/api/captcha-algorithm/history');
echo substr($resp4->body(), 0, 500) . "\n";
