<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PublicPropertyOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'property_id',
        'property_unit_id',
        'rental_kind',
        'monthly_rate',
        'nightly_rate',
        'security_deposit_type',
        'security_deposit_value',
        'max_guests',
        'status',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'property_id' => 'integer',
        'property_unit_id' => 'integer',
        'monthly_rate' => 'float',
        'nightly_rate' => 'float',
        'security_deposit_type' => 'integer',
        'security_deposit_value' => 'float',
        'max_guests' => 'integer',
        'status' => 'integer',
        'sort_order' => 'integer',
        'is_default' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function propertyUnit(): BelongsTo
    {
        return $this->belongsTo(PropertyUnit::class, 'property_unit_id');
    }
}
