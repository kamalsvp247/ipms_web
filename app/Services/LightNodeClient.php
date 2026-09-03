<?php

namespace App\Services;

use App\Exceptions\LightNodeException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class LightNodeClient
{
    private const BASE_URL = 'https://openapi.lightnode.com';

    private function token(): string
    {
        $token = Setting::instance()->lightnode_api_token;

        if (! $token) {
            throw new LightNodeException('LightNode API token is not configured.');
        }

        return $token;
    }

    private function get(string $path, array $query = []): array
    {
        $response = Http::retry(3, 1000)
            ->withHeaders(['x-open-token' => $this->token()])
            ->get(self::BASE_URL.$path, $query);

        if (! $response->successful()) {
            throw new LightNodeException('LightNode API error ['.$path.']: '.$response->body());
        }

        return $response->json();
    }

    private function post(string $path, array $body = []): array
    {
        $response = Http::retry(3, 1000)
            ->withHeaders(['x-open-token' => $this->token()])
            ->post(self::BASE_URL.$path, $body);

        if (! $response->successful()) {
            throw new LightNodeException('LightNode API error ['.$path.']: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @return array<int, array{regionCode: string, regionName: string, zones: array}>
     */
    public function listRegions(): array
    {
        $data = $this->get('/region/list');

        return $data['regions'] ?? [];
    }

    /**
     * @return array<int, array{packageCode: string, cpu: int, memory: int, regionCode: string, zoneCode: string}>
     */
    public function listPlans(string $regionCode): array
    {
        $data = $this->get('/package/list', ['regionCode' => $regionCode]);

        return $data['packages'] ?? [];
    }

    /**
     * @return array<int, array{imageResourceUUID: string, imageName: string, osDistroVersion: string, osVersionDetail: string}>
     */
    public function listImages(string $regionCode, int $page = 1, int $pageSize = 50): array
    {
        $data = $this->get('/image/list', [
            'regionCode' => $regionCode,
            'imageType' => 'System',
            'page' => $page,
            'pageSize' => $pageSize,
        ]);

        return $data['images'] ?? [];
    }

    /**
     * Create a new VPS instance. Returns ['ecsResourceUUID', 'asyncTaskUUID'].
     *
     * @return array{ecsResourceUUID: string, asyncTaskUUID: string}
     */
    public function createInstance(
        string $instanceName,
        string $regionCode,
        string $zoneCode,
        string $planCode,
        string $imageUuid,
        string $password,
    ): array {
        $data = $this->post('/instance/create', [
            'packageConfig' => [
                'instanceName' => $instanceName,
                'regionCode' => $regionCode,
                'zoneCode' => $zoneCode,
                'packageCode' => $planCode,
                'imageResourceUUID' => $imageUuid,
                'password' => $password,
            ],
        ]);

        if (empty($data['ecsResourceUUID'])) {
            throw new LightNodeException('Create instance response missing ecsResourceUUID: '.json_encode($data));
        }

        return [
            'ecsResourceUUID' => $data['ecsResourceUUID'],
            'asyncTaskUUID' => $data['asyncTaskUUID'] ?? '',
        ];
    }

    /**
     * Get instance details. Returns null when the instance does not exist.
     *
     * @return array{ecsStatus: string, publicIpAddress: string|null, ecsResourceUUID: string}|null
     */
    public function getInstance(string $ecsResourceUuid): ?array
    {
        try {
            $data = $this->get('/instance/detail', ['ecsResourceUUID' => $ecsResourceUuid]);

            return $data['instance'] ?? null;
        } catch (LightNodeException) {
            return null;
        }
    }

    /**
     * Release (delete) a VPS instance.
     */
    public function releaseInstance(string $ecsResourceUuid): void
    {
        $this->post('/instance/release', ['ecsResourceUUID' => $ecsResourceUuid]);
    }
}
