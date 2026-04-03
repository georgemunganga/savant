<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GatewayCurrency extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['gateway_id', 'currency', 'conversion_rate', 'owner_user_id'];

    public function  getSymbolAttribute()
    {
        try {
            return getCurrency($this->currency, true);
        } catch (\Throwable $e) {
            return Currency::query()
                ->where('currency_code', $this->currency)
                ->value('symbol') ?? $this->currency;
        }
    }
}
