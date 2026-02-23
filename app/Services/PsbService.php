<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PsbService
{
    public function __construct(
        private RegistrationNumberGenerator $registrationNumberGenerator
    ) {}

    public function register(array $validatedData): Registration
    {
        $activePeriod = RegistrationPeriod::where('is_active', true)
            ->where('registration_open', '<=', now())
            ->where('registration_close', '>=', now())
            ->first();

        $status = Registration::STATUS_NEW;

        if ($activePeriod && $activePeriod->student_quota !== null && $activePeriod->enrolled_count >= $activePeriod->student_quota) {
            $status = Registration::STATUS_WAITLIST;
        }

        return Registration::create([
            ...$validatedData,
            'registration_period_id' => $activePeriod?->id,
            'registration_number' => $this->registrationNumberGenerator->generate(),
            'status' => $status,
        ]);
    }

    public function acceptRegistration(Registration $registration, User $adminUser): array
    {
        return DB::transaction(function () use ($registration, $adminUser) {
            $guardianUser = null;
            $temporaryPassword = null;

            if ($registration->guardian_name !== null) {
                $temporaryPassword = Str::random(10);
                $guardianEmail = $registration->guardian_email ?? $registration->guardian_phone.'@wali.ribath.local';

                $guardianUser = User::create([
                    'name' => $registration->guardian_name,
                    'email' => $guardianEmail,
                    'password' => Hash::make($temporaryPassword),
                ]);

                $guardianRole = Role::firstOrCreate(
                    ['name' => 'wali_santri', 'guard_name' => 'web']
                );
                $guardianUser->assignRole($guardianRole);
            }

            $entryDate = $registration->period?->entry_date ?? now()->toDateString();

            $student = Student::create([
                'registration_id' => $registration->id,
                'guardian_user_id' => $guardianUser?->id,
                'full_name' => $registration->full_name,
                'birth_place' => $registration->birth_place,
                'birth_date' => $registration->birth_date,
                'gender' => $registration->gender,
                'program' => $registration->preferred_program,
                'status' => 'active',
                'entry_date' => $entryDate,
            ]);

            $registration->update([
                'status' => Registration::STATUS_ACCEPTED,
                'reviewed_at' => now(),
                'reviewed_by' => $adminUser->id,
            ]);

            if ($registration->period) {
                $registration->period->increment('enrolled_count');
            }

            $result = [
                'student' => $student,
                'registration' => $registration->fresh(),
            ];

            if ($guardianUser) {
                $result['guardian_user'] = [
                    'id' => $guardianUser->id,
                    'name' => $guardianUser->name,
                    'email' => $guardianUser->email,
                    'temporary_password' => $temporaryPassword,
                ];
            }

            return $result;
        });
    }

    public function rejectRegistration(Registration $registration, User $adminUser, string $rejectionReason): Registration
    {
        $registration->update([
            'status' => Registration::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $adminUser->id,
            'rejection_reason' => $rejectionReason,
        ]);

        return $registration->fresh();
    }

    public function getRegistrationStats(?string $registrationPeriodId = null): array
    {
        $query = Registration::query();

        if ($registrationPeriodId) {
            $query->where('registration_period_id', $registrationPeriodId);
        }

        $stats = $query->selectRaw("
            COUNT(*) as total,
            COUNT(*) FILTER (WHERE status = 'new') as new,
            COUNT(*) FILTER (WHERE status = 'contacted') as contacted,
            COUNT(*) FILTER (WHERE status = 'interview') as interview,
            COUNT(*) FILTER (WHERE status = 'visited') as visited,
            COUNT(*) FILTER (WHERE status = 'accepted') as accepted,
            COUNT(*) FILTER (WHERE status = 'rejected') as rejected,
            COUNT(*) FILTER (WHERE status = 'waitlist') as waitlist,
            COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled
        ")->first();

        return $stats->toArray();
    }
}
