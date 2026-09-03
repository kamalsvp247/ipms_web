<?php

use App\Enums\CaptchaProviderType;
use App\Models\CaptchaProvider;
use App\Support\CaptchaVendorRotation;
use Illuminate\Support\Facades\Redis;

function rotationVendor(string $name, int $threads): CaptchaProvider
{
    return CaptchaProvider::factory()->create([
        'name' => $name,
        'type' => CaptchaProviderType::CapMonster,
        'enabled' => true,
        'solver_threads' => $threads,
    ]);
}

beforeEach(function () {
    // The cursor is shared across processes and survives a DB refresh, so a leftover
    // value from the previous test would shift every expected sequence by one.
    Redis::flushdb();
});

it('visits every vendor once before returning to any vendor second thread', function () {
    $vendors = collect([
        rotationVendor('A', 2),
        rotationVendor('B', 2),
        rotationVendor('C', 2),
    ]);

    $picked = collect(range(1, 6))->map(fn () => CaptchaVendorRotation::next($vendors)->name)->all();

    // Interleaved, not blocked: A,B,C then their second threads. A blocked lap
    // (A,A,B,B,C,C) would burst three requests at one vendor before touching the others.
    expect($picked)->toBe(['A', 'B', 'C', 'A', 'B', 'C']);
});

it('gives a vendor with more threads proportionally more turns per lap', function () {
    $vendors = collect([
        rotationVendor('Big', 3),
        rotationVendor('Small', 1),
    ]);

    $picked = collect(range(1, 8))->map(fn () => CaptchaVendorRotation::next($vendors)->name)->all();

    // Lap is Big,Small,Big,Big — weighted by threads, and Small still gets its turn
    // first time round rather than being starved behind Big's whole allocation.
    expect($picked)->toBe(['Big', 'Small', 'Big', 'Big', 'Big', 'Small', 'Big', 'Big']);
});

it('keeps a vendor saved with zero threads in the lap', function () {
    $vendors = collect([
        rotationVendor('Zero', 0),
        rotationVendor('One', 1),
    ]);

    expect(collect(CaptchaVendorRotation::lap($vendors))->pluck('name')->all())
        ->toBe(['Zero', 'One']);
});

it('advances the shared cursor so two processes never repeat a slot', function () {
    $vendors = collect([rotationVendor('A', 1), rotationVendor('B', 1)]);

    // Simulates the pool filler and a queue worker each claiming in the same instant.
    expect(CaptchaVendorRotation::next($vendors)->name)->toBe('A');
    expect(CaptchaVendorRotation::next($vendors)->name)->toBe('B');
    expect(CaptchaVendorRotation::next($vendors)->name)->toBe('A');
});

it('orders a first-fit walk from the cursor with every vendor listed once', function () {
    $vendors = collect([
        rotationVendor('A', 2),
        rotationVendor('B', 2),
        rotationVendor('C', 2),
    ]);

    // Cursor starts at A, so the walk prefers A and keeps the rest in rotation order
    // behind it — a full vendor falls through to the next in line, not to a random one.
    expect(collect(CaptchaVendorRotation::order($vendors))->pluck('name')->all())
        ->toBe(['A', 'B', 'C']);

    expect(collect(CaptchaVendorRotation::order($vendors))->pluck('name')->all())
        ->toBe(['B', 'C', 'A']);

    expect(collect(CaptchaVendorRotation::order($vendors))->pluck('name')->all())
        ->toBe(['C', 'A', 'B']);
});

it('returns nothing to rotate when there are no vendors', function () {
    expect(CaptchaVendorRotation::next(collect()))->toBeNull();
    expect(CaptchaVendorRotation::order(collect()))->toBe([]);
});

it('resets the cursor so a fresh start begins at the first vendor', function () {
    $vendors = collect([rotationVendor('A', 1), rotationVendor('B', 1)]);

    CaptchaVendorRotation::next($vendors);
    CaptchaVendorRotation::reset();

    expect(CaptchaVendorRotation::next($vendors)->name)->toBe('A');
});
