<?php

namespace App\Models;

use App\Traits\HasTranslations;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable implements MustVerifyEmail
{
    use BroadcastsEvents, HasApiTokens, HasFactory, HasTranslations, HasUuids, Notifiable, Searchable;

    public $translatable = ['name'];

    public $broadcastAfterCommit = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime'
    ];

    // /**
    //  * Get the channels that model events should broadcast on.
    //  *
    //  * @param  string  $event
    //  * @return \Illuminate\Broadcasting\Channel|array
    //  */
    // public function broadcastOn($event)
    // {
    //     // return [$this, $this->user];

    //     return [new PrivateChannel($this->user)];
    // }
}
