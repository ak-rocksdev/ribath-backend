<?php

namespace App\Http\Controllers\Api\Tahfidz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tahfidz\ListMentoredStudentsRequest;
use App\Services\Tahfidz\MemorizationTargetService;
use Illuminate\Http\JsonResponse;

/**
 * Santri bimbingan (Tahfidz): the santri with a Target Hafalan in a
 * Semester Akademik — for a Pembimbing Tahfizh limited to his Cakupan
 * Mengajar, only his own. The santri picker of Log Setoran reads it.
 */
class MentoredStudentController extends Controller
{
    public function __construct(
        private MemorizationTargetService $memorizationTargetService,
    ) {}

    public function index(ListMentoredStudentsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $mentoredStudents = $this->memorizationTargetService->listMentoredStudents(
            $data['academic_year_id'],
            (int) $data['semester'],
        );

        return $this->successResponse($mentoredStudents, 'Daftar santri bimbingan berhasil diambil');
    }
}
