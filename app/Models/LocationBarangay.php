<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationBarangay extends Model
{
    protected $fillable = ['psgc_code', 'city_municipality_id', 'name'];

    public function cityMunicipality(): BelongsTo
    {
        return $this->belongsTo(LocationCityMunicipality::class, 'city_municipality_id');
    }
}