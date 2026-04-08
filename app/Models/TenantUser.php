<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;

class TenantUser extends Model
{
    use HasFactory, HasRoles, SoftDeletes;

    protected string $guard_name = 'web';

    protected $fillable = [
        'user_id',
        'display_name',
        'job_title',
        'phone',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * Get the central User model for this tenant user.
     * Queries the central database connection explicitly.
     */
    public function centralUser(): ?User
    {
        return User::on('central')->find($this->user_id);
    }
}
