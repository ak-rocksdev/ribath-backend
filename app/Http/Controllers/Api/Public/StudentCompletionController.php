<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StudentCompletionRequest;
use App\Http\Requests\Public\StudentDocumentUploadRequest;
use App\Models\StudentDocument;
use App\Services\StudentCompletionService;
use Illuminate\Http\JsonResponse;

class StudentCompletionController extends Controller
{
    public function __construct(
        private StudentCompletionService $completionService,
    ) {}

    /**
     * GET /api/v1/public/student-completion/{registrationId}
     */
    public function show(string $registrationId): JsonResponse
    {
        $result = $this->completionService->resolveStudent($registrationId);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], null, $result['code']);
        }

        $student = $this->completionService->getStudentWithProfile($result['student']);

        return $this->successResponse([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'nik' => $student->nik,
                'email' => $student->email,
                'phone' => $student->phone,
                'birth_place' => $student->birth_place,
                'birth_date' => $student->birth_date?->toDateString(),
                'gender' => $student->gender,
                'address' => $student->address,
                'child_order' => $student->child_order,
                'siblings_count' => $student->siblings_count,
                'family_income_range' => $student->family_income_range,
                'motivation' => $student->motivation,
                'profile_completion_status' => $student->profile_completion_status,
                'profile_completed_at' => $student->profile_completed_at?->toIso8601String(),
                'updated_at' => $student->updated_at?->toIso8601String(),
            ],
            'parents' => $student->parents->map(fn ($p) => [
                'relation' => $p->relation,
                'name' => $p->name,
                'occupation' => $p->occupation,
                'email' => $p->email,
                'phone' => $p->phone,
                'address' => $p->address,
            ])->values(),
            'health' => $student->health ? [
                'blood_type' => $student->health->blood_type,
                'disease_history' => $student->health->disease_history,
                'allergies' => $student->health->allergies,
                'special_conditions' => $student->health->special_conditions,
            ] : null,
            'education_history' => $student->educationHistory ? [
                'last_school_name' => $student->educationHistory->last_school_name,
                'last_education_level' => $student->educationHistory->last_education_level,
                'graduation_year' => $student->educationHistory->graduation_year,
                'achievements' => $student->educationHistory->achievements,
            ] : null,
            'religious_profile' => $student->religiousProfile ? [
                'quran_reading_ability' => $student->religiousProfile->quran_reading_ability,
                'memorized_juz' => $student->religiousProfile->memorized_juz,
                'has_pesantren_experience' => $student->religiousProfile->has_pesantren_experience,
                'previous_pesantren_name' => $student->religiousProfile->previous_pesantren_name,
                'other_skills' => $student->religiousProfile->other_skills,
            ] : null,
            'additional_info' => $student->additionalInfo ? [
                'hobbies_talents' => $student->additionalInfo->hobbies_talents,
                'extracurricular_interests' => $student->additionalInfo->extracurricular_interests,
                'post_graduation_hopes' => $student->additionalInfo->post_graduation_hopes,
            ] : null,
            'documents' => $student->documents->map(fn ($d) => [
                'document_type' => $d->document_type,
                'file_path' => $d->file_path,
                'original_filename' => $d->original_filename,
                'file_size' => $d->file_size,
            ])->values(),
        ], 'Data santri berhasil dimuat');
    }

    /**
     * PUT /api/v1/public/student-completion/{registrationId}
     */
    public function update(StudentCompletionRequest $request, string $registrationId): JsonResponse
    {
        $result = $this->completionService->resolveStudent($registrationId);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], null, $result['code']);
        }

        $student = $result['student'];

        if ($this->completionService->isCompleted($student)) {
            return $this->errorResponse('Data sudah dikirim dan tidak dapat diubah lagi', null, 403);
        }

        $status = $request->validated()['status'];

        // Check pre-existing fields and foto when completing
        if ($status === 'completed') {
            $preExistingError = $this->completionService->validatePreExistingFields($student);
            if ($preExistingError) {
                return $this->errorResponse($preExistingError, null, 422);
            }

            $hasFoto = $student->documents()->where('document_type', 'foto')->exists();
            if (! $hasFoto) {
                return $this->errorResponse('Foto santri wajib diunggah sebelum mengirim data', null, 422);
            }
        }

        $student = $this->completionService->saveCompletionData(
            $student,
            $request->validated(),
            $status,
        );

        $message = $status === 'completed'
            ? 'Data berhasil dikirim'
            : 'Data berhasil disimpan sebagai draft';

        return $this->successResponse([
            'profile_completion_status' => $student->profile_completion_status,
            'profile_completed_at' => $student->profile_completed_at?->toIso8601String(),
            'updated_at' => $student->updated_at?->toIso8601String(),
        ], $message);
    }

    /**
     * POST /api/v1/public/student-completion/{registrationId}/documents
     */
    public function uploadDocument(StudentDocumentUploadRequest $request, string $registrationId): JsonResponse
    {
        $result = $this->completionService->resolveStudent($registrationId);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], null, $result['code']);
        }

        $student = $result['student'];

        if ($this->completionService->isCompleted($student)) {
            return $this->errorResponse('Data sudah dikirim dan tidak dapat diubah lagi', null, 403);
        }

        $document = $this->completionService->uploadDocument(
            $student,
            $request->validated()['document_type'],
            $request->file('file'),
        );

        return $this->successResponse([
            'document_type' => $document->document_type,
            'file_path' => $document->file_path,
            'original_filename' => $document->original_filename,
            'file_size' => $document->file_size,
        ], 'Dokumen berhasil diunggah');
    }

    /**
     * DELETE /api/v1/public/student-completion/{registrationId}/documents/{documentType}
     */
    public function deleteDocument(string $registrationId, string $documentType): JsonResponse
    {
        if (! in_array($documentType, StudentDocument::ALLOWED_TYPES)) {
            return $this->errorResponse('Tipe dokumen tidak valid', null, 422);
        }

        $result = $this->completionService->resolveStudent($registrationId);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], null, $result['code']);
        }

        $student = $result['student'];

        if ($this->completionService->isCompleted($student)) {
            return $this->errorResponse('Data sudah dikirim dan tidak dapat diubah lagi', null, 403);
        }

        $deleted = $this->completionService->deleteDocument($student, $documentType);

        if (! $deleted) {
            return $this->errorResponse('Dokumen tidak ditemukan', null, 404);
        }

        return $this->successResponse(null, 'Dokumen berhasil dihapus');
    }
}
