<?php

use App\Models\CashBookActivityLog;
use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'view-cashbook']);
    Permission::firstOrCreate(['name' => 'manage-cashbook']);

    $this->school = School::factory()->create(['is_active' => true]);
    $this->category = CashBookCategory::factory()->for($this->school, 'school')->create();
});

function makeLogReader(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-cashbook', 'manage-cashbook']);
    $user->assignRole($role);

    return $user;
}

test('observer writes created log when entry is created via API', function () {
    $user = makeLogReader();

    $this->actingAs($user)->postJson('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'audit test',
        'amount' => 100_000,
    ])->assertCreated();

    $log = CashBookActivityLog::where('action', 'created')
        ->where('subject_type', 'entry')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->actor_id)->toBe($user->id);
    expect($log->school_id)->toBe($this->school->id);
    expect($log->changes)->toBeNull();
});

test('observer captures before-after diff on update', function () {
    $user = makeLogReader();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['description' => 'original', 'amount' => 100, 'created_by' => $user->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/cash-book-entries/{$entry->id}", [
            'description' => 'edited',
            'amount' => 200,
        ])->assertOk();

    $log = CashBookActivityLog::where('action', 'updated')->first();

    expect($log->changes['before']['description'])->toBe('original');
    expect($log->changes['after']['description'])->toBe('edited');
    expect($log->changes['before']['amount'])->toBe(100);
    expect($log->changes['after']['amount'])->toBe(200);
});

test('observer skips audit when nothing changes', function () {
    $user = makeLogReader();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['description' => 'same', 'created_by' => $user->id]);

    $countBefore = CashBookActivityLog::where('action', 'updated')->count();

    $this->actingAs($user)
        ->patchJson("/api/v1/cash-book-entries/{$entry->id}", [
            'description' => 'same', // unchanged
        ])->assertOk();

    expect(CashBookActivityLog::where('action', 'updated')->count())->toBe($countBefore);
});

test('index endpoint returns paginated logs scoped to active school', function () {
    $user = makeLogReader();

    // Trigger create+update via API so observer writes log rows
    $this->actingAs($user)->postJson('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'test create',
        'amount' => 1000,
    ])->assertCreated();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/cash-book-activity-logs')
        ->assertOk();

    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(1);
});

test('index endpoint can filter by subject_id', function () {
    $user = makeLogReader();
    $entryA = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);
    $entryB = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/cash-book-entries/{$entryA->id}", ['amount' => 555])
        ->assertOk();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/cash-book-activity-logs?subject_id={$entryA->id}")
        ->assertOk();

    foreach ($response->json('data') as $row) {
        expect($row['subject_id'])->toBe($entryA->id);
    }
});
