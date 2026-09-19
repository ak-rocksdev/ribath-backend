<?php

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/*
 * Wajib ganti password (role-ustadz ticket 09): an account created through
 * "Beri Akses", or whose password an admin resets, must change its password
 * before anything else. While `must_change_password` is on, every
 * authenticated endpoint except GET /auth/me, PUT /auth/change-password and
 * POST /auth/logout answers 403 with the code PASSWORD_CHANGE_REQUIRED.
 *
 * The Akun Ustadz calls the API with the Bearer token of a real login, so
 * the tests also prove the check runs after Sanctum has resolved the token.
 */

const TEMPORARY_PASSWORD = 'sementara123';
const CHOSEN_PASSWORD = 'pilihan-sendiri-456';

const PASSWORD_CHANGE_REQUIRED_RESPONSE = [
    'success' => false,
    'message' => 'Anda harus mengganti password sebelum melanjutkan.',
    'code' => 'PASSWORD_CHANGE_REQUIRED',
];

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $this->school = School::where('is_active', true)->firstOrFail();

    AcademicYear::factory()->create([
        'school_id' => $this->school->id,
        'is_active' => true,
        'active_semester' => 1,
    ]);

    $this->superAdmin = User::factory()->create(['school_id' => $this->school->id]);
    $this->superAdmin->assignRole('super_admin');

    $this->teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'user_id' => null]);
});

/** "Beri Akses" as super_admin (POST /teachers/{teacher}/grant-access); returns the new Akun Ustadz. */
function grantAccessWithTemporaryPassword($testCase, Teacher $teacher, string $email = 'ustadz@example.com'): User
{
    $testCase->actingAs($testCase->superAdmin)
        ->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
            'email' => $email,
            'password' => TEMPORARY_PASSWORD,
        ])
        ->assertCreated();

    return User::where('email', $email)->firstOrFail();
}

/**
 * Every production request starts with no resolved user and the default "web"
 * guard. The test application keeps both between requests (actingAs, and the
 * "sanctum" default that auth:sanctum sets), so reset them before a request
 * that must carry only its own credentials.
 */
function forgetAuthenticationOfEarlierRequests(): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

function loginThroughApi($testCase, string $email, string $password): TestResponse
{
    forgetAuthenticationOfEarlierRequests();

    return $testCase->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

/** Sends a request whose only credentials are this Bearer token. */
function requestWithToken($testCase, string $token, string $method, string $uri, array $data = []): TestResponse
{
    forgetAuthenticationOfEarlierRequests();

    return $testCase->withHeaders(['Authorization' => "Bearer {$token}"])->json($method, $uri, $data);
}

function changePasswordWithToken($testCase, string $token, string $currentPassword, string $newPassword): TestResponse
{
    return requestWithToken($testCase, $token, 'PUT', '/api/v1/auth/change-password', [
        'current_password' => $currentPassword,
        'new_password' => $newPassword,
        'new_password_confirmation' => $newPassword,
    ]);
}

// ── Switched on ──────────────────────────────────────────────────────────

test('Beri Akses switches on the required password change, shown by login and by the profile', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);

    $loginResponse = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', true);

    requestWithToken($this, $loginResponse->json('data.token'), 'GET', '/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.must_change_password', true)
        ->assertJsonPath('data.roles', ['ustadz']);
});

test('an admin password reset switches the required password change on again', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $firstToken = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');
    changePasswordWithToken($this, $firstToken, TEMPORARY_PASSWORD, CHOSEN_PASSWORD)->assertOk();

    $ustadzAccount = User::where('email', 'ustadz@example.com')->firstOrFail();
    forgetAuthenticationOfEarlierRequests();
    $this->actingAs($this->superAdmin)
        ->patchJson("/api/v1/users/{$ustadzAccount->id}/reset-password", ['new_password' => 'reset-oleh-admin'])
        ->assertOk();

    expect($ustadzAccount->fresh()->must_change_password)->toBeTrue();

    $resetToken = loginThroughApi($this, 'ustadz@example.com', 'reset-oleh-admin')
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', true)
        ->json('data.token');

    requestWithToken($this, $resetToken, 'GET', '/api/v1/academic-years')
        ->assertForbidden()
        ->assertExactJson(PASSWORD_CHANGE_REQUIRED_RESPONSE);
});

test('super_admin is not exempt: a reset super_admin must change his password too', function () {
    $otherSuperAdmin = User::factory()->create(['school_id' => $this->school->id, 'email' => 'admin.dua@example.com']);
    $otherSuperAdmin->assignRole('super_admin');

    $this->actingAs($this->superAdmin)
        ->patchJson("/api/v1/users/{$otherSuperAdmin->id}/reset-password", ['new_password' => 'reset-oleh-admin'])
        ->assertOk();

    $token = loginThroughApi($this, 'admin.dua@example.com', 'reset-oleh-admin')
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', true)
        ->json('data.token');

    requestWithToken($this, $token, 'GET', '/api/v1/users')
        ->assertForbidden()
        ->assertExactJson(PASSWORD_CHANGE_REQUIRED_RESPONSE);
});

// ── Refused and allowed endpoints ────────────────────────────────────────

test('while the change is required every other endpoint is refused with the special code', function (string $method, string $uri) {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    requestWithToken($this, $token, $method, $uri)
        ->assertForbidden()
        ->assertExactJson(PASSWORD_CHANGE_REQUIRED_RESPONSE);
})->with([
    'Tahun Ajaran (an Akun Ustadz may read it)' => ['GET', '/api/v1/academic-years'],
    'Jadwal Saya' => ['GET', '/api/v1/my-teaching-schedules'],
    'notifications' => ['GET', '/api/v1/notifications'],
    'dashboard stats' => ['GET', '/api/v1/dashboard/stats'],
    'a Setoran write' => ['POST', '/api/v1/memorization-logs'],
    'a grade write' => ['PUT', '/api/v1/student-grades/bulk'],
    'Data Ustadz (refused by permission anyway)' => ['GET', '/api/v1/teachers'],
]);

test('while the change is required the realtime channel authorization is refused too', function () {
    $ustadzAccount = grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    requestWithToken($this, $token, 'POST', '/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-App.Models.User.{$ustadzAccount->id}",
    ])
        ->assertForbidden()
        ->assertExactJson(PASSWORD_CHANGE_REQUIRED_RESPONSE);
});

test('while the change is required the own profile, the password change and logout stay open', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    requestWithToken($this, $token, 'GET', '/api/v1/auth/me')->assertOk();

    requestWithToken($this, $token, 'POST', '/api/v1/auth/logout')->assertOk();
    expect(User::where('email', 'ustadz@example.com')->firstOrFail()->tokens()->count())->toBe(0);
});

test('a request without a token is still unauthenticated, not refused for the password', function () {
    $this->getJson('/api/v1/academic-years')->assertUnauthorized();
});

test('public endpoints stay open to a token holder who must change his password', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    requestWithToken($this, $token, 'GET', '/api/v1/public/psb/active-periods')->assertOk();
});

// ── Switched off ─────────────────────────────────────────────────────────

test('changing the password switches the requirement off and opens every page again', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    changePasswordWithToken($this, $token, TEMPORARY_PASSWORD, CHOSEN_PASSWORD)
        ->assertOk()
        ->assertJsonPath('success', true);

    $ustadzAccount = User::where('email', 'ustadz@example.com')->firstOrFail();
    expect($ustadzAccount->must_change_password)->toBeFalse()
        ->and(Hash::check(CHOSEN_PASSWORD, $ustadzAccount->password))->toBeTrue();

    requestWithToken($this, $token, 'GET', '/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.must_change_password', false);
    requestWithToken($this, $token, 'GET', '/api/v1/academic-years')->assertOk();
    requestWithToken($this, $token, 'GET', '/api/v1/my-teaching-schedules')->assertOk();
    requestWithToken($this, $token, 'GET', '/api/v1/notifications')->assertOk();
    requestWithToken($this, $token, 'GET', '/api/v1/teachers')
        ->assertForbidden()
        ->assertJsonMissingPath('code');

    loginThroughApi($this, 'ustadz@example.com', CHOSEN_PASSWORD)
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', false);
});

test('changing the password signs out every other session, the one that changed it stays', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $otherSessionToken = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->assertOk()->json('data.token');
    $changingSessionToken = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->assertOk()->json('data.token');

    changePasswordWithToken($this, $changingSessionToken, TEMPORARY_PASSWORD, CHOSEN_PASSWORD)->assertOk();

    requestWithToken($this, $otherSessionToken, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    requestWithToken($this, $otherSessionToken, 'GET', '/api/v1/academic-years')->assertUnauthorized();
    requestWithToken($this, $changingSessionToken, 'GET', '/api/v1/academic-years')->assertOk();
    expect(User::where('email', 'ustadz@example.com')->firstOrFail()->tokens()->count())->toBe(1);
});

test('a wrong current password is refused and the requirement stays on', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    changePasswordWithToken($this, $token, 'bukan-password-ini', CHOSEN_PASSWORD)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password'])
        ->assertJsonPath('errors.current_password.0', 'Password saat ini salah.');

    expect(User::where('email', 'ustadz@example.com')->firstOrFail()->must_change_password)->toBeTrue();
    requestWithToken($this, $token, 'GET', '/api/v1/academic-years')
        ->assertForbidden()
        ->assertExactJson(PASSWORD_CHANGE_REQUIRED_RESPONSE);
});

test('keeping the temporary password is not a password change', function () {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    changePasswordWithToken($this, $token, TEMPORARY_PASSWORD, TEMPORARY_PASSWORD)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_password'])
        ->assertJsonPath('errors.new_password.0', 'Password baru harus berbeda dari password saat ini.');

    expect(User::where('email', 'ustadz@example.com')->firstOrFail()->must_change_password)->toBeTrue();
});

test('the new password follows the password rules', function (array $payload, string $invalidField, string $message) {
    grantAccessWithTemporaryPassword($this, $this->teacher);
    $token = loginThroughApi($this, 'ustadz@example.com', TEMPORARY_PASSWORD)->json('data.token');

    requestWithToken($this, $token, 'PUT', '/api/v1/auth/change-password', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$invalidField])
        ->assertJsonPath("errors.{$invalidField}.0", $message);
})->with([
    'too short' => [
        ['current_password' => TEMPORARY_PASSWORD, 'new_password' => 'lima5', 'new_password_confirmation' => 'lima5'],
        'new_password',
        'Password baru minimal 6 karakter.',
    ],
    'confirmation differs' => [
        ['current_password' => TEMPORARY_PASSWORD, 'new_password' => CHOSEN_PASSWORD, 'new_password_confirmation' => 'lain-lagi-789'],
        'new_password',
        'Konfirmasi password baru tidak cocok.',
    ],
    'current password missing' => [
        ['new_password' => CHOSEN_PASSWORD, 'new_password_confirmation' => CHOSEN_PASSWORD],
        'current_password',
        'Password saat ini wajib diisi.',
    ],
    'new password missing' => [
        ['current_password' => TEMPORARY_PASSWORD],
        'new_password',
        'Password baru wajib diisi.',
    ],
]);

// ── Accounts the requirement does not touch ─────────────────────────────

test('existing accounts and accounts created on Akun Pengguna are not required to change their password', function () {
    $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/users', [
            'name' => 'Pengurus Baru',
            'email' => 'pengurus.baru@example.com',
            'password' => 'password123',
            'role' => 'pengurus_pesantren',
        ])
        ->assertCreated();

    $newAccountToken = loginThroughApi($this, 'pengurus.baru@example.com', 'password123')
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', false)
        ->json('data.token');
    requestWithToken($this, $newAccountToken, 'GET', '/api/v1/academic-years')->assertOk();

    $this->superAdmin->update(['password' => Hash::make('password-admin')]);
    $superAdminToken = loginThroughApi($this, $this->superAdmin->email, 'password-admin')
        ->assertOk()
        ->assertJsonPath('data.user.must_change_password', false)
        ->json('data.token');
    requestWithToken($this, $superAdminToken, 'GET', '/api/v1/users')->assertOk();
    requestWithToken($this, $superAdminToken, 'GET', '/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.must_change_password', false);
});

test('the seeded default admin is not required to change his password', function () {
    $this->seed(RolePermissionSeeder::class);

    expect(User::where('email', 'akhabsy110@gmail.com')->firstOrFail()->must_change_password)->toBeFalse();
});
