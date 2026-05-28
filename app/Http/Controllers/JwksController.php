<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Jwt\JwksKeySet;
use Illuminate\Http\JsonResponse;

final class JwksController extends Controller
{
    public function __invoke(JwksKeySet $jwks): JsonResponse
    {
        return response()->json($jwks->toArray());
    }
}
