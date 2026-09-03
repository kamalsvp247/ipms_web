<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'admin@test.com')->first();
$token = $user->createToken('x')->plainTextToken;

$client = Illuminate\Support\Facades\Http::withToken($token)->timeout(10);

$resp = $client->get('http://localhost:8000/api/captcha-algorithm/engine');
echo "Engine status: " . $resp->status() . "\n";
if ($resp->successful()) {
    echo json_encode($resp->json(), JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Body: " . $resp->body() . "\n";
}
