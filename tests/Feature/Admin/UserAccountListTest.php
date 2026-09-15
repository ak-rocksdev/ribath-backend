<?php

use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
 * Ticket role-ustadz/11 — Halaman Akun Pengguna.
 *
 * GET /users lists the login accounts of the active school, filtered on the
 * server by role (any of the account's roles), search and status, and
 * GET /users/summary counts them for the statistics cards.
 *
 * The accounts are the three kinds of users of the role-ustadz tests: a
 * pengurus, an Akun Ustadz made through "Beri Akses", and a multi-role
 * pengurus + ustadz account (Beri Akses, then roles assigned through
 * POST /users/{user}/roles). The roles are seeded before the school exists,
 * so the seeder's default admin has no school and never appears here.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->school = School::factory()->create();
    $this->otherSchool = School::factory()->inactive()->create();

    $this->superAdmin = User::factory()->create(['school_id' => $this->school->id, 'email' => 'super-admin@example.com']);
    $this->superAdmin->assignRole('super_admin');

    $this->pengurus = User::factory()->create(['school_id' => $this->school->id, 'email' => 'pengurus@example.com']);
    $this->pengurus->assignRole('pengurus_pesantren');

    $this->ustadzAccount = grantAkunUstadz($this, 'ustadz@example.com');

    $this->pengurusUstadzAccount = grantAkunUstadz($this, 'pengurus-ustadz@example.com');
    $this->actingAs($this->superAdmin)
        ->postJson("/api/v1/users/{$this->pengurusUstadzAccount->id}/roles", [
            'roles' => ['pengurus_pesantren', 'ustadz'],
        ])
        ->assertOk();
});

/** Creates an Akun Ustadz for a new teacher of the active school through "Beri Akses" and makes its first-login password change. */
function grantAkunUstadz(TestCase $testCase, string $email): User
{
    $teacher = Teacher::factory()->create(['school_id' => $testCase->school->id, 'user_id' => null]);

    $testCase->actingAs($testCase->superAdmin)
        ->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
            'email' => $email,
            'password' => 'password123',
        ])
        ->assertCreated();

    return completeFirstLoginPasswordChange($testCase, User::where('email', $email)->firstOrFail(), 'password123');
}

function listedEmails(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('email')->sort()->values()->all();
}

// --- Tenancy ---

test('the account list shows only the accounts of the active school', function () {
    $otherSchoolUstadz = User::factory()->create(['school_id' => $this->otherSchool->id, 'email' => 'other-school@example.com']);
    $otherSchoolUstadz->assignRole('ustadz');
    User::factory()->create(['school_id' => null, 'email' => 'no-school@example.com']);

    $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/users')->assertOk();

    expect($response->json('meta.total'))->toBe(4)
        ->and(listedEmails($response))->toBe([
            'pengurus-ustadz@example.com',
            'pengurus@example.com',
            'super-admin@example.com',
            'ustadz@example.com',
        ]);
});

test('filters never reach accounts of another school', function () {
    $otherSchoolUstadz = User::factory()->create([
        'school_id' => $this->otherSchool->id,
        'name' => 'Ustadz Sekolah Lain',
        'email' => 'other-school@example.com',
        'is_active' => false,
    ]);
    $otherSchoolUstadz->assignRole('ustadz');

    $this->actingAs($this->superAdmin)->getJson('/api/v1/users?role=ustadz')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
    $this->actingAs($this->superAdmin)->getJson('/api/v1/users?search=Sekolah Lain')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
    $this->actingAs($this->superAdmin)->getJson('/api/v1/users?is_active=false')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

// --- Access ---

test('a pengurus with view-users sees the account list and summary', function () {
    $this->actingAs($this->pengurus)->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonPath('meta.total', 4);
    $this->actingAs($this->pengurus)->getJson('/api/v1/users/summary')
        ->assertOk()
        ->assertJsonPath('data.total', 4);
});

test('an Akun Ustadz cannot read the account list or summary', function () {
    $this->actingAs($this->ustadzAccount)->getJson('/api/v1/users')->assertForbidden();
    $this->actingAs($this->ustadzAccount)->getJson('/api/v1/users/summary')->assertForbidden();
});

test('the account summary requires authentication', function () {
    // beforeEach signed in as super_admin to call the real endpoints; sign out.
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/users/summary')->assertUnauthorized();
});

// --- Role filter ---

test('the role filter lists a multi-role account under each of its roles', function () {
    $ustadzResponse = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?role=ustadz')->assertOk();
    $pengurusResponse = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?role=pengurus_pesantren')->assertOk();

    expect(listedEmails($ustadzResponse))->toBe(['pengurus-ustadz@example.com', 'ustadz@example.com'])
        ->and(listedEmails($pengurusResponse))->toBe(['pengurus-ustadz@example.com', 'pengurus@example.com']);

    $multiRoleAccount = collect($ustadzResponse->json('data'))->firstWhere('email', 'pengurus-ustadz@example.com');
    expect(collect($multiRoleAccount['roles'])->pluck('name')->sort()->values()->all())
        ->toBe(['pengurus_pesantren', 'ustadz']);
});

test('the role, search and status filters combine', function () {
    $this->actingAs($this->superAdmin)
        ->patchJson("/api/v1/users/{$this->ustadzAccount->id}/toggle-status")
        ->assertOk();

    $inactiveUstadzResponse = $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/users?role=ustadz&is_active=false')
        ->assertOk();
    $activeUstadzResponse = $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/users?role=ustadz&is_active=true&search=pengurus-ustadz')
        ->assertOk();

    expect(listedEmails($inactiveUstadzResponse))->toBe(['ustadz@example.com'])
        ->and(listedEmails($activeUstadzResponse))->toBe(['pengurus-ustadz@example.com']);
});

test('an empty status filter lists active and inactive accounts', function () {
    $this->actingAs($this->superAdmin)
        ->patchJson("/api/v1/users/{$this->ustadzAccount->id}/toggle-status")
        ->assertOk();

    $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?is_active=')->assertOk();

    expect($response->json('meta.total'))->toBe(4)
        ->and(collect($response->json('data'))->pluck('is_active')->unique()->sort()->values()->all())->toBe([false, true]);
});

test('the list paginates with the requested page size', function () {
    $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?per_page=3&page=2')->assertOk();

    expect($response->json('meta'))->toMatchArray(['current_page' => 2, 'last_page' => 2, 'per_page' => 3, 'total' => 4])
        ->and($response->json('data'))->toHaveCount(1);
});

test('accounts created at the same moment keep one order across pages, newest id first', function () {
    User::query()->update(['created_at' => '2026-09-01 08:00:00']);

    $firstPage = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?per_page=2&page=1')->assertOk();
    $secondPage = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?per_page=2&page=2')->assertOk();

    $listedIds = collect($firstPage->json('data'))->concat($secondPage->json('data'))->pluck('id')->all();
    $expectedIds = User::where('school_id', $this->school->id)->orderByDesc('id')->pluck('id')->all();

    expect($listedIds)->toBe($expectedIds);
});

// --- Validation ---

test('the list validates its filters', function (string $query, string $invalidField) {
    $this->actingAs($this->superAdmin)
        ->getJson("/api/v1/users?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$invalidField]);
})->with([
    'per_page above 100' => ['per_page=101', 'per_page'],
    'per_page below 1' => ['per_page=0', 'per_page'],
    'per_page not a number' => ['per_page=semua', 'per_page'],
    'page below 1' => ['page=0', 'page'],
    'role that does not exist' => ['role=kepala_sekolah', 'role'],
    'is_active not a boolean' => ['is_active=mungkin', 'is_active'],
    'search longer than 100 characters' => ['search='.str_repeat('a', 101), 'search'],
]);

test('the list accepts the largest page size', function () {
    $this->actingAs($this->superAdmin)
        ->getJson('/api/v1/users?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

// --- Summary ---

test('the summary counts the accounts of the active school per status and role', function () {
    $otherSchoolUstadz = User::factory()->create(['school_id' => $this->otherSchool->id]);
    $otherSchoolUstadz->assignRole('ustadz');
    $this->actingAs($this->superAdmin)
        ->patchJson("/api/v1/users/{$this->ustadzAccount->id}/toggle-status")
        ->assertOk();

    $this->actingAs($this->superAdmin)->getJson('/api/v1/users/summary')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertExactJson([
            'success' => true,
            'message' => 'User summary retrieved',
            'data' => [
                'total' => 4,
                'active' => 3,
                'inactive' => 1,
                'administrators' => 3,
                'by_role' => [
                    'super_admin' => 1,
                    'pengurus_pesantren' => 2,
                    'ustadz' => 2,
                ],
            ],
        ]);
});

test('the summary counts an administrator account once however many admin roles it holds', function () {
    // super_admin + pengurus_pesantren on one account, and a second pengurus_* role.
    $this->actingAs($this->superAdmin)
        ->postJson("/api/v1/users/{$this->superAdmin->id}/roles", ['roles' => ['super_admin', 'pengurus_pesantren']])
        ->assertOk();
    Role::create(['name' => 'pengurus_keuangan', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->superAdmin)
        ->postJson("/api/v1/users/{$this->pengurus->id}/roles", ['roles' => ['pengurus_pesantren', 'pengurus_keuangan']])
        ->assertOk();
    // A name that only looks like a pengurus role when "_" is read as a LIKE wildcard.
    $lookalikeAccount = User::factory()->create(['school_id' => $this->school->id]);
    $lookalikeAccount->assignRole(Role::create(['name' => 'pengurusXkeuangan', 'guard_name' => 'web']));

    $this->actingAs($this->superAdmin)->getJson('/api/v1/users/summary')
        ->assertOk()
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.by_role.super_admin', 1)
        ->assertJsonPath('data.by_role.pengurus_pesantren', 3)
        ->assertJsonPath('data.by_role.pengurus_keuangan', 1)
        ->assertJsonPath('data.administrators', 3);
});

test('the summary leaves out deleted accounts', function () {
    $this->actingAs($this->superAdmin)
        ->deleteJson("/api/v1/users/{$this->ustadzAccount->id}")
        ->assertOk();

    $this->actingAs($this->superAdmin)->getJson('/api/v1/users/summary')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_role.ustadz', 1);
});

// --- Tambah Akun ---

test('an account created through Tambah Akun joins the active school and shows in the list and summary', function () {
    $this->actingAs($this->superAdmin)
        ->postJson('/api/v1/users', [
            'name' => 'Akun Baru',
            'email' => 'akun-baru@example.com',
            'password' => 'password123',
            'role' => 'pengurus_pesantren',
            'school_id' => $this->otherSchool->id,
        ])
        ->assertCreated();

    expect(User::where('email', 'akun-baru@example.com')->value('school_id'))->toBe($this->school->id);

    $listResponse = $this->actingAs($this->superAdmin)->getJson('/api/v1/users?role=pengurus_pesantren')->assertOk();
    expect(listedEmails($listResponse))->toContain('akun-baru@example.com');

    $this->actingAs($this->superAdmin)->getJson('/api/v1/users/summary')
        ->assertOk()
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.by_role.pengurus_pesantren', 3);
});
