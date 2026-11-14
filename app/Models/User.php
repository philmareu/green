<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\NotificationType;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

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
            'notification_preferences' => 'array',
        ];
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    /** @return HasMany<PlaidItem, $this> */
    public function plaidItems(): HasMany
    {
        return $this->hasMany(PlaidItem::class);
    }

    /** @return HasMany<CategorySet, $this> */
    public function categorySets(): HasMany
    {
        return $this->hasMany(CategorySet::class);
    }

    /** @return HasMany<Recurring, $this> */
    public function recurrings(): HasMany
    {
        return $this->hasMany(Recurring::class);
    }

    /**
     * Where this user gets a kind of notification. Empty means it's turned off.
     *
     * @return list<string>
     */
    public function notificationChannels(NotificationType $type): array
    {
        return $this->notification_preferences[$type->value] ?? $type->defaultChannels();
    }

    /**
     * Green is self-hosted: every user created on this install may sign in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
