<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable(['name', 'email', 'password', 'avatar_path', 'locale', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use CentralConnection, HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'landlord') {
            return $this->is_landlord;
        }

        // For admin panel: user must have a TenantUser record in the current tenant
        if ($panel->getId() === 'admin') {
            try {
                return TenantUser::where('user_id', $this->id)->exists();
            } catch (\Exception) {
                return false;
            }
        }

        return false;
    }

    /**
     * Check a permission by delegating to this user's TenantUser record.
     *
     * Filament policies call hasPermissionTo() on the auth user. In the tenant
     * panel the auth user is the central User, but roles live on TenantUser
     * in the tenant DB. This method bridges the two without adding HasRoles to
     * the central User, which would query the wrong (central) DB.
     */
    public function hasPermissionTo(string|\BackedEnum $permission, ?string $guardName = null): bool
    {
        try {
            $tenantUser = TenantUser::where('user_id', $this->id)->first();

            return $tenantUser?->hasPermissionTo($permission, $guardName ?? 'web') ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasRole(string|\BackedEnum|array $roles, ?string $guard = null): bool
    {
        try {
            $tenantUser = TenantUser::where('user_id', $this->id)->first();

            return $tenantUser?->hasRole($roles, $guard ?? 'web') ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_landlord' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
