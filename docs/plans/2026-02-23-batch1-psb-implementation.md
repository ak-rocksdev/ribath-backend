# Batch 1: PSB Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build PSB (Pendaftaran Santri Baru) API — public registration form, admin lead management, PSB periods, and acceptance flow that creates santri + wali user records.

**Architecture:** Three new tables (`psb_periods`, `psb_registrations`, `santri`) with UUID primary keys. Business logic lives in `PsbService`. Controllers extend the base `Controller` which provides `ApiResponseTrait`. All admin routes protected by Spatie Permission middleware. Public endpoints have no auth.

**Tech Stack:** Laravel 12, PostgreSQL 18, Pest, Spatie Permission v6, Sanctum v4

**Design Doc:** `docs/plans/2026-02-23-batch1-psb-design.md`

**Existing patterns to follow:**
- Controllers use constructor DI for services (see `AuthController`)
- All controllers extend `Controller` which has `ApiResponseTrait` (`successResponse`, `errorResponse`, `paginatedResponse`)
- Validation in Form Request classes (never in controllers)
- Tests use Pest syntax with `RefreshDatabase`
- Routes under `Route::prefix('v1')` in `routes/api.php`

---

### Task 1: Migrations — psb_periods, psb_registrations, santri

**Files:**
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_create_psb_periods_table.php`
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_create_psb_registrations_table.php`
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_create_santri_table.php`

**Step 1: Create the three migrations**

```php
// create_psb_periods_table.php
Schema::create('psb_periods', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name', 100);
    $table->string('year', 20);
    $table->integer('gelombang');
    $table->timestamp('pendaftaran_buka');
    $table->timestamp('pendaftaran_tutup');
    $table->date('tanggal_masuk');
    $table->decimal('biaya_pendaftaran', 12, 2)->default(0);
    $table->decimal('biaya_spp_bulanan', 12, 2)->default(0);
    $table->integer('kuota_santri')->nullable();
    $table->integer('kuota_terisi')->default(0);
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// create_psb_registrations_table.php
Schema::create('psb_registrations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('psb_period_id')->nullable()->constrained('psb_periods')->nullOnDelete();
    $table->string('registration_number')->unique();
    $table->string('status', 20)->default('baru');
    $table->string('registrant_type', 10);
    $table->string('nama_lengkap', 100);
    $table->string('tempat_lahir', 100)->nullable();
    $table->date('tanggal_lahir');
    $table->string('jenis_kelamin', 1);
    $table->string('program_minat', 20);
    $table->string('nama_wali', 100)->nullable();
    $table->string('no_hp_wali', 20);
    $table->string('email_wali', 255)->nullable();
    $table->string('sumber_info', 50)->nullable();
    $table->text('admin_notes')->nullable();
    $table->timestamp('contacted_at')->nullable();
    $table->foreignUuid('contacted_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('interviewed_at')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->text('rejection_reason')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index('status');
    $table->index('registration_number');
    $table->index(['status', 'created_at']);
});

// create_santri_table.php
Schema::create('santri', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('psb_registration_id')->nullable()->constrained('psb_registrations')->nullOnDelete();
    $table->foreignUuid('wali_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('nama_lengkap', 100);
    $table->string('tempat_lahir', 100)->nullable();
    $table->date('tanggal_lahir');
    $table->string('jenis_kelamin', 1);
    $table->string('program', 20);
    $table->string('status', 20)->default('aktif');
    $table->date('tanggal_masuk');
    $table->timestamps();
});
```

**Step 2: Run migrations**

Run: `php artisan migrate`
Expected: 3 tables created, 0 errors.

**Step 3: Commit**

```bash
git add database/migrations/
git commit -m "Add migrations for psb_periods, psb_registrations, santri tables"
```

---

### Task 2: Models + Factories — PsbPeriod, PsbRegistration, Santri

**Files:**
- Create: `app/Models/PsbPeriod.php`
- Create: `app/Models/PsbRegistration.php`
- Create: `app/Models/Santri.php`
- Create: `database/factories/PsbPeriodFactory.php`
- Create: `database/factories/PsbRegistrationFactory.php`
- Create: `database/factories/SantriFactory.php`

**Step 1: Create PsbPeriod model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsbPeriod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'year',
        'gelombang',
        'pendaftaran_buka',
        'pendaftaran_tutup',
        'tanggal_masuk',
        'biaya_pendaftaran',
        'biaya_spp_bulanan',
        'kuota_santri',
        'kuota_terisi',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pendaftaran_buka' => 'datetime',
            'pendaftaran_tutup' => 'datetime',
            'tanggal_masuk' => 'date',
            'biaya_pendaftaran' => 'decimal:2',
            'biaya_spp_bulanan' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PsbRegistration::class);
    }
}
```

**Step 2: Create PsbRegistration model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PsbRegistration extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_BARU = 'baru';
    public const STATUS_DIHUBUNGI = 'dihubungi';
    public const STATUS_INTERVIEW = 'interview';
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_WAITLIST = 'waitlist';
    public const STATUS_BATAL = 'batal';

    public const STATUSES = [
        self::STATUS_BARU,
        self::STATUS_DIHUBUNGI,
        self::STATUS_INTERVIEW,
        self::STATUS_DITERIMA,
        self::STATUS_DITOLAK,
        self::STATUS_WAITLIST,
        self::STATUS_BATAL,
    ];

    protected $fillable = [
        'psb_period_id',
        'registration_number',
        'status',
        'registrant_type',
        'nama_lengkap',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'program_minat',
        'nama_wali',
        'no_hp_wali',
        'email_wali',
        'sumber_info',
        'admin_notes',
        'contacted_at',
        'contacted_by',
        'interviewed_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'contacted_at' => 'datetime',
            'interviewed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PsbPeriod::class, 'psb_period_id');
    }

    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function santri(): HasOne
    {
        return $this->hasOne(Santri::class, 'psb_registration_id');
    }
}
```

**Step 3: Create Santri model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Santri extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'santri';

    protected $fillable = [
        'psb_registration_id',
        'wali_user_id',
        'user_id',
        'nama_lengkap',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'program',
        'status',
        'tanggal_masuk',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'tanggal_masuk' => 'date',
        ];
    }

    public function psbRegistration(): BelongsTo
    {
        return $this->belongsTo(PsbRegistration::class, 'psb_registration_id');
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wali_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

**Step 4: Create PsbPeriodFactory**

```php
<?php

namespace Database\Factories;

use App\Models\PsbPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsbPeriodFactory extends Factory
{
    protected $model = PsbPeriod::class;

    public function definition(): array
    {
        $year = now()->year;

        return [
            'name' => "Pendaftaran {$year}/" . ($year + 1),
            'year' => "{$year}/" . ($year + 1),
            'gelombang' => 1,
            'pendaftaran_buka' => now(),
            'pendaftaran_tutup' => now()->addMonths(2),
            'tanggal_masuk' => now()->addMonths(3)->toDateString(),
            'biaya_pendaftaran' => 500000,
            'biaya_spp_bulanan' => 1000000,
            'kuota_santri' => 30,
            'kuota_terisi' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'kuota_terisi' => $attributes['kuota_santri'],
        ]);
    }
}
```

**Step 5: Create PsbRegistrationFactory**

```php
<?php

namespace Database\Factories;

use App\Models\PsbRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

class PsbRegistrationFactory extends Factory
{
    protected $model = PsbRegistration::class;

    public function definition(): array
    {
        return [
            'registration_number' => 'PSB-' . now()->year . '-' . str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => PsbRegistration::STATUS_BARU,
            'registrant_type' => 'wali',
            'nama_lengkap' => fake()->name(),
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-20 years', '-5 years')->format('Y-m-d'),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'program_minat' => fake()->randomElement(['tahfidz', 'regular']),
            'nama_wali' => fake()->name(),
            'no_hp_wali' => '08' . fake()->numerify('##########'),
            'email_wali' => fake()->safeEmail(),
            'sumber_info' => fake()->randomElement(['sosial_media', 'website', 'teman_keluarga', 'alumni', 'masjid', 'brosur', 'google', 'lainnya']),
        ];
    }

    public function selfRegistered(): static
    {
        return $this->state([
            'registrant_type' => 'santri',
            'nama_wali' => null,
            'email_wali' => null,
        ]);
    }
}
```

**Step 6: Create SantriFactory**

```php
<?php

namespace Database\Factories;

use App\Models\Santri;
use Illuminate\Database\Eloquent\Factories\Factory;

class SantriFactory extends Factory
{
    protected $model = Santri::class;

    public function definition(): array
    {
        return [
            'nama_lengkap' => fake()->name(),
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-20 years', '-5 years')->format('Y-m-d'),
            'jenis_kelamin' => fake()->randomElement(['L', 'P']),
            'program' => fake()->randomElement(['tahfidz', 'regular']),
            'status' => 'aktif',
            'tanggal_masuk' => now()->addMonths(3)->toDateString(),
        ];
    }
}
```

**Step 7: Run tests to confirm models work**

Run: `php artisan test`
Expected: All existing 18 tests still pass.

**Step 8: Commit**

```bash
git add app/Models/ database/factories/
git commit -m "Add PsbPeriod, PsbRegistration, Santri models and factories"
```

---

### Task 3: RegistrationNumberGenerator + PsbService

**Files:**
- Create: `app/Services/RegistrationNumberGenerator.php`
- Create: `app/Services/PsbService.php`
- Create: `tests/Feature/PSB/PsbServiceTest.php`

**Step 1: Create RegistrationNumberGenerator**

```php
<?php

namespace App\Services;

use App\Models\PsbRegistration;

class RegistrationNumberGenerator
{
    public function generate(): string
    {
        $year = now()->year;
        $prefix = "PSB-{$year}-";

        $lastNumber = PsbRegistration::withTrashed()
            ->where('registration_number', 'like', "{$prefix}%")
            ->orderByRaw("CAST(SUBSTRING(registration_number FROM '[0-9]+$') AS INTEGER) DESC")
            ->value('registration_number');

        $nextSequence = 1;
        if ($lastNumber) {
            $nextSequence = (int) substr($lastNumber, strrpos($lastNumber, '-') + 1) + 1;
        }

        return $prefix . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
    }
}
```

**Step 2: Create PsbService**

```php
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
```

**Step 3: Write tests for PsbService**

```php
<?php
// tests/Feature/PSB/PsbServiceTest.php

use App\Models\PsbPeriod;
use App\Models\PsbRegistration;
use App\Models\User;
use App\Services\PsbService;
use App\Services\RegistrationNumberGenerator;

beforeEach(function () {
    $this->service = app(PsbService::class);
});

test('register creates registration with auto-generated number', function () {
    $registration = $this->service->register([
        'registrant_type' => 'wali',
        'nama_lengkap' => 'Ahmad Fauzi',
        'tempat_lahir' => 'Solo',
        'tanggal_lahir' => '2015-03-10',
        'jenis_kelamin' => 'L',
        'program_minat' => 'tahfidz',
        'nama_wali' => 'Budi Santoso',
        'no_hp_wali' => '081234567890',
        'email_wali' => 'budi@test.com',
        'sumber_info' => 'website',
    ]);

    expect($registration->registration_number)->toStartWith('PSB-' . now()->year . '-')
        ->and($registration->status)->toBe('baru')
        ->and($registration->nama_lengkap)->toBe('Ahmad Fauzi');
});

test('register assigns waitlist status when period quota is full', function () {
    $period = PsbPeriod::factory()->full()->create();

    $registration = $this->service->register([
        'registrant_type' => 'wali',
        'nama_lengkap' => 'Test Student',
        'tanggal_lahir' => '2015-01-01',
        'jenis_kelamin' => 'L',
        'program_minat' => 'regular',
        'nama_wali' => 'Test Wali',
        'no_hp_wali' => '081234567890',
    ]);

    expect($registration->status)->toBe('waitlist');
});

test('accept registration creates santri and wali user', function () {
    \Spatie\Permission\Models\Role::create(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $period = PsbPeriod::factory()->create(['kuota_terisi' => 5]);
    $registration = PsbRegistration::factory()->create([
        'psb_period_id' => $period->id,
        'nama_wali' => 'Budi Santoso',
        'email_wali' => 'budi@test.com',
        'no_hp_wali' => '081234567890',
    ]);

    $result = $this->service->acceptRegistration($registration, $admin);

    expect($result['santri']->nama_lengkap)->toBe($registration->nama_lengkap)
        ->and($result['santri']->wali_user_id)->not->toBeNull()
        ->and($result['santri']->status)->toBe('aktif')
        ->and($result['wali_user']['temporary_password'])->toBeString()
        ->and($result['registration']->status)->toBe('diterima')
        ->and($period->fresh()->kuota_terisi)->toBe(6);

    $waliUser = User::find($result['wali_user']['id']);
    expect($waliUser->hasRole('wali_santri'))->toBeTrue();
});

test('accept self-registered student creates santri without wali user', function () {
    \Spatie\Permission\Models\Role::create(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $registration = PsbRegistration::factory()->selfRegistered()->create();

    $result = $this->service->acceptRegistration($registration, $admin);

    expect($result['santri']->wali_user_id)->toBeNull()
        ->and($result)->not->toHaveKey('wali_user');
});

test('reject registration stores reason and updates status', function () {
    \Spatie\Permission\Models\Role::create(['name' => 'super_admin']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $registration = PsbRegistration::factory()->create();

    $result = $this->service->rejectRegistration($registration, $admin, 'Tidak memenuhi syarat');

    expect($result->status)->toBe('ditolak')
        ->and($result->rejection_reason)->toBe('Tidak memenuhi syarat')
        ->and($result->reviewed_by)->toBe($admin->id);
});

test('registration number generator creates sequential numbers', function () {
    $generator = app(RegistrationNumberGenerator::class);

    $first = $generator->generate();
    PsbRegistration::factory()->create(['registration_number' => $first]);

    $second = $generator->generate();

    expect($first)->toBe('PSB-' . now()->year . '-00001')
        ->and($second)->toBe('PSB-' . now()->year . '-00002');
});

test('get registration stats returns correct counts', function () {
    PsbRegistration::factory()->create(['status' => 'baru']);
    PsbRegistration::factory()->create(['status' => 'baru']);
    PsbRegistration::factory()->create(['status' => 'dihubungi']);
    PsbRegistration::factory()->create(['status' => 'diterima']);

    $stats = $this->service->getRegistrationStats();

    expect($stats['total'])->toBe(4)
        ->and($stats['baru'])->toBe(2)
        ->and($stats['dihubungi'])->toBe(1)
        ->and($stats['diterima'])->toBe(1)
        ->and($stats['ditolak'])->toBe(0);
});
```

**Step 4: Run tests**

Run: `php artisan test tests/Feature/PSB/PsbServiceTest.php`
Expected: All 7 tests pass.

**Step 5: Commit**

```bash
git add app/Services/RegistrationNumberGenerator.php app/Services/PsbService.php tests/Feature/PSB/
git commit -m "Add PsbService and RegistrationNumberGenerator with tests"
```

---

### Task 4: Form Request validation classes

**Files:**
- Create: `app/Http/Requests/PSB/QuickRegistrationRequest.php`
- Create: `app/Http/Requests/PSB/StorePsbPeriodRequest.php`
- Create: `app/Http/Requests/PSB/UpdatePsbPeriodRequest.php`
- Create: `app/Http/Requests/PSB/UpdateRegistrationStatusRequest.php`
- Create: `app/Http/Requests/PSB/RejectRegistrationRequest.php`

**Step 1: Create all Form Requests**

```php
<?php
// app/Http/Requests/PSB/QuickRegistrationRequest.php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class QuickRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    public function rules(): array
    {
        return [
            'registrant_type' => ['required', 'string', 'in:wali,santri'],
            'nama_lengkap' => ['required', 'string', 'min:3', 'max:100'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'jenis_kelamin' => ['required', 'string', 'in:L,P'],
            'program_minat' => ['required', 'string', 'in:tahfidz,regular'],
            'nama_wali' => ['required_if:registrant_type,wali', 'nullable', 'string', 'min:3', 'max:100'],
            'no_hp_wali' => ['required', 'string', 'regex:/^(\+62|62|0)8[1-9][0-9]{7,10}$/'],
            'email_wali' => ['nullable', 'email', 'max:255'],
            'sumber_info' => ['nullable', 'string', 'in:sosial_media,website,teman_keluarga,alumni,masjid,brosur,google,lainnya'],
        ];
    }
}
```

```php
<?php
// app/Http/Requests/PSB/StorePsbPeriodRequest.php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class StorePsbPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission checked via middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'year' => ['required', 'string', 'max:20'],
            'gelombang' => ['required', 'integer', 'min:1'],
            'pendaftaran_buka' => ['required', 'date'],
            'pendaftaran_tutup' => ['required', 'date', 'after:pendaftaran_buka'],
            'tanggal_masuk' => ['required', 'date', 'after:pendaftaran_tutup'],
            'biaya_pendaftaran' => ['required', 'numeric', 'min:0'],
            'biaya_spp_bulanan' => ['required', 'numeric', 'min:0'],
            'kuota_santri' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
```

```php
<?php
// app/Http/Requests/PSB/UpdatePsbPeriodRequest.php

namespace App\Http\Requests\PSB;

class UpdatePsbPeriodRequest extends StorePsbPeriodRequest
{
    // Same rules as StorePsbPeriodRequest
}
```

```php
<?php
// app/Http/Requests/PSB/UpdateRegistrationStatusRequest.php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistrationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:dihubungi,interview,waitlist,batal'],
            'admin_notes' => ['nullable', 'string'],
            'interviewed_at' => ['nullable', 'date'],
        ];
    }
}
```

```php
<?php
// app/Http/Requests/PSB/RejectRegistrationRequest.php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class RejectRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:5'],
        ];
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Requests/PSB/
git commit -m "Add PSB form request validation classes"
```

---

### Task 5: Update RolePermissionSeeder with PSB permissions

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`
- Create: `tests/Feature/PSB/PsbPermissionSeederTest.php`

**Step 1: Update the seeder**

Add 4 new permissions to the `$permissions` array and assign them to `pengurus_pesantren`:

```php
$permissions = [
    'view-users',
    'create-users',
    'edit-users',
    'delete-users',
    'manage-roles',
    'manage-settings',
    // PSB
    'view-psb-registrations',
    'manage-psb-registrations',
    'view-psb-periods',
    'manage-psb-periods',
];
```

Update the `pengurus_pesantren` sync:

```php
$pengurusPesantren->syncPermissions([
    'view-users',
    'manage-settings',
    'view-psb-registrations',
    'manage-psb-registrations',
    'view-psb-periods',
    'manage-psb-periods',
]);
```

**Step 2: Write test for new permissions**

```php
<?php
// tests/Feature/PSB/PsbPermissionSeederTest.php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeder creates psb permissions', function () {
    $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

    expect(Permission::where('name', 'view-psb-registrations')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-psb-registrations')->exists())->toBeTrue()
        ->and(Permission::where('name', 'view-psb-periods')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-psb-periods')->exists())->toBeTrue();
});

test('pengurus_pesantren has psb permissions', function () {
    $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);

    $role = Role::findByName('pengurus_pesantren');

    expect($role->hasPermissionTo('view-psb-registrations'))->toBeTrue()
        ->and($role->hasPermissionTo('manage-psb-registrations'))->toBeTrue()
        ->and($role->hasPermissionTo('view-psb-periods'))->toBeTrue()
        ->and($role->hasPermissionTo('manage-psb-periods'))->toBeTrue();
});
```

**Step 3: Run tests**

Run: `php artisan test`
Expected: All tests pass (existing + new).

**Step 4: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php tests/Feature/PSB/PsbPermissionSeederTest.php
git commit -m "Add PSB permissions to RolePermissionSeeder"
```

---

### Task 6: PublicPsbController — public registration + active period

**Files:**
- Create: `app/Http/Controllers/Api/PSB/PublicPsbController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/PSB/PublicPsbTest.php`

**Step 1: Create PublicPsbController**

```php
<?php

namespace App\Http\Controllers\Api\PSB;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\QuickRegistrationRequest;
use App\Models\PsbPeriod;
use App\Services\PsbService;
use Illuminate\Http\JsonResponse;

class PublicPsbController extends Controller
{
    public function __construct(
        private PsbService $psbService
    ) {}

    public function activePeriod(): JsonResponse
    {
        $activePeriod = PsbPeriod::where('is_active', true)
            ->where('pendaftaran_buka', '<=', now())
            ->where('pendaftaran_tutup', '>=', now())
            ->first();

        if (! $activePeriod) {
            return $this->successResponse(null, 'Tidak ada periode pendaftaran yang aktif saat ini');
        }

        return $this->successResponse($activePeriod);
    }

    public function register(QuickRegistrationRequest $request): JsonResponse
    {
        $registration = $this->psbService->register($request->validated());

        return $this->successResponse([
            'registration_number' => $registration->registration_number,
            'status' => $registration->status,
        ], 'Pendaftaran berhasil dikirim', 201);
    }
}
```

**Step 2: Add public routes to `routes/api.php`**

Add inside the existing `Route::prefix('v1')` group:

```php
// Public PSB routes (no auth)
Route::prefix('public/psb')->group(function () {
    Route::get('active-period', [PublicPsbController::class, 'activePeriod']);
    Route::post('register', [PublicPsbController::class, 'register']);
});
```

**Step 3: Write tests**

```php
<?php
// tests/Feature/PSB/PublicPsbTest.php

use App\Models\PsbPeriod;
use App\Models\PsbRegistration;

test('get active period returns current active period', function () {
    $period = PsbPeriod::factory()->create([
        'pendaftaran_buka' => now()->subDay(),
        'pendaftaran_tutup' => now()->addMonth(),
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/public/psb/active-period');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $period->id);
});

test('get active period returns null when no active period', function () {
    PsbPeriod::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/public/psb/active-period');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', null);
});

test('quick registration succeeds with valid data', function () {
    $response = $this->postJson('/api/v1/public/psb/register', [
        'registrant_type' => 'wali',
        'nama_lengkap' => 'Ahmad Fauzi',
        'tempat_lahir' => 'Solo',
        'tanggal_lahir' => '2015-03-10',
        'jenis_kelamin' => 'L',
        'program_minat' => 'tahfidz',
        'nama_wali' => 'Budi Santoso',
        'no_hp_wali' => '081234567890',
        'email_wali' => 'budi@test.com',
        'sumber_info' => 'website',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'baru')
        ->assertJsonStructure(['success', 'data' => ['registration_number', 'status'], 'message']);

    $this->assertDatabaseHas('psb_registrations', [
        'nama_lengkap' => 'Ahmad Fauzi',
        'nama_wali' => 'Budi Santoso',
    ]);
});

test('quick registration validates required fields', function () {
    $response = $this->postJson('/api/v1/public/psb/register', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['registrant_type', 'nama_lengkap', 'tanggal_lahir', 'jenis_kelamin', 'program_minat', 'no_hp_wali']);
});

test('quick registration validates phone number format', function () {
    $response = $this->postJson('/api/v1/public/psb/register', [
        'registrant_type' => 'wali',
        'nama_lengkap' => 'Ahmad Fauzi',
        'tanggal_lahir' => '2015-03-10',
        'jenis_kelamin' => 'L',
        'program_minat' => 'tahfidz',
        'nama_wali' => 'Budi Santoso',
        'no_hp_wali' => '12345',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['no_hp_wali']);
});

test('quick registration requires nama_wali when registrant is wali', function () {
    $response = $this->postJson('/api/v1/public/psb/register', [
        'registrant_type' => 'wali',
        'nama_lengkap' => 'Ahmad Fauzi',
        'tanggal_lahir' => '2015-03-10',
        'jenis_kelamin' => 'L',
        'program_minat' => 'tahfidz',
        'no_hp_wali' => '081234567890',
        // nama_wali missing
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['nama_wali']);
});

test('quick registration sets waitlist when period quota is full', function () {
    PsbPeriod::factory()->create([
        'pendaftaran_buka' => now()->subDay(),
        'pendaftaran_tutup' => now()->addMonth(),
        'kuota_santri' => 10,
        'kuota_terisi' => 10,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/public/psb/register', [
        'registrant_type' => 'santri',
        'nama_lengkap' => 'Self Student',
        'tanggal_lahir' => '2008-01-01',
        'jenis_kelamin' => 'P',
        'program_minat' => 'regular',
        'no_hp_wali' => '081234567890',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'waitlist');
});
```

**Step 4: Run tests**

Run: `php artisan test tests/Feature/PSB/PublicPsbTest.php`
Expected: All 7 tests pass.

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PSB/PublicPsbController.php routes/api.php tests/Feature/PSB/PublicPsbTest.php
git commit -m "Add public PSB endpoints: active period and quick registration"
```

---

### Task 7: PsbPeriodController — admin CRUD for periods

**Files:**
- Create: `app/Http/Controllers/Api/PSB/PsbPeriodController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/PSB/PsbPeriodTest.php`

**Step 1: Create PsbPeriodController**

```php
<?php

namespace App\Http\Controllers\Api\PSB;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\StorePsbPeriodRequest;
use App\Http\Requests\PSB\UpdatePsbPeriodRequest;
use App\Models\PsbPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PsbPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $periods = PsbPeriod::orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($periods);
    }

    public function store(StorePsbPeriodRequest $request): JsonResponse
    {
        $period = PsbPeriod::create($request->validated());

        return $this->successResponse($period, 'Periode PSB berhasil dibuat', 201);
    }

    public function show(PsbPeriod $period): JsonResponse
    {
        return $this->successResponse($period);
    }

    public function update(UpdatePsbPeriodRequest $request, PsbPeriod $period): JsonResponse
    {
        $period->update($request->validated());

        return $this->successResponse($period->fresh(), 'Periode PSB berhasil diperbarui');
    }

    public function destroy(PsbPeriod $period): JsonResponse
    {
        if ($period->registrations()->exists()) {
            return $this->errorResponse(
                'Periode tidak dapat dihapus karena sudah memiliki data pendaftaran',
                null,
                422
            );
        }

        $period->delete();

        return $this->successResponse(null, 'Periode PSB berhasil dihapus');
    }
}
```

**Step 2: Add admin period routes to `routes/api.php`**

Inside the `Route::prefix('v1')` group, add authenticated PSB routes:

```php
// Admin PSB routes
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('psb')->group(function () {
        Route::apiResource('periods', PsbPeriodController::class)
            ->middleware('permission:view-psb-periods')
            ->only(['index', 'show']);

        Route::apiResource('periods', PsbPeriodController::class)
            ->middleware('permission:manage-psb-periods')
            ->only(['store', 'update', 'destroy']);
    });
});
```

**Step 3: Write tests**

```php
<?php
// tests/Feature/PSB/PsbPeriodTest.php

use App\Models\PsbPeriod;
use App\Models\PsbRegistration;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::create(['name' => 'super_admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('admin can list psb periods', function () {
    PsbPeriod::factory()->count(3)->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/periods');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('admin can create psb period', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Pendaftaran 2026/2027',
            'year' => '2026/2027',
            'gelombang' => 1,
            'pendaftaran_buka' => '2026-03-01 00:00:00',
            'pendaftaran_tutup' => '2026-05-31 23:59:59',
            'tanggal_masuk' => '2026-07-01',
            'biaya_pendaftaran' => 500000,
            'biaya_spp_bulanan' => 1000000,
            'kuota_santri' => 30,
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Pendaftaran 2026/2027');
});

test('create period validates dates order', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test',
            'year' => '2026/2027',
            'gelombang' => 1,
            'pendaftaran_buka' => '2026-06-01',
            'pendaftaran_tutup' => '2026-03-01', // before buka
            'tanggal_masuk' => '2026-07-01',
            'biaya_pendaftaran' => 0,
            'biaya_spp_bulanan' => 0,
            'is_active' => true,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['pendaftaran_tutup']);
});

test('admin can show psb period', function () {
    $period = PsbPeriod::factory()->create();

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/psb/periods/{$period->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $period->id);
});

test('admin can update psb period', function () {
    $period = PsbPeriod::factory()->create();

    $response = $this->actingAs($this->admin)
        ->putJson("/api/v1/psb/periods/{$period->id}", [
            'name' => 'Updated Period',
            'year' => '2026/2027',
            'gelombang' => 2,
            'pendaftaran_buka' => '2026-03-01',
            'pendaftaran_tutup' => '2026-05-31',
            'tanggal_masuk' => '2026-07-01',
            'biaya_pendaftaran' => 600000,
            'biaya_spp_bulanan' => 1200000,
            'is_active' => true,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Period')
        ->assertJsonPath('data.gelombang', 2);
});

test('admin can delete psb period without registrations', function () {
    $period = PsbPeriod::factory()->create();

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/psb/periods/{$period->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('psb_periods', ['id' => $period->id]);
});

test('admin cannot delete psb period with registrations', function () {
    $period = PsbPeriod::factory()->create();
    PsbRegistration::factory()->create(['psb_period_id' => $period->id]);

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/psb/periods/{$period->id}");

    $response->assertUnprocessable();
    $this->assertDatabaseHas('psb_periods', ['id' => $period->id]);
});

test('unauthenticated user cannot access admin period endpoints', function () {
    $this->getJson('/api/v1/psb/periods')->assertUnauthorized();
    $this->postJson('/api/v1/psb/periods')->assertUnauthorized();
});
```

**Step 4: Run tests**

Run: `php artisan test tests/Feature/PSB/PsbPeriodTest.php`
Expected: All 8 tests pass.

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PSB/PsbPeriodController.php routes/api.php tests/Feature/PSB/PsbPeriodTest.php
git commit -m "Add admin PSB period CRUD endpoints with tests"
```

---

### Task 8: PsbRegistrationController — list, show, stats, status update, accept, reject, delete

**Files:**
- Create: `app/Http/Controllers/Api/PSB/PsbRegistrationController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/PSB/PsbRegistrationTest.php`

**Step 1: Create PsbRegistrationController**

```php
<?php

namespace App\Http\Controllers\Api\PSB;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\RejectRegistrationRequest;
use App\Http\Requests\PSB\UpdateRegistrationStatusRequest;
use App\Models\PsbRegistration;
use App\Services\PsbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PsbRegistrationController extends Controller
{
    public function __construct(
        private PsbService $psbService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PsbRegistration::with('period')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('psb_period_id')) {
            $query->where('psb_period_id', $request->input('psb_period_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'ilike', "%{$search}%")
                    ->orWhere('registration_number', 'ilike', "%{$search}%")
                    ->orWhere('no_hp_wali', 'ilike', "%{$search}%");
            });
        }

        $registrations = $query->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($registrations);
    }

    public function show(PsbRegistration $registration): JsonResponse
    {
        $registration->load('period');

        return $this->successResponse($registration);
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = $this->psbService->getRegistrationStats($request->input('psb_period_id'));

        return $this->successResponse($stats);
    }

    public function updateStatus(UpdateRegistrationStatusRequest $request, PsbRegistration $registration): JsonResponse
    {
        $updateData = ['status' => $request->validated('status')];

        if ($request->filled('admin_notes')) {
            $updateData['admin_notes'] = $request->validated('admin_notes');
        }

        if ($request->validated('status') === 'dihubungi' && ! $registration->contacted_at) {
            $updateData['contacted_at'] = now();
            $updateData['contacted_by'] = $request->user()->id;
        }

        if ($request->validated('status') === 'interview' && $request->filled('interviewed_at')) {
            $updateData['interviewed_at'] = $request->validated('interviewed_at');
        }

        $registration->update($updateData);

        return $this->successResponse($registration->fresh(), 'Status pendaftaran berhasil diperbarui');
    }

    public function accept(PsbRegistration $registration, Request $request): JsonResponse
    {
        if ($registration->status === PsbRegistration::STATUS_DITERIMA) {
            return $this->errorResponse('Pendaftaran ini sudah diterima sebelumnya', null, 422);
        }

        $result = $this->psbService->acceptRegistration($registration, $request->user());

        return $this->successResponse($result, 'Pendaftaran diterima, data santri berhasil dibuat', 201);
    }

    public function reject(RejectRegistrationRequest $request, PsbRegistration $registration): JsonResponse
    {
        if ($registration->status === PsbRegistration::STATUS_DITOLAK) {
            return $this->errorResponse('Pendaftaran ini sudah ditolak sebelumnya', null, 422);
        }

        $result = $this->psbService->rejectRegistration(
            $registration,
            $request->user(),
            $request->validated('rejection_reason')
        );

        return $this->successResponse($result, 'Pendaftaran ditolak');
    }

    public function destroy(PsbRegistration $registration): JsonResponse
    {
        $registration->delete();

        return $this->successResponse(null, 'Pendaftaran berhasil dihapus');
    }
}
```

**Step 2: Add registration routes to `routes/api.php`**

Inside the authenticated PSB group:

```php
Route::prefix('psb/registrations')->group(function () {
    Route::get('/', [PsbRegistrationController::class, 'index'])
        ->middleware('permission:view-psb-registrations');
    Route::get('/stats', [PsbRegistrationController::class, 'stats'])
        ->middleware('permission:view-psb-registrations');
    Route::get('/{registration}', [PsbRegistrationController::class, 'show'])
        ->middleware('permission:view-psb-registrations');
    Route::patch('/{registration}/status', [PsbRegistrationController::class, 'updateStatus'])
        ->middleware('permission:manage-psb-registrations');
    Route::post('/{registration}/accept', [PsbRegistrationController::class, 'accept'])
        ->middleware('permission:manage-psb-registrations');
    Route::post('/{registration}/reject', [PsbRegistrationController::class, 'reject'])
        ->middleware('permission:manage-psb-registrations');
    Route::delete('/{registration}', [PsbRegistrationController::class, 'destroy'])
        ->middleware('permission:manage-psb-registrations');
});
```

**Step 3: Write tests**

```php
<?php
// tests/Feature/PSB/PsbRegistrationTest.php

use App\Models\PsbPeriod;
use App\Models\PsbRegistration;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::create(['name' => 'super_admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

test('admin can list registrations', function () {
    PsbRegistration::factory()->count(3)->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('admin can filter registrations by status', function () {
    PsbRegistration::factory()->create(['status' => 'baru']);
    PsbRegistration::factory()->create(['status' => 'dihubungi']);
    PsbRegistration::factory()->create(['status' => 'baru']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations?status=baru');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('admin can search registrations by name', function () {
    PsbRegistration::factory()->create(['nama_lengkap' => 'Ahmad Fauzi']);
    PsbRegistration::factory()->create(['nama_lengkap' => 'Siti Aisyah']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations?search=ahmad');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('admin can show registration detail', function () {
    $registration = PsbRegistration::factory()->create();

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/psb/registrations/{$registration->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $registration->id);
});

test('admin can get registration stats', function () {
    PsbRegistration::factory()->count(2)->create(['status' => 'baru']);
    PsbRegistration::factory()->create(['status' => 'diterima']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations/stats');

    $response->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.baru', 2)
        ->assertJsonPath('data.diterima', 1);
});

test('admin can update registration status to dihubungi', function () {
    $registration = PsbRegistration::factory()->create(['status' => 'baru']);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'dihubungi',
            'admin_notes' => 'Called via WhatsApp',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'dihubungi')
        ->assertJsonPath('data.admin_notes', 'Called via WhatsApp');

    expect($registration->fresh()->contacted_at)->not->toBeNull()
        ->and($registration->fresh()->contacted_by)->toBe($this->admin->id);
});

test('admin can accept registration with wali data', function () {
    $period = PsbPeriod::factory()->create(['kuota_terisi' => 0]);
    $registration = PsbRegistration::factory()->create([
        'psb_period_id' => $period->id,
        'nama_wali' => 'Budi Santoso',
        'email_wali' => 'budi@test.com',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.santri.nama_lengkap', $registration->nama_lengkap)
        ->assertJsonStructure(['success', 'data' => ['santri', 'registration', 'wali_user']]);

    expect($registration->fresh()->status)->toBe('diterima')
        ->and($period->fresh()->kuota_terisi)->toBe(1);

    $this->assertDatabaseHas('santri', [
        'psb_registration_id' => $registration->id,
    ]);
});

test('admin can accept self-registered student without wali', function () {
    $registration = PsbRegistration::factory()->selfRegistered()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $response->assertCreated()
        ->assertJsonPath('data.santri.wali_user_id', null);

    expect($response->json('data'))->not->toHaveKey('wali_user');
});

test('admin cannot accept already accepted registration', function () {
    $registration = PsbRegistration::factory()->create(['status' => 'diterima']);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $response->assertUnprocessable();
});

test('admin can reject registration with reason', function () {
    $registration = PsbRegistration::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/reject", [
            'rejection_reason' => 'Tidak memenuhi syarat usia',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'ditolak')
        ->assertJsonPath('data.rejection_reason', 'Tidak memenuhi syarat usia');
});

test('reject requires reason', function () {
    $registration = PsbRegistration::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/reject", []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['rejection_reason']);
});

test('admin can soft delete registration', function () {
    $registration = PsbRegistration::factory()->create();

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/psb/registrations/{$registration->id}");

    $response->assertOk();
    $this->assertSoftDeleted('psb_registrations', ['id' => $registration->id]);
});

test('unauthenticated user cannot access registration endpoints', function () {
    $this->getJson('/api/v1/psb/registrations')->assertUnauthorized();
});
```

**Step 4: Run all tests**

Run: `php artisan test`
Expected: All tests pass (existing + all PSB tests).

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PSB/PsbRegistrationController.php routes/api.php tests/Feature/PSB/PsbRegistrationTest.php
git commit -m "Add admin PSB registration endpoints: list, stats, status, accept, reject, delete"
```

---

### Task 9: Run seeder on actual database + final verification

**Step 1: Run the updated seeder**

Run: `php artisan db:seed --class=RolePermissionSeeder`
Expected: 4 new PSB permissions created, assigned to pengurus_pesantren.

**Step 2: Run all tests one final time**

Run: `php artisan test`
Expected: All tests pass — should be around 40+ tests total.

**Step 3: Run Pint for code style**

Run: `./vendor/bin/pint`
Expected: Code formatted.

**Step 4: Final commit (if pint changed anything)**

```bash
git add -A
git commit -m "Apply code style fixes via Pint"
```

**Step 5: Push to remote**

```bash
git push origin main
```
