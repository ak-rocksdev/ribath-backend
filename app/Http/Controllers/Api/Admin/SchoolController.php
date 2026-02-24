<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;

class SchoolController extends Controller
{
    public function index(): JsonResponse
    {
        $activeSchools = School::where('is_active', true)
            ->select('id', 'name')
            ->get();

        return $this->successResponse($activeSchools, 'Schools retrieved');
    }
}
