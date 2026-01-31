<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'schema',
        'status',
        'plan_id',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function usageMonthly(): HasMany
    {
        return $this->hasMany(UsageMonthly::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }
}
