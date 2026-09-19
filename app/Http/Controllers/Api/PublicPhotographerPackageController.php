<?php

namespace App\Http\Controllers\Api;

use App\Enums\PackageStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPackageResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPhotographerPackageController extends Controller
{
    use ApiResponses;

    public function index(User $user)
    {
    if (! $user->isPhotographer() || ! $user->isApprovedPhotographer() || $user->account_status !== \App\Enums\AccountStatus::Active) {
            throw new NotFoundHttpException('Photographer not found.');
        }

        $packages = $user->packages()->where('status', PackageStatus::Published)->latest()->get();

        return $this->success(PublicPackageResource::collection($packages));
    }
}