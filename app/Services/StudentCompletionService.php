<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentCompletionService
{
    /**
     * Resolve student from registration ID.
     * Returns ['student' => Student] on success, or ['error' => message, 'code' => int] on failure.
     */
    public function resolveStudent(string $registrationId): array
    {
        $registration = Registration::find($registrationId);

        if (! $registration) {
            return ['error' => 'Pendaftaran tidak ditemukan', 'code' => 404];
        }

        if ($registration->status !== 'accepted') {
            return ['error' => 'Pendaftaran belum diterima', 'code' => 400];
        }

        $student = Student::where('registration_id', $registrationId)->first();

        if (! $student) {
            return ['error' => 'Data santri belum tersedia', 'code' => 404];
        }

        return ['student' => $student];
    }

    /**
     * Check if profile is already completed.
     */
    public function isCompleted(Student $student): bool
    {
        return $student->profile_completion_status === 'completed';
    }

    /**
     * Load student with all profile relationships.
     */
    public function getStudentWithProfile(Student $student): Student
    {
        return $student->load([
            'parents',
            'health',
            'educationHistory',
            'religiousProfile',
            'additionalInfo',
            'documents',
        ]);
    }

    /**
     * Save all form data in a single transaction.
     */
    public function saveCompletionData(Student $student, array $data, string $status): Student
    {
        return DB::transaction(function () use ($student, $data, $status) {
            // Update student fields
            if (isset($data['student'])) {
                $student->fill($data['student']);
            }

            // Set completion status
            $student->profile_completion_status = $status;

            if ($status === 'completed') {
                $student->profile_completed_at = now();

                // Set agreement timestamps
                if (! empty($data['agreements']['agreed_to_rules'])) {
                    $student->agreed_to_rules_at = now();
                }
                if (! empty($data['agreements']['agreed_to_commitment'])) {
                    $student->agreed_to_commitment_at = now();
                }
                if (! empty($data['agreements']['data_verified'])) {
                    $student->data_verified_at = now();
                }
            }

            $student->save();

            // Upsert parents
            if (isset($data['parents'])) {
                foreach ($data['parents'] as $relation => $parentData) {
                    if (! empty($parentData['name'])) {
                        $student->parents()->updateOrCreate(
                            ['relation' => $relation],
                            $parentData,
                        );
                    }
                }
            }

            // Upsert health
            if (isset($data['health'])) {
                $student->health()->updateOrCreate(
                    ['student_id' => $student->id],
                    $data['health'],
                );
            }

            // Upsert education history
            if (isset($data['education_history'])) {
                $student->educationHistory()->updateOrCreate(
                    ['student_id' => $student->id],
                    $data['education_history'],
                );
            }

            // Upsert religious profile
            if (isset($data['religious_profile'])) {
                $student->religiousProfile()->updateOrCreate(
                    ['student_id' => $student->id],
                    $data['religious_profile'],
                );
            }

            // Upsert additional info
            if (isset($data['additional_info'])) {
                $student->additionalInfo()->updateOrCreate(
                    ['student_id' => $student->id],
                    $data['additional_info'],
                );
            }

            return $student->fresh();
        });
    }

    /**
     * Upload a document file.
     */
    public function uploadDocument(Student $student, string $documentType, $file): StudentDocument
    {
        // Delete existing document of this type
        $existing = $student->documents()->where('document_type', $documentType)->first();
        if ($existing) {
            Storage::disk('public')->delete($existing->file_path);
            $existing->delete();
        }

        // Store new file
        $extension = $file->getClientOriginalExtension();
        $fileName = "{$documentType}.{$extension}";
        $filePath = $file->storeAs("student-documents/{$student->id}", $fileName, 'public');

        return $student->documents()->create([
            'document_type' => $documentType,
            'file_path' => $filePath,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
        ]);
    }

    /**
     * Delete a document file. Returns true on success, false if not found.
     */
    public function deleteDocument(Student $student, string $documentType): bool
    {
        $document = $student->documents()->where('document_type', $documentType)->first();

        if (! $document) {
            return false;
        }

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return true;
    }

    /**
     * Validate that required pre-existing fields are present for completion.
     */
    public function validatePreExistingFields(Student $student): ?string
    {
        if (empty($student->birth_place)) {
            return 'Tempat lahir harus diisi sebelum mengirim data';
        }
        if (empty($student->birth_date)) {
            return 'Tanggal lahir harus diisi sebelum mengirim data';
        }

        return null;
    }
}
