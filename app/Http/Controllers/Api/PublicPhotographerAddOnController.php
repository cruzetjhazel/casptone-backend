<?php

namespace App\Http\Controllers\Api;

use App\Enums\AddOnStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicAddOnResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPhotographerAddOnController extends Controller
{
    use ApiResponses;

    public function index(User $user)
    {
if (! $user->isPhotographer() || ! $user->isApprovedPhotographer() || $user->account_status !== \App\Enums\AccountStatus::Active) {
            throw new NotFoundHttpException('Photographer not found.');
        }

        $addOns = $user->addOns()->where('status', AddOnStatus::Active)->latest()->get();

        return $this->success(PublicAddOnResource::collection($addOns));
    }
}