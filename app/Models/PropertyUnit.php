<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyUnit extends Model
{
    use HasFactory, SoftDeletes;

    public const MANUAL_AVAILABILITY_ACTIVE = 'active';
    public const MANUAL_AVAILABILITY_ON_HOLD = 'on_hold';
    public const MANUAL_AVAILABILITY_OFF_MARKET = 'off_market';

    public static function manualAvailabilityOptions(): array
    {
        return [
            self::MANUAL_AVAILABILITY_ACTIVE => __('Active'),
            self::MANUAL_AVAILABILITY_ON_HOLD => __('On Hold'),
            self::MANUAL_AVAILABILITY_OFF_MARKET => __('Off Market'),
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function propertyUnits(): HasMany
    {
        return $this->hasMany(PropertyUnit::class);
    }

    public function publicOption(): HasOne
    {
        return $this->hasOne(PublicPropertyOption::class, 'property_unit_id', 'id');
    }

    /**
     * Get the tenant that owns the PropertyUnit
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function activeTenant(): HasOne
    {
        return $this->hasOne(Tenant::class, 'unit_id', 'id')->where('status', TENANT_STATUS_ACTIVE);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TenantUnitAssignment::class, 'unit_id', 'id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(PropertyUnitActivityLog::class, 'unit_id', 'id');
    }

    public function fileAttachImage(): HasOne
    {
        return $this->hasOne(FileManager::class, 'origin_id', 'id')
            ->where('origin_type', 'App\Models\PropertyUnit')
            ->select('id', 'folder_name', 'file_name', 'origin_type', 'origin_id');
    }
}
