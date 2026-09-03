<?php

use App\Models\Account;
use App\Models\PaymentLink;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Guards the four-tier hierarchy: super_admin > manager > sub_manager > agent.
 *
 * The property that matters most is LATERAL ISOLATION — one manager must never reach
 * another manager's agents, their accounts, their payment links, their logs, or their
 * stored passwords. Everything else (escalation guards, impersonation limits) exists to
 * stop that boundary being walked around, so each is pinned here from both sides: the
 * permitted case AND the denied case.
 */
function makeUser(string $name, string $role, ?int $parentId = null, ?string $email = null): User
{
    return User::create([
        'name' => $name,
        'email' => $email ?? "{$name}@hierarchy.test",
        'password' => Hash::make('secret'),
        'plain_password' => 'secret',
        'role' => $role,
        'parent_id' => $parentId,
        'is_approved' => true,
        'approved_at' => now(),
    ]);
}

function makeAccount(User $owner, string $phone): Account
{
    return Account::create([
        'user_id' => $owner->id,
        'phone' => $phone,
        'password' => 'pw',
        'is_active' => true,
        'status' => 'running',
    ]);
}

/**
 * Two disjoint manager subtrees plus an admin and an unparented agent. The orphan exists
 * to prove a manager's reach is defined by parent_id, not merely by "is an agent".
 */
beforeEach(function () {
    $this->admin = makeUser('admin', User::ROLE_SUPER_ADMIN);
    $this->mgrA = makeUser('mgrA', User::ROLE_MANAGER);
    $this->mgrB = makeUser('mgrB', User::ROLE_MANAGER);
    $this->agA1 = makeUser('agA1', User::ROLE_AGENT, $this->mgrA->id);
    $this->agA2 = makeUser('agA2', User::ROLE_AGENT, $this->mgrA->id);
    $this->agB1 = makeUser('agB1', User::ROLE_AGENT, $this->mgrB->id);
    $this->orphan = makeUser('orphan', User::ROLE_AGENT);

    $this->accA1 = makeAccount($this->agA1, '01A1');
    $this->accA2 = makeAccount($this->agA2, '01A2');
    $this->accMgrA = makeAccount($this->mgrA, '01MA');
    $this->accB1 = makeAccount($this->agB1, '01B1');

    foreach ([$this->accA1, $this->accB1] as $acc) {
        PaymentLink::create([
            'account_id' => $acc->id,
            'account_phone' => $acc->phone,
            'status_code' => 200,
        ]);
    }
});

it('resolves the visible user set per role', function () {
    // null means "no WHERE clause at all", not "an empty set" — callers depend on this.
    expect($this->admin->visibleUserIds())->toBeNull();
    expect($this->agA1->visibleUserIds())->toBe([$this->agA1->id]);

    $seen = $this->mgrA->visibleUserIds();
    sort($seen);
    $expected = [$this->mgrA->id, $this->agA1->id, $this->agA2->id];
    sort($expected);

    expect($seen)->toBe($expected);
    expect($seen)->not->toContain($this->mgrB->id)
        ->and($seen)->not->toContain($this->agB1->id)
        ->and($seen)->not->toContain($this->orphan->id);
});

it('scopes accounts to the manager subtree', function () {
    $scoped = function (User $user): array {
        $query = Account::query();
        if (($ids = $user->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $ids);
        }

        return $query->pluck('phone')->sort()->values()->all();
    };

    expect($scoped($this->mgrA))->toBe(['01A1', '01A2', '01MA']);
    expect($scoped($this->agA1))->toBe(['01A1']);
    expect($scoped($this->admin))->toBe(['01A1', '01A2', '01B1', '01MA']);
});

it('scopes phone-keyed records like payment links to the manager subtree', function () {
    $phones = $this->mgrA->visibleAccountPhones();

    expect($phones)->toContain('01A1', '01MA')
        ->and($phones)->not->toContain('01B1');

    expect(PaymentLink::whereIn('account_phone', $phones)->pluck('account_phone')->all())
        ->toBe(['01A1']);

    expect(PaymentLink::whereIn('account_phone', $this->agA1->visibleAccountPhones())->pluck('account_phone')->all())
        ->toBe(['01A1']);
});

it('scopes the otps endpoint to the manager subtree', function () {
    \App\Models\OtpCode::create(['phone' => '01A1', 'otp_code' => '111111', 'is_ivacbd' => true, 'fetched_at' => now('Asia/Dhaka')]);
    \App\Models\OtpCode::create(['phone' => '01B1', 'otp_code' => '222222', 'is_ivacbd' => true, 'fetched_at' => now('Asia/Dhaka')]);
    \App\Models\OtpCode::create(['phone' => '01UNASSIGNED', 'otp_code' => '333333', 'is_ivacbd' => true, 'fetched_at' => now('Asia/Dhaka')]);

    $agentPhones = fn ($response) => collect($response->json('data'))->pluck('phone')->all();

    $asAgent = $this->actingAs($this->agA1)->getJson('/api/otps')->assertOk();
    expect($agentPhones($asAgent))->toBe(['01A1']);

    $asManager = $this->actingAs($this->mgrA)->getJson('/api/otps')->assertOk();
    expect($agentPhones($asManager))->toBe(['01A1']);

    $asOtherManager = $this->actingAs($this->mgrB)->getJson('/api/otps')->assertOk();
    expect($agentPhones($asOtherManager))->toBe(['01B1']);

    $asAdmin = $this->actingAs($this->admin)->getJson('/api/otps')->assertOk();
    expect($agentPhones($asAdmin))->toEqualCanonicalizing(['01A1', '01B1', '01UNASSIGNED']);
});

it('confines otp deletion to the manager subtree', function () {
    $ownOtp = \App\Models\OtpCode::create(['phone' => '01A1', 'otp_code' => '111111', 'is_ivacbd' => true, 'fetched_at' => now('Asia/Dhaka')]);
    $otherOtp = \App\Models\OtpCode::create(['phone' => '01B1', 'otp_code' => '222222', 'is_ivacbd' => true, 'fetched_at' => now('Asia/Dhaka')]);

    $this->actingAs($this->agA1)
        ->deleteJson('/api/otps', ['ids' => [$ownOtp->id, $otherOtp->id]])
        ->assertOk()
        ->assertJson(['deleted' => 1]);

    $this->assertModelMissing($ownOtp);
    $this->assertModelExists($otherOtp);
});

it('confines a manager to the agents they created', function () {
    expect($this->mgrA->canManageUser($this->agA1))->toBeTrue();

    // The isolation boundary, from every direction it could be crossed.
    expect($this->mgrA->canManageUser($this->agB1))->toBeFalse();
    expect($this->mgrA->canManageUser($this->mgrB))->toBeFalse();
    expect($this->mgrA->canManageUser($this->admin))->toBeFalse();
    expect($this->mgrA->canManageUser($this->orphan))->toBeFalse();
    expect($this->mgrA->canManageUser($this->mgrA))->toBeFalse();

    expect($this->agA1->canManageUser($this->agA2))->toBeFalse();
    expect($this->agA1->canManageUser($this->mgrA))->toBeFalse();

    expect($this->admin->canManageUser($this->mgrA))->toBeTrue();
    expect($this->admin->canManageUser($this->agB1))->toBeTrue();
    expect($this->admin->canManageUser($this->admin))->toBeFalse();
});

it('never reveals another manager agents credentials', function () {
    expect($this->mgrA->can('viewCredentials', $this->agA1))->toBeTrue();
    expect($this->mgrA->can('viewCredentials', $this->agB1))->toBeFalse();
    expect($this->mgrA->can('viewCredentials', $this->mgrB))->toBeFalse();
});

it('limits impersonation to the actor own subtree', function () {
    expect($this->mgrA->can('impersonate', $this->agA1))->toBeTrue();
    expect($this->mgrA->can('impersonate', $this->agB1))->toBeFalse();
    expect($this->mgrA->can('impersonate', $this->admin))->toBeFalse();
    expect($this->agA1->can('impersonate', $this->agA2))->toBeFalse();
    expect($this->admin->can('impersonate', $this->agB1))->toBeTrue();
});

it('applies the subtree rule to the user policy', function () {
    expect($this->mgrA->can('update', $this->agA1))->toBeTrue();
    expect($this->mgrA->can('update', $this->agB1))->toBeFalse();
    expect($this->mgrA->can('delete', $this->agB1))->toBeFalse();

    // Viewing your own record is always allowed, even though managing it is not.
    expect($this->mgrA->can('view', $this->mgrA))->toBeTrue();
    expect($this->mgrA->can('view', $this->mgrB))->toBeFalse();
    expect($this->agA1->can('view', $this->agA1))->toBeTrue();
    expect($this->agA1->can('view', $this->agA2))->toBeFalse();
});

it('lets a manager assign only their own subtree accounts to a worker slot', function () {
    $slot = \App\Models\AgentSlot::create([
        'name' => 'slot-1',
        'api_key' => \App\Models\AgentSlot::generateApiKey(),
        'status' => 'offline',
        'worker_state' => 'idle',
    ]);

    $response = $this->actingAs($this->mgrA)->putJson('/api/accounts/bulk-assign', [
        'account_ids' => [$this->accA1->id, $this->accB1->id],
        'agent_slot_id' => $slot->id,
    ]);

    $response->assertOk()->assertJson(['updated' => 1]);
    expect($this->accA1->fresh()->agent_slot_id)->toBe($slot->id);
    expect($this->accB1->fresh()->agent_slot_id)->toBeNull();
});

it('counts only the caller subtree in a worker slot account count', function () {
    $slot = \App\Models\AgentSlot::create([
        'name' => 'slot-count',
        'api_key' => \App\Models\AgentSlot::generateApiKey(),
        'status' => 'offline',
        'worker_state' => 'idle',
    ]);

    Account::whereIn('id', [$this->accA1->id, $this->accB1->id])->update(['agent_slot_id' => $slot->id]);

    $mgrCount = collect($this->actingAs($this->mgrA)->getJson('/api/agent-slots')->json())
        ->firstWhere('id', $slot->id)['accounts_count'];
    $adminCount = collect($this->actingAs($this->admin)->getJson('/api/agent-slots')->json())
        ->firstWhere('id', $slot->id)['accounts_count'];

    expect($mgrCount)->toBe(1);
    expect($adminCount)->toBe(2);
});

it('lets an admin assign any account to a worker slot via bulk-assign', function () {
    $slot = \App\Models\AgentSlot::create([
        'name' => 'slot-2',
        'api_key' => \App\Models\AgentSlot::generateApiKey(),
        'status' => 'offline',
        'worker_state' => 'idle',
    ]);

    $response = $this->actingAs($this->admin)->putJson('/api/accounts/bulk-assign', [
        'account_ids' => [$this->accA1->id, $this->accB1->id],
        'agent_slot_id' => $slot->id,
    ]);

    $response->assertOk()->assertJson(['updated' => 2]);
});

it('applies the subtree rule to the account policy', function () {
    // The account lock window is on by default and would deny the agent regardless of the
    // subtree, which is the one rule this test is about. Switch it off so the assertions
    // below do not depend on the wall clock.
    Setting::instance()->update(['account_lock_enabled' => false]);

    expect($this->mgrA->can('update', $this->accA1))->toBeTrue();
    expect($this->mgrA->can('update', $this->accB1))->toBeFalse();
    expect($this->mgrA->can('view', $this->accB1))->toBeFalse();
    expect($this->agA1->can('update', $this->accA1))->toBeTrue();
    expect($this->agA1->can('update', $this->accA2))->toBeFalse();
    expect($this->admin->can('update', $this->accB1))->toBeTrue();
});

it('prevents a manager from escalating anyone', function () {
    // A manager mints only roles below their own, so there is no path to a peer or an admin.
    expect($this->mgrA->assignableRoles())->toBe([User::ROLE_SUB_MANAGER, User::ROLE_AGENT]);
    expect($this->admin->assignableRoles())->toBe(User::ROLES);
    expect($this->agA1->assignableRoles())->toBe([]);

    expect($this->mgrA->can('bot.manage'))->toBeFalse();
    expect($this->agA1->hasPermission('users.manage'))->toBeFalse();
    expect($this->agA1->hasPermission('logs.read'))->toBeFalse();
});

it('grants managers user administration and log access', function () {
    expect($this->mgrA->hasPermission('users.manage'))->toBeTrue();
    expect($this->mgrA->hasPermission('logs.read'))->toBeTrue();
});

/**
 * The legacy 'user' role was renamed to 'agent'; agents must keep exactly the surface they
 * had before so the rename is not a silent downgrade.
 */
it('preserves the legacy agent permission set', function () {
    foreach (['accounts.read', 'accounts.write', 'captcha.read', 'captcha.write', 'proxies.read', 'proxies.write', 'settings.read', 'settings.write'] as $permission) {
        expect($this->agA1->hasPermission($permission))->toBeTrue("agent lost {$permission}");
    }
});

it('denies agents the api-tester page and api routes while managers and admins keep it', function () {
    $this->actingAs($this->agA1)->get('/api-tester')->assertForbidden();
    $this->actingAs($this->agA1)->getJson('/api/api-tester/context')->assertForbidden();

    $this->actingAs($this->mgrA)->get('/api-tester')->assertOk();
    $this->actingAs($this->admin)->get('/api-tester')->assertOk();
});

it('auto-generates a unique referral code for every manager', function () {
    expect($this->mgrA->referral_code)->not->toBeNull();
    expect($this->mgrB->referral_code)->not->toBeNull();
    expect($this->mgrA->referral_code)->not->toBe($this->mgrB->referral_code);
});

it('leaves referral_code null for agents and admins', function () {
    expect($this->agA1->referral_code)->toBeNull();
    expect($this->admin->referral_code)->toBeNull();
});

it('never collides referral codes across many managers with the same name', function () {
    // Same name (so the slugged prefix is identical) but distinct emails, which is the only
    // thing that stops the insert itself failing before the code generator is exercised.
    $codes = collect(range(1, 10))
        ->map(fn (int $i) => makeUser('mgrA', User::ROLE_MANAGER, null, "dupName{$i}@hierarchy.test")->referral_code)
        ->unique();

    expect($codes)->toHaveCount(10);
});

it('exposes the manager and agents relations', function () {
    expect($this->mgrA->agents()->count())->toBe(2);
    expect($this->agA1->manager->id)->toBe($this->mgrA->id);
    expect($this->mgrA->manager)->toBeNull();
});

it('lists only the manager own subtree from the users endpoint', function () {
    $response = $this->actingAs($this->mgrA)->getJson('/api/users');

    $response->assertOk();
    $emails = collect($response->json('data'))->pluck('email')->all();

    sort($emails);
    expect($emails)->toBe(['agA1@hierarchy.test', 'agA2@hierarchy.test', 'mgrA@hierarchy.test']);
});

it('denies an agent access to the users endpoint', function () {
    $this->actingAs($this->agA1)->getJson('/api/users')->assertForbidden();
});

it('forbids a manager from editing another manager agent over http', function () {
    $this->actingAs($this->mgrA)
        ->putJson("/api/users/{$this->agB1->id}", ['name' => 'hijacked'])
        ->assertForbidden();

    expect($this->agB1->fresh()->name)->toBe('agB1');
});

it('forces a manager created user to be an agent under that manager', function () {
    $response = $this->actingAs($this->mgrA)->postJson('/api/users', [
        'name' => 'newbie',
        'email' => 'newbie@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_AGENT,
    ]);

    $response->assertCreated();

    $created = User::where('email', 'newbie@hierarchy.test')->first();
    expect($created->role)->toBe(User::ROLE_AGENT);
    expect($created->parent_id)->toBe($this->mgrA->id);
});

it('rejects a manager attempting to create a peer manager or an admin', function (string $role) {
    $this->actingAs($this->mgrA)->postJson('/api/users', [
        'name' => 'escalated',
        'email' => 'escalated@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => $role,
    ])->assertStatus(422);

    expect(User::where('email', 'escalated@hierarchy.test')->exists())->toBeFalse();
})->with([User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN]);

it('ignores a manager attempt to re-home or re-role their own agent', function () {
    $this->actingAs($this->mgrA)
        ->putJson("/api/users/{$this->agA1->id}", [
            'name' => 'renamed',
            'parent_id' => $this->mgrB->id,
        ])->assertStatus(422);

    $fresh = $this->agA1->fresh();
    expect($fresh->parent_id)->toBe($this->mgrA->id);
    expect($fresh->role)->toBe(User::ROLE_AGENT);
});

it('scopes the users endpoint totals to the manager subtree', function () {
    $response = $this->actingAs($this->mgrA)->getJson('/api/users');

    // 01A1, 01A2 and 01MA — never agent B1's account.
    expect($response->json('total_accounts'))->toBe(3);
    expect($response->json('booked_today'))->toBe(1);
});

it('hides another manager agent credentials over http', function () {
    $rows = collect($this->actingAs($this->mgrA)->getJson('/api/users')->json('data'));

    // Own agents come back with the stored password; nobody else is even in the payload.
    expect($rows->firstWhere('email', 'agA1@hierarchy.test'))->toHaveKey('plain_password');
    expect($rows->pluck('email'))->not->toContain('agB1@hierarchy.test');
});

it('offers a manager the roles below them and no manager picker', function () {
    $response = $this->actingAs($this->mgrA)->getJson('/api/users/options');

    $response->assertOk();
    expect($response->json('roles'))->toBe([User::ROLE_SUB_MANAGER, User::ROLE_AGENT]);
    expect($response->json('managers'))->toBe([]);
});

it('offers an admin every role and the manager list', function () {
    $response = $this->actingAs($this->admin)->getJson('/api/users/options');

    $response->assertOk();
    expect($response->json('roles'))->toBe(User::ROLES);

    // Asserted by containment: the migrations seed an owner of their own, so the list is
    // never exactly the fixtures this test created.
    $owners = collect($response->json('managers'));
    expect($owners->pluck('name'))->toContain('mgrA', 'mgrB');
    expect($owners->pluck('role')->unique()->all())->toBe([User::ROLE_MANAGER]);
});

it('scopes the accounts endpoint to the manager subtree', function () {
    $phones = collect($this->actingAs($this->mgrA)->getJson('/api/accounts')->json('data'))
        ->pluck('phone')->sort()->values()->all();

    expect($phones)->toBe(['01A1', '01A2', '01MA']);
});

it('forbids a manager from reading another manager agent account', function () {
    $this->actingAs($this->mgrA)->getJson("/api/accounts/{$this->accB1->id}")->assertForbidden();
});

/**
 * The sub manager tier: manager > sub_manager > agent.
 *
 * A sub manager is a manager in privilege but not in reach — it owns only the agents it
 * created, while the manager above it owns the whole branch. Both halves of that are pinned
 * here, because either one failing is a data leak: a sub manager seeing a peer's agents, or
 * a manager losing sight of work done inside their own subtree.
 */
function makeSubtree(): array
{
    $mgr = makeUser('subMgrParent', User::ROLE_MANAGER);
    $sub = makeUser('sub1', User::ROLE_SUB_MANAGER, $mgr->id);
    $subPeer = makeUser('sub2', User::ROLE_SUB_MANAGER, $mgr->id);
    $subAgent = makeUser('subAgent', User::ROLE_AGENT, $sub->id);
    $peerAgent = makeUser('peerAgent', User::ROLE_AGENT, $subPeer->id);
    $directAgent = makeUser('directAgent', User::ROLE_AGENT, $mgr->id);

    return compact('mgr', 'sub', 'subPeer', 'subAgent', 'peerAgent', 'directAgent');
}

it('grants a sub manager the manager permission set minus the bot fleet', function () {
    $sub = makeUser('permSub', User::ROLE_SUB_MANAGER);

    foreach (['accounts.read', 'accounts.write', 'captcha.read', 'captcha.write', 'proxies.read', 'proxies.write', 'settings.read', 'settings.write', 'users.manage', 'logs.read'] as $permission) {
        expect($sub->hasPermission($permission))->toBeTrue("sub manager lost {$permission}");
    }

    // Bot Control and the request tester belong to the manager tier and above.
    expect($sub->hasPermission('accounts.assign'))->toBeFalse();
    expect($sub->can('accounts.assign'))->toBeFalse();
    expect($sub->can('api-tester.access'))->toBeFalse();

    // Admin-only surface stays admin-only.
    expect($sub->can('bot.manage'))->toBeFalse();
});

it('reaches a manager subtree through its sub managers', function () {
    ['mgr' => $mgr, 'sub' => $sub, 'subAgent' => $subAgent, 'directAgent' => $directAgent, 'peerAgent' => $peerAgent, 'subPeer' => $subPeer] = makeSubtree();

    $seen = $mgr->visibleUserIds();
    sort($seen);
    $expected = [$mgr->id, $sub->id, $subPeer->id, $subAgent->id, $peerAgent->id, $directAgent->id];
    sort($expected);

    expect($seen)->toBe($expected);

    // The sub manager sees only its own branch — never its peer, its peer's agents, or the
    // manager above it.
    $subSeen = $sub->visibleUserIds();
    sort($subSeen);
    expect($subSeen)->toBe([$sub->id, $subAgent->id]);
    expect($subSeen)->not->toContain($mgr->id)
        ->and($subSeen)->not->toContain($subPeer->id)
        ->and($subSeen)->not->toContain($peerAgent->id);

    expect($subAgent->visibleUserIds())->toBe([$subAgent->id]);
});

it('scopes accounts down the whole manager branch', function () {
    ['mgr' => $mgr, 'sub' => $sub, 'subAgent' => $subAgent, 'peerAgent' => $peerAgent] = makeSubtree();

    makeAccount($subAgent, '01SUBA');
    makeAccount($peerAgent, '01PEER');

    $phones = collect($this->actingAs($mgr)->getJson('/api/accounts')->json('data'))->pluck('phone')->sort()->values()->all();
    expect($phones)->toBe(['01PEER', '01SUBA']);

    $subPhones = collect($this->actingAs($sub)->getJson('/api/accounts')->json('data'))->pluck('phone')->all();
    expect($subPhones)->toBe(['01SUBA']);
});

it('confines management to the tier below inside the same branch', function () {
    ['mgr' => $mgr, 'sub' => $sub, 'subPeer' => $subPeer, 'subAgent' => $subAgent, 'peerAgent' => $peerAgent] = makeSubtree();

    // A manager owns its sub managers and everything under them.
    expect($mgr->canManageUser($sub))->toBeTrue();
    expect($mgr->canManageUser($subAgent))->toBeTrue();

    // A sub manager owns its own agents only.
    expect($sub->canManageUser($subAgent))->toBeTrue();
    expect($sub->canManageUser($peerAgent))->toBeFalse();
    expect($sub->canManageUser($subPeer))->toBeFalse();
    expect($sub->canManageUser($mgr))->toBeFalse();
    expect($sub->canManageUser($this->admin))->toBeFalse();
    expect($sub->canManageUser($this->agA1))->toBeFalse();

    // Credentials and impersonation follow the same boundary.
    expect($mgr->can('viewCredentials', $subAgent))->toBeTrue();
    expect($sub->can('viewCredentials', $peerAgent))->toBeFalse();
    expect($sub->can('impersonate', $subAgent))->toBeTrue();
    expect($sub->can('impersonate', $subPeer))->toBeFalse();
});

it('lets a manager mint a sub manager under themselves', function () {
    $mgr = makeUser('minting', User::ROLE_MANAGER);

    $this->actingAs($mgr)->postJson('/api/users', [
        'name' => 'freshSub',
        'email' => 'freshSub@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_SUB_MANAGER,
    ])->assertCreated();

    $created = User::where('email', 'freshSub@hierarchy.test')->first();
    expect($created->role)->toBe(User::ROLE_SUB_MANAGER);
    expect($created->parent_id)->toBe($mgr->id);
    expect($created->referral_code)->not->toBeNull();
});

it('lets a sub manager mint only agents, parented to itself', function () {
    $sub = makeUser('mintingSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    expect($sub->assignableRoles())->toBe([User::ROLE_AGENT]);

    $this->actingAs($sub)->postJson('/api/users', [
        'name' => 'subsAgent',
        'email' => 'subsAgent@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_AGENT,
    ])->assertCreated();

    $created = User::where('email', 'subsAgent@hierarchy.test')->first();
    expect($created->role)->toBe(User::ROLE_AGENT);
    expect($created->parent_id)->toBe($sub->id);
});

it('rejects a sub manager creating a peer sub manager or higher', function (string $role) {
    $sub = makeUser('escalatingSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    $this->actingAs($sub)->postJson('/api/users', [
        'name' => 'escalated',
        'email' => 'escalatedSub@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => $role,
    ])->assertStatus(422);

    expect(User::where('email', 'escalatedSub@hierarchy.test')->exists())->toBeFalse();
})->with([User::ROLE_SUB_MANAGER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN]);

it('never lets a sub manager reach another branch over http', function () {
    ['sub' => $sub, 'peerAgent' => $peerAgent] = makeSubtree();

    $this->actingAs($sub)->putJson("/api/users/{$peerAgent->id}", ['name' => 'hijacked'])->assertForbidden();
    expect($peerAgent->fresh()->name)->toBe('peerAgent');

    $emails = collect($this->actingAs($sub)->getJson('/api/users')->json('data'))->pluck('email')->sort()->values()->all();
    expect($emails)->toBe(['sub1@hierarchy.test', 'subAgent@hierarchy.test']);
});

it('lists a manager whole branch from the users endpoint', function () {
    ['mgr' => $mgr] = makeSubtree();

    $emails = collect($this->actingAs($mgr)->getJson('/api/users')->json('data'))->pluck('email')->sort()->values()->all();

    expect($emails)->toBe([
        'directAgent@hierarchy.test',
        'peerAgent@hierarchy.test',
        'sub1@hierarchy.test',
        'sub2@hierarchy.test',
        'subAgent@hierarchy.test',
        'subMgrParent@hierarchy.test',
    ]);
});

it('requires an admin to place a sub manager under a manager', function () {
    // No owner at all.
    $this->actingAs($this->admin)->postJson('/api/users', [
        'name' => 'orphanSub',
        'email' => 'orphanSub@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_SUB_MANAGER,
    ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

    // An owner of the wrong tier: a sub manager may never own another sub manager.
    $existingSub = makeUser('ownerSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    $this->actingAs($this->admin)->postJson('/api/users', [
        'name' => 'nestedSub',
        'email' => 'nestedSub@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_SUB_MANAGER,
        'parent_id' => $existingSub->id,
    ])->assertStatus(422)->assertJsonValidationErrors('parent_id');

    $this->actingAs($this->admin)->postJson('/api/users', [
        'name' => 'placedSub',
        'email' => 'placedSub@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_SUB_MANAGER,
        'parent_id' => $this->mgrA->id,
    ])->assertCreated();
});

it('lets an admin park an agent under a sub manager', function () {
    $sub = makeUser('parkingSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    $this->actingAs($this->admin)->postJson('/api/users', [
        'name' => 'parkedAgent',
        'email' => 'parkedAgent@hierarchy.test',
        'password' => 'Tr7$Vq9Xz2Lm',
        'role' => User::ROLE_AGENT,
        'parent_id' => $sub->id,
    ])->assertCreated();

    expect(User::where('email', 'parkedAgent@hierarchy.test')->first()->parent_id)->toBe($sub->id);

    // ...and the manager above still sees it, since the branch is theirs.
    expect($this->mgrA->visibleUserIds())->toContain($sub->id);
});

it('blocks a promotion that would strand a sub manager without a manager', function () {
    $sub = makeUser('promoSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    // Promoting the orphan agent to sub manager leaves it with no owner at all.
    $this->actingAs($this->admin)
        ->putJson("/api/users/{$this->orphan->id}", ['role' => User::ROLE_SUB_MANAGER])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');

    expect($this->orphan->fresh()->role)->toBe(User::ROLE_AGENT);

    // With an owner named it goes through, and the sub manager itself may be re-homed.
    $this->actingAs($this->admin)
        ->putJson("/api/users/{$this->orphan->id}", ['role' => User::ROLE_SUB_MANAGER, 'parent_id' => $this->mgrB->id])
        ->assertOk();

    expect($this->orphan->fresh()->parent_id)->toBe($this->mgrB->id);
    expect($sub->fresh()->parent_id)->toBe($this->mgrA->id);
});

it('lets an agent self-register against a sub manager referral code', function () {
    $sub = makeUser('referralSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    expect($sub->referral_code)->not->toBeNull();

    $registered = User::create([
        'name' => 'selfSignup',
        'email' => 'selfSignup@hierarchy.test',
        'password' => Hash::make('secret'),
        'role' => User::ROLE_AGENT,
        'parent_id' => User::where('referral_code', $sub->referral_code)->whereIn('role', User::OWNER_ROLES)->firstOrFail()->id,
    ]);

    expect($registered->parent_id)->toBe($sub->id);
    // The manager above inherits the signup without doing anything.
    expect($this->mgrA->visibleUserIds())->toContain($registered->id);
});

it('keeps sub managers out of the agent-only lock window', function () {
    $sub = makeUser('lockSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    expect(\App\Support\AccountLockWindow::blocks($sub))->toBeFalse();
    expect(\App\Support\AccountLockWindow::blocks($this->agA1))->toBe(\App\Support\AccountLockWindow::isActive());
});

it('keeps the notice surface with managers only', function () {
    $sub = makeUser('surfaceSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    expect($this->mgrA->can('notice.write'))->toBeTrue();
    expect($sub->can('notice.write'))->toBeFalse();
    expect($this->agA1->can('notice.write'))->toBeFalse();

    $this->actingAs($sub)->get('/notice')->assertForbidden();
    $this->actingAs($this->mgrA)->get('/notice')->assertOk();
});

it('denies sub managers bot control and the api tester while managers keep both', function () {
    $sub = makeUser('fleetSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    $this->actingAs($sub)->get('/bot-control')->assertForbidden();
    $this->actingAs($sub)->get('/api-tester')->assertForbidden();
    $this->actingAs($sub)->getJson('/api/agent-slots')->assertForbidden();
    $this->actingAs($sub)->getJson('/api/api-tester/context')->assertForbidden();

    $this->actingAs($this->mgrA)->get('/bot-control')->assertOk();
    $this->actingAs($this->mgrA)->get('/api-tester')->assertOk();
});

it('offers an admin the sub manager role and both owner tiers as parents', function () {
    $sub = makeUser('optionsSub', User::ROLE_SUB_MANAGER, $this->mgrA->id);

    $response = $this->actingAs($this->admin)->getJson('/api/users/options');

    $response->assertOk();
    expect($response->json('roles'))->toBe(User::ROLES);
    $owners = collect($response->json('managers'));
    expect($owners->pluck('name'))->toContain('mgrA', 'mgrB', 'optionsSub');
    expect($owners->firstWhere('name', 'optionsSub')['role'])->toBe(User::ROLE_SUB_MANAGER);
    expect($owners->firstWhere('id', $sub->id))->not->toBeNull();
});
