<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage-school-profile']);
    Storage::fake('public');

    $this->school = School::factory()->create([
        'is_active' => true,
        'name'      => 'Pesantren Asal',
        'address'   => 'Alamat Asal',
        'phone'     => '021-0001',
        'email'     => 'asal@pesantren.test',
    ]);
});

function makeSchoolProfileEditor(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['manage-school-profile']);
    $user->assignRole($role);

    return $user;
}

// ─── Show ────────────────────────────────────────────────────────────

test('unauthenticated cannot show a school', function () {
    $this->getJson("/api/v1/schools/{$this->school->id}")->assertUnauthorized();
});

test('user without manage-school-profile cannot show', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->getJson("/api/v1/schools/{$this->school->id}")
        ->assertForbidden();
});

test('authorised user can show their school', function () {
    $user = makeSchoolProfileEditor();
    $response = $this->actingAs($user)->getJson("/api/v1/schools/{$this->school->id}");

    $response->assertOk()
        ->assertJsonPath('data.name', 'Pesantren Asal')
        ->assertJsonPath('data.address', 'Alamat Asal')
        ->assertJsonPath('data.phone', '021-0001')
        ->assertJsonPath('data.email', 'asal@pesantren.test');
});

// ─── Update ──────────────────────────────────────────────────────────

test('update validates name as required', function () {
    $user = makeSchoolProfileEditor();
    $this->actingAs($user)
        ->putJson("/api/v1/schools/{$this->school->id}", ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('update accepts name plus nullable optional fields', function () {
    $user = makeSchoolProfileEditor();
    $response = $this->actingAs($user)->putJson("/api/v1/schools/{$this->school->id}", [
        'name'    => 'Pesantren Baru',
        'address' => 'Alamat Baru',
        'phone'   => '021-9999',
        'email'   => 'baru@pesantren.test',
    ]);

    $response->assertOk();
    expect($this->school->fresh()->name)->toBe('Pesantren Baru');
    expect($this->school->fresh()->address)->toBe('Alamat Baru');
});

// ─── Upload logo ─────────────────────────────────────────────────────

test('upload rejects non-image files', function () {
    $user = makeSchoolProfileEditor();
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'plain text');

    $this->actingAs($user)
        ->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);
});

test('upload rejects oversize images', function () {
    $user = makeSchoolProfileEditor();
    // 3 MB > 2 MB cap
    $file = UploadedFile::fake()->image('big.png', 1024, 1024)->size(3 * 1024);

    $this->actingAs($user)
        ->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);
});

test('upload rejects images smaller than 256x256', function () {
    $user = makeSchoolProfileEditor();
    $file = UploadedFile::fake()->image('tiny.png', 100, 100);

    $this->actingAs($user)
        ->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);
});

test('upload happy path persists logo and exposes logo_url', function () {
    $user = makeSchoolProfileEditor();
    $file = UploadedFile::fake()->image('logo.png', 512, 512);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $file]);

    $response->assertOk();

    $school = $this->school->fresh();
    expect($school->logo_path)->not->toBeNull();
    expect($school->logo_path)->toStartWith('school-logos/');
    Storage::disk('public')->assertExists($school->logo_path);

    $response->assertJsonPath('data.logo_url', $school->logo_url);
});

test('uploading a second logo deletes the previous file', function () {
    $user = makeSchoolProfileEditor();

    // First upload
    $first = UploadedFile::fake()->image('one.png', 512, 512);
    $this->actingAs($user)->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $first])
        ->assertOk();
    $firstPath = $this->school->fresh()->logo_path;

    // Second upload
    $second = UploadedFile::fake()->image('two.png', 512, 512);
    $this->actingAs($user)->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $second])
        ->assertOk();
    $secondPath = $this->school->fresh()->logo_path;

    expect($firstPath)->not->toBe($secondPath);
    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($secondPath);
});

// ─── Delete logo ─────────────────────────────────────────────────────

test('delete removes file and clears logo_path', function () {
    $user = makeSchoolProfileEditor();

    $file = UploadedFile::fake()->image('logo.png', 512, 512);
    $this->actingAs($user)->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $file])
        ->assertOk();
    $path = $this->school->fresh()->logo_path;

    $this->actingAs($user)
        ->deleteJson("/api/v1/schools/{$this->school->id}/logo")
        ->assertOk();

    expect($this->school->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('delete is no-op when no logo is set', function () {
    $user = makeSchoolProfileEditor();

    $this->actingAs($user)
        ->deleteJson("/api/v1/schools/{$this->school->id}/logo")
        ->assertOk();

    expect($this->school->fresh()->logo_path)->toBeNull();
});

// ─── Permission gating on writes ─────────────────────────────────────

test('user without permission cannot update', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->putJson("/api/v1/schools/{$this->school->id}", ['name' => 'Pesantren Hack'])
        ->assertForbidden();
});

test('user without permission cannot upload logo', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('logo.png', 512, 512);
    $this->actingAs($user)
        ->postJson("/api/v1/schools/{$this->school->id}/logo", ['logo' => $file])
        ->assertForbidden();
});

test('user without permission cannot delete logo', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->deleteJson("/api/v1/schools/{$this->school->id}/logo")
        ->assertForbidden();
});
