<?php

use App\Enums\CaptchaProviderType;
use App\Models\CaptchaProvider;
use App\Support\CaptchaDemandSplit;

/**
 * @return array{0: CaptchaProvider, 1: CaptchaProvider, 2: CaptchaProvider}
 */
function tiers(): array
{
    $vendorA = CaptchaProvider::factory()->create(['type' => CaptchaProviderType::CapMonster, 'solver_threads' => 2]);
    $vendorB = CaptchaProvider::factory()->create(['type' => CaptchaProviderType::CapSolver, 'solver_threads' => 3]);
    $inHouse = CaptchaProvider::factory()->create(['type' => CaptchaProviderType::InHouse, 'solver_threads' => 10]);

    return [$inHouse, $vendorA, $vendorB];
}

/** @param list<CaptchaProvider> $plan */
function countsById(array $plan): array
{
    $counts = [];

    foreach ($plan as $provider) {
        $counts[$provider->id] = ($counts[$provider->id] ?? 0) + 1;
    }

    return $counts;
}

it('fills every free slot across both tiers in one pass', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();

    // Deficit larger than what anyone can start: every provider must be handed its full
    // free capacity, so the pool fills at the sum of the two tiers rather than either alone.
    $plan = CaptchaDemandSplit::plan(250, collect([$vendorA, $vendorB, $inHouse]), 45);

    expect(countsById($plan))->toEqual([
        $vendorA->id => 2,
        $vendorB->id => 3,
        $inHouse->id => 45,
    ]);
    expect($plan)->toHaveCount(50);
});

it('reaches the vendors even when the fleet alone could absorb the whole pass', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();

    // The regression this replaces: a deficit under the fleet's budget used to go entirely
    // to the fleet, so the paid providers sat idle waiting for in-house to fill up first.
    $plan = CaptchaDemandSplit::plan(3, collect([$vendorA, $vendorB, $inHouse]), 45);

    expect($plan)->toHaveCount(3);
    expect(collect($plan)->pluck('id')->unique())->toHaveCount(3);
});

it('gives a saturated tier no slots without holding the other one back', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();

    // Fleet fully committed; the vendors must still be dispatched their own capacity.
    $plan = CaptchaDemandSplit::plan(250, collect([$vendorA, $vendorB, $inHouse]), 45, [$inHouse->id => 45]);

    expect(countsById($plan))->toEqual([$vendorA->id => 2, $vendorB->id => 3]);

    // And the mirror image: busy vendors must not cost the fleet its slots.
    $plan = CaptchaDemandSplit::plan(250, collect([$vendorA, $vendorB, $inHouse]), 45, [
        $vendorA->id => 2,
        $vendorB->id => 3,
    ]);

    expect(countsById($plan))->toEqual([$inHouse->id => 45]);
});

it('counts work already dispatched but not yet started against the free slots', function () {
    [$inHouse, $vendorA] = tiers();

    // Without this the same free slot is re-dispatched every 50ms until a worker claims it,
    // burying the queue in captchas no provider has room to run.
    $plan = CaptchaDemandSplit::plan(250, collect([$vendorA, $inHouse]), 10, [
        $vendorA->id => 1,
        $inHouse->id => 8,
    ]);

    expect(countsById($plan))->toEqual([$vendorA->id => 1, $inHouse->id => 2]);
});

it('dispatches nothing when every provider is at capacity', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();

    $plan = CaptchaDemandSplit::plan(250, collect([$vendorA, $vendorB, $inHouse]), 45, [
        $vendorA->id => 2,
        $vendorB->id => 3,
        $inHouse->id => 45,
    ]);

    // Back-pressure, not a stall: the next pass runs 50ms later and picks up whatever freed.
    expect($plan)->toBe([]);
});

it('skips the fleet entirely when no solver node is online', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();

    // queueLimit() is 0 with an empty fleet, and a captcha pinned there would only fail.
    $plan = CaptchaDemandSplit::plan(250, collect([$vendorA, $vendorB, $inHouse]), 0);

    expect(countsById($plan))->toEqual([$vendorA->id => 2, $vendorB->id => 3]);
});

it('rotates which vendor leads so a short top-up does not always land on the same one', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();
    $providers = collect([$vendorA, $vendorB, $inHouse]);

    // A pass of two fills the fleet lane then one vendor lane. Over consecutive passes that
    // vendor must change, or the lowest id takes every scarce top-up for good.
    $vendorsLed = collect(range(0, 1))
        ->map(fn (int $pass) => CaptchaDemandSplit::plan(2, $providers, 45, [], $pass)[1]->id);

    expect($vendorsLed->unique())->toHaveCount(2);
});

it('backs the tail of a fill with spare fleet capacity so one slow vendor cannot set the pace', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();
    $providers = collect([$vendorA, $vendorB, $inHouse]);

    // The end of a fill: in-flight work covers the whole remaining deficit, so the deficit
    // itself is zero and the old rule dispatched nothing — leaving four tokens to arrive
    // whenever the provider holding them got round to it.
    $plan = CaptchaDemandSplit::plan(
        needed: 0,
        providers: $providers,
        fleetLimit: 45,
        inFlight: [$vendorA->id => 2, $vendorB->id => 3, $inHouse->id => 1],
        fleetSlack: 4,
    );

    // Fleet only: a surplus token on bought hardware is free, on a paid key it is waste.
    expect(countsById($plan))->toEqual([$inHouse->id => 4]);
});

it('never lets the tail push the fleet past its in-flight ceiling', function () {
    [$inHouse, $vendorA] = tiers();

    $plan = CaptchaDemandSplit::plan(
        needed: 0,
        providers: collect([$vendorA, $inHouse]),
        fleetLimit: 10,
        inFlight: [$inHouse->id => 8],
        fleetSlack: 12,
    );

    expect(countsById($plan))->toEqual([$inHouse->id => 2]);
});

it('adds no tail when the fleet is unavailable', function () {
    [$inHouse, $vendorA] = tiers();

    // No node online, so the fleet has no capacity to lend and the vendors must not be handed
    // surplus work to make up for it — the pool waits instead of paying for tokens it did not ask for.
    $plan = CaptchaDemandSplit::plan(
        needed: 0,
        providers: collect([$vendorA, $inHouse]),
        fleetLimit: 0,
        inFlight: [],
        fleetSlack: 12,
    );

    expect($plan)->toBe([]);
});

it('never plans more than the pool is short', function () {
    [$inHouse, $vendorA, $vendorB] = tiers();

    expect(CaptchaDemandSplit::plan(7, collect([$vendorA, $vendorB, $inHouse]), 45))->toHaveCount(7);
    expect(CaptchaDemandSplit::plan(0, collect([$vendorA, $vendorB, $inHouse]), 45))->toBe([]);
    expect(CaptchaDemandSplit::plan(-5, collect([$vendorA, $vendorB, $inHouse]), 45))->toBe([]);
});
