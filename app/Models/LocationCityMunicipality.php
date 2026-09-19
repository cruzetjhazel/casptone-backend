<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationCityMunicipality extends Model
{
    protected $table = 'location_cities_municipalities';

    protected $fillable = ['psgc_code', 'province_id', 'name', 'type'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(LocationProvince::class, 'province_id');
    }

    public function barangays(): HasMany
    {
        return $this->hasMany(LocationBarangay::class, 'city_municipality_id');
    }
}