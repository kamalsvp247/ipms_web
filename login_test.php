<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$user = User::where('email', 'admin@test.com')->first();
if (!$user) {
    echo "No user found";
    exit(1);
}

// Use the API login flow via HTTP
$client = new \GuzzleHttp\Client(['base_uri' => 'http://localhost:8000', 'http_errors' => false]);

$loginResp = $client->post('/api/login', [
    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
    'json' => ['email' => 'admin@test.com', 'password' => 'password'],
]);

$body = json_decode($loginResp->getBody(), true);
echo "Login status: " . $loginResp->getStatusCode() . "\n";

if ($loginResp->getStatusCode() === 200 && isset($body['token'])) {
    echo "TOKEN: " . $body['token'] . "\n";
    echo "USER: " . $body['user']['name'] . " ({$body['user']['role']})\n";
} else {
    echo "Response: " . json_encode($body, JSON_PRETTY_PRINT) . "\n";
}
