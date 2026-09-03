<?php

use App\Models\User;

describe('UserController', function () {
    // ── index ──────────────────────────────────────────────────────────────

    it('requires bot.manage to list users', function () {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson('/api/users')
            ->assertForbidden();
    });

    it('returns paginated users for super admin', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory(3)->create();

        $this->actingAs($admin)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('total', 4); // 3 + admin
    });

    it('filters users by name or email', function () {
        $admin = User::factory()->create(['role' => 'super_admin', 'name' => 'Admin']);
        User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

        $this->actingAs($admin)
            ->getJson('/api/users?search=John')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    // ── store ──────────────────────────────────────────────────────────────

    it('requires bot.manage to create a user', function () {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->postJson('/api/users', ['name' => 'Test', 'email' => 'test@x.com', 'password' => 'password', 'role' => 'user'])
            ->assertForbidden();
    });

    it('super admin can create a user', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'new@example.com')
            ->assertJsonPath('role', 'user');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'user']);
    });

    it('validates required fields on create', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->postJson('/api/users', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
    });

    it('rejects duplicate email on create', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name' => 'Another',
                'email' => 'taken@example.com',
                'password' => 'password123',
                'role' => 'user',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    // ── update ─────────────────────────────────────────────────────────────

    it('requires bot.manage to update a user', function () {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create();

        $this->actingAs($user)
            ->putJson("/api/users/{$target->id}", ['name' => 'Changed'])
            ->assertForbidden();
    });

    it('super admin can update a user', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", ['name' => 'Updated Name', 'role' => 'super_admin'])
            ->assertOk()
            ->assertJsonPath('name', 'Updated Name')
            ->assertJsonPath('role', 'super_admin');
    });

    it('does not change password when blank is sent on update', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create();
        $originalHash = $target->password;

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", ['name' => 'Name', 'password' => ''])
            ->assertOk();

        expect($target->fresh()->password)->toBe($originalHash);
    });

    it('rejects duplicate email on update', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['email' => 'taken@example.com']);
        $target = User::factory()->create(['email' => 'target@example.com']);

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    // ── destroy ────────────────────────────────────────────────────────────

    it('requires bot.manage to delete a user', function () {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson("/api/users/{$target->id}")
            ->assertForbidden();
    });

    it('super admin can delete a user', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$target->id}")
            ->assertNoContent();

        $this->assertModelMissing($target);
    });

    it('cannot delete own account', function () {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot delete your own account.');
    });
});
