<?php

namespace App\Services;

use App\Models\PsbPeriod;
use App\Models\PsbRegistration;
use App\Models\Santri;
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

    public function register(array $validatedData): PsbRegistration
    {
        $activePeriod = PsbPeriod::where('is_active', true)
            ->where('pendaftaran_buka', '<=', now())
            ->where('pendaftaran_tutup', '>=', now())
            ->first();

        $status = PsbRegistration::STATUS_BARU;

        if ($activePeriod && $activePeriod->kuota_santri !== null && $activePeriod->kuota_terisi >= $activePeriod->kuota_santri) {
            $status = PsbRegistration::STATUS_WAITLIST;
        }

        return PsbRegistration::create([
            ...$validatedData,
            'psb_period_id' => $activePeriod?->id,
            'registration_number' => $this->registrationNumberGenerator->generate(),
            'status' => $status,
        ]);
    }

    public function acceptRegistration(PsbRegistration $registration, User $adminUser): array
    {
        return DB::transaction(function () use ($registration, $adminUser) {
            $waliUser = null;
            $temporaryPassword = null;

            if ($registration->nama_wali !== null) {
                $temporaryPassword = Str::random(10);
                $waliEmail = $registration->email_wali ?? $registration->no_hp_wali . '@wali.ribath.local';

                $waliUser = User::create([
                    'name' => $registration->nama_wali,
                    'email' => $waliEmail,
                    'password' => Hash::make($temporaryPassword),
                ]);

                $waliSantriRole = Role::firstOrCreate(['name' => 'wali_santri']);
                $waliUser->assignRole($waliSantriRole);
            }

            $tanggalMasuk = $registration->period?->tanggal_masuk ?? now()->toDateString();

            $santri = Santri::create([
                'psb_registration_id' => $registration->id,
                'wali_user_id' => $waliUser?->id,
                'nama_lengkap' => $registration->nama_lengkap,
                'tempat_lahir' => $registration->tempat_lahir,
                'tanggal_lahir' => $registration->tanggal_lahir,
                'jenis_kelamin' => $registration->jenis_kelamin,
                'program' => $registration->program_minat,
                'status' => 'aktif',
                'tanggal_masuk' => $tanggalMasuk,
            ]);

            $registration->update([
                'status' => PsbRegistration::STATUS_DITERIMA,
                'reviewed_at' => now(),
                'reviewed_by' => $adminUser->id,
            ]);

            if ($registration->period) {
                $registration->period->increment('kuota_terisi');
            }

            $result = [
                'santri' => $santri,
                'registration' => $registration->fresh(),
            ];

            if ($waliUser) {
                $result['wali_user'] = [
                    'id' => $waliUser->id,
                    'name' => $waliUser->name,
                    'email' => $waliUser->email,
                    'temporary_password' => $temporaryPassword,
                ];
            }

            return $result;
        });
    }

    public function rejectRegistration(PsbRegistration $registration, User $adminUser, string $rejectionReason): PsbRegistration
    {
        $registration->update([
            'status' => PsbRegistration::STATUS_DITOLAK,
            'reviewed_at' => now(),
            'reviewed_by' => $adminUser->id,
            'rejection_reason' => $rejectionReason,
        ]);

        return $registration->fresh();
    }

    public function getRegistrationStats(?string $psbPeriodId = null): array
    {
        $query = PsbRegistration::query();

        if ($psbPeriodId) {
            $query->where('psb_period_id', $psbPeriodId);
        }

        $stats = $query->selectRaw("
            COUNT(*) as total,
            COUNT(*) FILTER (WHERE status = 'baru') as baru,
            COUNT(*) FILTER (WHERE status = 'dihubungi') as dihubungi,
            COUNT(*) FILTER (WHERE status = 'interview') as interview,
            COUNT(*) FILTER (WHERE status = 'diterima') as diterima,
            COUNT(*) FILTER (WHERE status = 'ditolak') as ditolak,
            COUNT(*) FILTER (WHERE status = 'waitlist') as waitlist,
            COUNT(*) FILTER (WHERE status = 'batal') as batal
        ")->first();

        return $stats->toArray();
    }
}
