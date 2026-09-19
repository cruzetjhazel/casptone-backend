<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationProvince extends Model
{
    protected $fillable = ['psgc_code', 'name', 'region_code'];

    public function citiesMunicipalities(): HasMany
    {
        return $this->hasMany(LocationCityMunicipality::class, 'province_id');
    }
}