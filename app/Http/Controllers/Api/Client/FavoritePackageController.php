<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\AccountType;
use App\Enums\PackageStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FavoritePackageResource;
use App\Models\FavoritePackage;
use App\Models\Package;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class FavoritePackageController extends Controller
{
    use ApiResponses;

    private const WITH = ['package.user.photographerApplication', 'package.user.photographerProfile'];

    public function index(Request $request)
    {
        $favorites = FavoritePackage::where('client_id', $request->user()->id)
            ->with(self::WITH)
            ->latest()
            ->get();

        return $this->success(FavoritePackageResource::collection($favorites));
    }

    public function store(Request $request, Package $package)
    {
        abort_unless($request->user()->account_type === AccountType::Client, 403, 'Only client accounts can favorite packages.');
        abort_unless($package->status === PackageStatus::Published, 404, 'Package not found.');

        $favorite = FavoritePackage::firstOrCreate([
            'client_id' => $request->user()->id,
            'package_id' => $package->id,
        ]);
        $favorite->load(self::WITH);

        return $this->success(new FavoritePackageResource($favorite), 'Package added to favorites.', 201);
    }

    public function destroy(Request $request, Package $package)
    {
        FavoritePackage::where('client_id', $request->user()->id)
            ->where('package_id', $package->id)
            ->delete();

        return $this->success(null, 'Package removed from favorites.');
    }
}