<?php

use App\Models\CashBookActivityLog;
use App\Models\CashBookCategory;
use App\Models\CashBookEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'view-cashbook']);
    Permission::firstOrCreate(['name' => 'manage-cashbook']);
    Storage::fake('local');

    $this->school = School::factory()->create(['is_active' => true]);
    $this->category = CashBookCategory::factory()
        ->for($this->school, 'school')
        ->create();
});

function makeCashBookManager(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions(['view-cashbook', 'manage-cashbook']);
    $user->assignRole($role);

    return $user;
}

function makeCashBookViewer(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'viewer-only']);
    $role->syncPermissions(['view-cashbook']);
    $user->assignRole($role);

    return $user;
}

// ─── Create ──────────────────────────────────────────────────────────

test('unauthenticated user cannot create entry', function () {
    $this->postJson('/api/v1/cash-book-entries', [])->assertUnauthorized();
});

test('user without manage-cashbook cannot create entry', function () {
    $viewer = makeCashBookViewer();

    $this->actingAs($viewer)
        ->postJson('/api/v1/cash-book-entries', [
            'transaction_date' => '2026-05-19',
            'type' => 'in',
            'category_id' => $this->category->id,
            'description' => 'test',
            'amount' => 1000,
        ])
        ->assertForbidden();
});

test('manager can create entry without proof file', function () {
    $user = makeCashBookManager();

    $response = $this->actingAs($user)->postJson('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'Saldo awal kas',
        'counterparty' => 'Bendahara lama',
        'amount' => 5_000_000,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.amount', 5_000_000)
        ->assertJsonPath('data.type', 'in')
        ->assertJsonPath('data.proof_file_path', null);

    expect(CashBookEntry::count())->toBe(1);
    expect(CashBookActivityLog::where('action', 'created')->count())->toBe(1);
});

test('manager can create entry with PDF proof and file is stored', function () {
    $user = makeCashBookManager();
    $proof = UploadedFile::fake()->create('kuitansi.pdf', 100, 'application/pdf');

    $response = $this->actingAs($user)->post('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'out',
        'category_id' => $this->category->id,
        'description' => 'Bayar PLN',
        'amount' => 350_000,
        'proof' => $proof,
    ]);

    $response->assertCreated();

    $entry = CashBookEntry::first();
    expect($entry->proof_file_path)->not->toBeNull();
    expect($entry->proof_file_mime)->toBe('application/pdf');
    Storage::disk('local')->assertExists($entry->proof_file_path);
});

test('create rejects future transaction date', function () {
    $user = makeCashBookManager();

    $this->actingAs($user)->postJson('/api/v1/cash-book-entries', [
        'transaction_date' => '2099-01-01',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'future date test',
        'amount' => 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_date']);
});

test('create rejects zero or negative amount', function () {
    $user = makeCashBookManager();

    $this->actingAs($user)->postJson('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'zero amount test',
        'amount' => 0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('create rejects oversize file', function () {
    $user = makeCashBookManager();
    $proof = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'); // 6 MB

    $this->actingAs($user)->post('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'big file test',
        'amount' => 1000,
        'proof' => $proof,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['proof']);
});

test('create rejects disallowed mime type', function () {
    $user = makeCashBookManager();
    $proof = UploadedFile::fake()->create('script.exe', 50, 'application/x-msdownload');

    $this->actingAs($user)->post('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $this->category->id,
        'description' => 'bad mime test',
        'amount' => 1000,
        'proof' => $proof,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['proof']);
});

test('create rejects inactive category', function () {
    $user = makeCashBookManager();
    $inactive = CashBookCategory::factory()->for($this->school, 'school')->inactive()->create();

    $this->actingAs($user)->postJson('/api/v1/cash-book-entries', [
        'transaction_date' => '2026-05-19',
        'type' => 'in',
        'category_id' => $inactive->id,
        'description' => 'inactive category test',
        'amount' => 1000,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id']);
});

// ─── Update ──────────────────────────────────────────────────────────

test('update changes amount and writes audit log with diff', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['amount' => 350_000, 'created_by' => $user->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/cash-book-entries/{$entry->id}", ['amount' => 425_000])
        ->assertOk()
        ->assertJsonPath('data.amount', 425_000);

    $log = CashBookActivityLog::where('action', 'updated')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->changes['before']['amount'])->toBe(350_000);
    expect($log->changes['after']['amount'])->toBe(425_000);
});

test('update with new proof deletes old file', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    // First upload — establish baseline proof
    $oldProof = UploadedFile::fake()->create('old.pdf', 50, 'application/pdf');
    $this->actingAs($user)->post("/api/v1/cash-book-entries/{$entry->id}", [
        '_method' => 'PATCH',
        'proof' => $oldProof,
    ])->assertOk();

    $entry->refresh();
    $oldPath = $entry->proof_file_path;
    Storage::disk('local')->assertExists($oldPath);

    // Replace proof
    $newProof = UploadedFile::fake()->image('new.png');
    $this->actingAs($user)->post("/api/v1/cash-book-entries/{$entry->id}", [
        '_method' => 'PATCH',
        'proof' => $newProof,
    ])->assertOk();

    $entry->refresh();
    expect($entry->proof_file_path)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($entry->proof_file_path);
});

test('remove_proof clears file', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $proof = UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf');
    $this->actingAs($user)->post("/api/v1/cash-book-entries/{$entry->id}", [
        '_method' => 'PATCH',
        'proof' => $proof,
    ])->assertOk();

    $entry->refresh();
    $oldPath = $entry->proof_file_path;
    Storage::disk('local')->assertExists($oldPath);

    $this->actingAs($user)->patchJson("/api/v1/cash-book-entries/{$entry->id}", [
        'remove_proof' => true,
    ])->assertOk()
        ->assertJsonPath('data.proof_file_path', null);

    Storage::disk('local')->assertMissing($oldPath);
});

// ─── Delete ──────────────────────────────────────────────────────────

test('delete performs soft delete and writes audit log', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/cash-book-entries/{$entry->id}")
        ->assertOk();

    expect($entry->fresh()->deleted_at)->not->toBeNull();
    expect(CashBookActivityLog::where('action', 'deleted')->count())->toBe(1);
});

// ─── List & Summary ─────────────────────────────────────────────────

test('list returns paginated entries scoped to active school', function () {
    $user = makeCashBookManager();
    CashBookEntry::factory()
        ->count(3)
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/cash-book-entries');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3);
});

test('summary returns correct saldo_total_now', function () {
    $user = makeCashBookManager();
    CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->in()
        ->create(['amount' => 5_000_000, 'created_by' => $user->id]);
    CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->out()
        ->create(['amount' => 350_000, 'created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-entries/summary')
        ->assertOk()
        ->assertJsonPath('data.saldo_total_now', 4_650_000);
});

// ─── Show & Proof Stream ────────────────────────────────────────────

test('show returns single entry with eager-loaded category', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/cash-book-entries/{$entry->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $entry->id)
        ->assertJsonPath('data.category.id', $this->category->id);
});

test('proof stream returns file with correct mime', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $proof = UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf');
    $this->actingAs($user)->post("/api/v1/cash-book-entries/{$entry->id}", [
        '_method' => 'PATCH',
        'proof' => $proof,
    ])->assertOk();

    $this->actingAs($user)
        ->get("/api/v1/cash-book-entries/{$entry->id}/proof")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('proof stream returns 404 when entry has no proof', function () {
    $user = makeCashBookManager();
    $entry = CashBookEntry::factory()
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/cash-book-entries/{$entry->id}/proof")
        ->assertNotFound();
});

// ─── Multi-Tenant ───────────────────────────────────────────────────

test('cross-school entry returns 404 not 403', function () {
    $user = makeCashBookManager();
    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherCategory = CashBookCategory::factory()->for($otherSchool, 'school')->create();
    $otherEntry = CashBookEntry::factory()
        ->for($otherSchool, 'school')
        ->for($otherCategory, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/cash-book-entries/{$otherEntry->id}")
        ->assertNotFound();
});

test('list excludes entries from other schools', function () {
    $user = makeCashBookManager();
    CashBookEntry::factory()
        ->count(2)
        ->for($this->school, 'school')
        ->for($this->category, 'category')
        ->create(['created_by' => $user->id]);

    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherCategory = CashBookCategory::factory()->for($otherSchool, 'school')->create();
    CashBookEntry::factory()
        ->count(5)
        ->for($otherSchool, 'school')
        ->for($otherCategory, 'category')
        ->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/cash-book-entries')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});
