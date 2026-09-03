<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_SUB_MANAGER = 'sub_manager';

    public const ROLE_AGENT = 'agent';

    /**
     * Every role, most privileged first.
     *
     * @var list<string>
     */
    public const ROLES = [self::ROLE_SUPER_ADMIN, self::ROLE_MANAGER, self::ROLE_SUB_MANAGER, self::ROLE_AGENT];

    /**
     * Roles that own other users and therefore carry a referral code agents can sign up with.
     *
     * @var list<string>
     */
    public const OWNER_ROLES = [self::ROLE_MANAGER, self::ROLE_SUB_MANAGER];

    /**
     * Seniority per role, lowest number being the most privileged. Only ever used to compare
     * two roles, so nobody can create or administer somebody at or above their own rank.
     *
     * @var array<string, int>
     */
    private const ROLE_RANKS = [
        self::ROLE_SUPER_ADMIN => 0,
        self::ROLE_MANAGER => 1,
        self::ROLE_SUB_MANAGER => 2,
        self::ROLE_AGENT => 3,
    ];

    /**
     * @var list<string>
     */
    private const MANAGER_PERMISSIONS = [
        'accounts.read',
        'accounts.write',
        'accounts.assign',
        'captcha.read',
        'captcha.write',
        'proxies.read',
        'proxies.write',
        'settings.read',
        'settings.write',
        'users.manage',
        'logs.read',
    ];

    /**
     * The manager set minus the bot fleet: a sub manager administers their own agents but
     * never touches worker slots, so they lose Bot Control and everything else hanging off
     * accounts.assign (bulk worker assignment, the per-account race settings).
     *
     * @var list<string>
     */
    private const SUB_MANAGER_PERMISSIONS = [
        'accounts.read',
        'accounts.write',
        'captcha.read',
        'captcha.write',
        'proxies.read',
        'proxies.write',
        'settings.read',
        'settings.write',
        'users.manage',
        'logs.read',
    ];

    /**
     * @var list<string>
     */
    private const AGENT_PERMISSIONS = [
        'accounts.read',
        'accounts.write',
        'captcha.read',
        'captcha.write',
        'proxies.read',
        'proxies.write',
        'settings.read',
        'settings.write',
    ];

    /**
     * Permissions granted to each role. Super admins bypass this map entirely.
     *
     * A sub manager sits just under a manager: same subtree administration, but no access to
     * the bot fleet or the request tester.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        self::ROLE_MANAGER => self::MANAGER_PERMISSIONS,
        self::ROLE_SUB_MANAGER => self::SUB_MANAGER_PERMISSIONS,
        self::ROLE_AGENT => self::AGENT_PERMISSIONS,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'plain_password',
        'role',
        'parent_id',
        'referral_code',
        'is_approved',
        'approved_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'plain_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Every manager and sub manager gets a referral code the moment they're created, so
     * agents can always self-register (Request Access) against whoever owns them without a
     * separate provisioning step.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (in_array($user->role, self::OWNER_ROLES, true) && empty($user->referral_code)) {
                $user->referral_code = self::generateReferralCode($user->name);
            }
        });
    }

    /**
     * A short, shareable code slugged from the owner's name plus a random suffix (e.g.
     * JEWEL-4X9K). Retries the suffix until it's unique; falls back to a fully random prefix
     * if the name has nothing slug-worthy in it (e.g. non-Latin names).
     */
    public static function generateReferralCode(string $name): string
    {
        $prefix = Str::upper(Str::slug($name, ''));
        $prefix = $prefix !== '' ? substr($prefix, 0, 8) : Str::upper(Str::random(6));

        do {
            $code = $prefix.'-'.Str::upper(Str::random(4));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    protected function plainPassword(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) return null;
                try {
                    return Crypt::decrypt($value);
                } catch (\Exception $e) {
                    return null;
                }
            },
            set: fn ($value) => $value !== null ? Crypt::encrypt($value) : null,
        );
    }

    /**
     * The manager or sub manager who created this user. Null for admins, managers, and any
     * agent created directly by an admin.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The users created directly by this one: a manager's sub managers and agents, or a sub
     * manager's agents. Deeper descendants are reached through descendantIds().
     */
    public function agents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function paymentLinks(): HasManyThrough
    {
        return $this->hasManyThrough(PaymentLink::class, Account::class);
    }

    public function googleAccount(): HasOne
    {
        return $this->hasOne(GoogleAccount::class);
    }

    public function approve(): void
    {
        $this->update(['is_approved' => true, 'approved_at' => now()]);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isSubManager(): bool
    {
        return $this->role === self::ROLE_SUB_MANAGER;
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    /**
     * Whether this user owns a subtree of their own — a manager or a sub manager. Prefer
     * this over isManager() anywhere the rule is "not an agent, not an admin", so the two
     * owner tiers never drift apart.
     */
    public function ownsSubtree(): bool
    {
        return in_array($this->role, self::OWNER_ROLES, true);
    }

    /**
     * Seniority of a role; an unknown role sorts below everything so it can never manage.
     */
    private static function rank(string $role): int
    {
        return self::ROLE_RANKS[$role] ?? PHP_INT_MAX;
    }

    /**
     * Whether this user outranks the given role, which is the escalation guard behind both
     * assignableRoles() and canManageUser().
     */
    public function outranks(string $role): bool
    {
        return self::rank($this->role) < self::rank($role);
    }

    /**
     * The roles a user of the given role may hang off: a sub manager always sits under a
     * manager, an agent under either owner tier. An empty list means the role is never
     * parented (managers and admins sit at the top of the tree).
     *
     * @return list<string>
     */
    public static function parentRolesFor(string $role): array
    {
        return match ($role) {
            self::ROLE_SUB_MANAGER => [self::ROLE_MANAGER],
            self::ROLE_AGENT => self::OWNER_ROLES,
            default => [],
        };
    }

    /**
     * Whether the given role is meaningless without an owner above it. A sub manager is
     * defined by the manager it belongs to, so it may never be created unparented; an agent
     * may still be admin-owned.
     */
    public static function requiresParent(string $role): bool
    {
        return $role === self::ROLE_SUB_MANAGER;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, self::ROLE_PERMISSIONS[$this->role] ?? [], true);
    }

    /**
     * Every user below this one, at any depth: a manager's sub managers plus the agents
     * those sub managers created.
     *
     * Walked level by level so the cost is one query per depth rather than one per node,
     * and ids already seen are excluded so a cyclic parent_id can never loop forever.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $collected = [];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $children = self::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', [$this->id, ...$collected])
                ->pluck('id')
                ->all();

            if ($children === []) {
                break;
            }

            $collected = [...$collected, ...$children];
            $frontier = $children;
        }

        return $collected;
    }

    /**
     * The user ids whose data this user may see, or null when unrestricted.
     *
     * This is the single source of truth for every "whose records are these" decision: an
     * admin gets null (no filter at all), and everybody else gets themselves plus their
     * whole subtree — so a manager reaches the agents their sub managers created, while a
     * sub manager reaches only their own agents. Callers must treat null as "no WHERE
     * clause" rather than an empty set.
     *
     * @return list<int>|null
     */
    public function visibleUserIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return [$this->id, ...$this->descendantIds()];
    }

    /**
     * Phones of every account this user may see. Payment links and bot logs key off the
     * account phone rather than a user id, so they scope through this instead.
     *
     * @return Collection<int, string>
     */
    public function visibleAccountPhones(): Collection
    {
        $query = Account::query();

        if (($ids = $this->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $ids);
        }

        return $query->pluck('phone');
    }

    /**
     * Whether this user may administer the target user (edit, delete, impersonate).
     *
     * Admins may manage anyone but themselves; an owner may manage anyone inside their own
     * subtree who ranks strictly below them — so a manager reaches their sub managers and
     * every agent underneath, and a sub manager reaches only their own agents. The rank
     * check is what stops a mis-parented peer row from granting lateral access, and the
     * subtree check is what keeps one manager's agents and passwords invisible to another.
     */
    public function canManageUser(self $target): bool
    {
        if ($this->id === $target->id) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if (! $this->ownsSubtree() || ! $this->outranks($target->role)) {
            return false;
        }

        return in_array($target->id, $this->descendantIds(), true);
    }

    /**
     * Roles this user is allowed to assign when creating or editing a user. Every role here
     * ranks strictly below the actor, so nobody can mint a peer or escalate themselves: a
     * manager may create sub managers and agents, a sub manager only agents.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        if ($this->isSuperAdmin()) {
            return self::ROLES;
        }

        if (! $this->ownsSubtree()) {
            return [];
        }

        return array_values(array_filter(
            [self::ROLE_SUB_MANAGER, self::ROLE_AGENT],
            fn (string $role): bool => $this->outranks($role),
        ));
    }
}
