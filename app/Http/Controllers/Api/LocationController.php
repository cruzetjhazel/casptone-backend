<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationBarangayResource;
use App\Http\Resources\LocationCityMunicipalityResource;
use App\Http\Resources\LocationProvinceResource;
use App\Models\LocationBarangay;
use App\Models\LocationCityMunicipality;
use App\Models\LocationProvince;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function provinces(): AnonymousResourceCollection
    {
        return LocationProvinceResource::collection(
            LocationProvince::orderBy('name')->get()
        );
    }

    public function citiesMunicipalities(Request $request): AnonymousResourceCollection
    {
        $request->validate(['province_id' => ['required', 'integer', 'exists:location_provinces,id']]);

        return LocationCityMunicipalityResource::collection(
            LocationCityMunicipality::where('province_id', $request->integer('province_id'))
                ->orderBy('name')
                ->get()
        );
    }

    public function barangays(Request $request): AnonymousResourceCollection
    {
        $request->validate(['city_municipality_id' => ['required', 'integer', 'exists:location_cities_municipalities,id']]);

        return LocationBarangayResource::collection(
            LocationBarangay::where('city_municipality_id', $request->integer('city_municipality_id'))
                ->orderBy('name')
                ->get()
        );
    }
}