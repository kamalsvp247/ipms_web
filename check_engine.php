<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'admin@test.com')->first();
$client = Illuminate\Support\Facades\Http::withToken($user->createToken('test2')->plainTextToken)->timeout(15);

// Test engine
$resp = $client->get('http://localhost:8000/api/captcha-algorithm/engine');
echo "Engine: " . $resp->status() . "\n";
if ($resp->status() === 200) {
    $data = $resp->json();
    echo "  sidecar healthy: " . var_export($data['sidecar']['healthy'] ?? null, true) . "\n";
    echo "  needs_attention: " . var_export($data['needs_attention'] ?? null, true) . "\n";
} else {
    echo "  " . $resp->body() . "\n";
}

// Test versions
$resp2 = $client->get('http://localhost:8000/api/captcha-algorithm/versions');
echo "\nVersions: " . $resp2->status() . "\n";
if ($resp2->status() === 200) {
    $data2 = $resp2->json();
    echo "  versions count: " . count($data2['versions'] ?? []) . "\n";
    echo "  active: " . var_export($data2['active']['id'] ?? null, true) . "\n";
} else {
    echo "  " . $resp2->body() . "\n";
}

// Check log for errors
$log = file_get_contents(__DIR__.'/storage/logs/laravel.log');
echo "\n=== Recent log errors ===\n";
echo substr($log, -2000);
