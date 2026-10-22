<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activated_at' => 'datetime',
            'balance' => 'integer',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'ADMIN';
    }

    public function getFilamentName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // "Ada O." — how other members see this person
    public function shortName(): string
    {
        return $this->first_name.' '.mb_substr($this->last_name, 0, 1).'.';
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    // Public shape for the member API — never includes the password
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'role' => $this->role,
            'status' => $this->status,
            'referralCode' => $this->referral_code,
            'balance' => $this->balance,
            'bank' => $this->account_number ? [
                'bankName' => $this->bank_name,
                'accountNumber' => $this->account_number,
                'accountName' => $this->account_name,
            ] : null,
            'activatedAt' => $this->activated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
