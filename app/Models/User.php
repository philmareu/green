<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
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
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
     * Green is self-hosted: every user created on this install may sign in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
