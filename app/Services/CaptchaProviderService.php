<?php

namespace App\Services;

use App\Models\CaptchaProvider;
use Illuminate\Database\Eloquent\Collection;

class CaptchaProviderService
{
    public function getAll(): Collection
    {
        return CaptchaProvider::all();
    }

    public function create(array $data): CaptchaProvider
    {
        return CaptchaProvider::create($data);
    }

    public function update(CaptchaProvider $provider, array $data): CaptchaProvider
    {
        $provider->update($data);
        return $provider;
    }

    public function delete(CaptchaProvider $provider): void
    {
        $provider->delete();
    }
}
