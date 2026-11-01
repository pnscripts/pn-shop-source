<?php

namespace PnShop\Acl\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use PnShop\Acl\Factories\AdminUserFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff member who signs in to the admin panel. Separate from customer accounts.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasApiTokens<HasAbilities> */
    use HasApiTokens;

    /** @use HasFactory<AdminUserFactory> */
    use HasFactory;

    use HasRoles {
        hasPermissionTo as protected roleHasPermissionTo;
    }
    use LogsActivity, Notifiable;

    public const ADMINISTRATOR_ROLE = 'administrator';

    protected string $guard_name = 'admin';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'is_active'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Permissions through roles, narrowed to the abilities of the API token in use: a token
     * never grants more than its owner holds, and the owner's other permissions stay off.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        // Whatever form the permission comes in (name, enum, model or id), the token must carry it.
        $name = match (true) {
            is_string($permission) => $permission,
            $permission instanceof \BackedEnum => (string) $permission->value,
            $permission instanceof Permission => data_get($permission, 'name'),
            default => app(PermissionRegistrar::class)->getPermissionClass()::query()->whereKey($permission)->value('name'),
        };

        if (! is_string($name) || ! $this->tokenAllows($name)) {
            return false;
        }

        return $this->roleHasPermissionTo($permission, $guardName);
    }

    /**
     * Whether the API token this request runs with (if any) carries the permission.
     */
    public function tokenAllows(string $permission): bool
    {
        $token = $this->currentAccessToken();

        return ! $token instanceof PersonalAccessToken || $token->can($permission);
    }

    /**
     * Whether this staff member may hand out all of these permissions: administrators may
     * grant anything, others only what they hold themselves (no privilege escalation).
     *
     * @param  iterable<string>  $permissions
     */
    public function mayGrant(iterable $permissions): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (! $this->checkPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /** Whether this staff member may give someone the role. */
    public function mayAssign(Role $role): bool
    {
        if ($role->name === self::ADMINISTRATOR_ROLE) {
            return $this->isAdministrator();
        }

        return $this->mayGrant($role->permissions->pluck('name'));
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(self::ADMINISTRATOR_ROLE);
    }

    protected static function newFactory(): AdminUserFactory
    {
        return AdminUserFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('system')
            ->logOnly(['name', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
