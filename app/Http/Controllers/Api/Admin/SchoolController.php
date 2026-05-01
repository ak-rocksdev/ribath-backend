<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSchoolRequest;
use App\Http\Requests\Admin\UploadSchoolLogoRequest;
use App\Models\School;
use App\Services\SchoolService;
use Illuminate\Http\JsonResponse;

class SchoolController extends Controller
{
    public function __construct(
        private SchoolService $schoolService,
    ) {}

    public function index(): JsonResponse
    {
        $activeSchools = School::where('is_active', true)
            ->select('id', 'name', 'logo_path')
            ->get()
            ->map(fn (School $school) => [
                'id'       => $school->id,
                'name'     => $school->name,
                'logo_url' => $school->logo_url,
            ]);

        return $this->successResponse($activeSchools, 'Schools retrieved');
    }

    public function show(School $school): JsonResponse
    {
        return $this->successResponse(
            $school->only(['id', 'name', 'address', 'phone', 'email', 'logo_url']),
            'School retrieved',
        );
    }

    public function update(UpdateSchoolRequest $request, School $school): JsonResponse
    {
        $updated = $this->schoolService->update($school, $request->validated());

        return $this->successResponse($updated, 'School updated');
    }

    public function uploadLogo(UploadSchoolLogoRequest $request, School $school): JsonResponse
    {
        $updated = $this->schoolService->uploadLogo($school, $request->file('logo'));

        return $this->successResponse($updated, 'Logo uploaded');
    }

    public function deleteLogo(School $school): JsonResponse
    {
        $updated = $this->schoolService->deleteLogo($school);

        return $this->successResponse($updated, 'Logo removed');
    }
}
