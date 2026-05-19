<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\StoreCashBookEntryRequest;
use App\Http\Requests\Keuangan\UpdateCashBookEntryRequest;
use App\Models\CashBookEntry;
use App\Models\School;
use App\Services\Keuangan\CashBookEntryService;
use App\Services\Keuangan\CashBookProofStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashBookEntryController extends Controller
{
    public function __construct(
        private CashBookEntryService $entryService,
        private CashBookProofStorageService $proofStorage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['start_date', 'end_date', 'type', 'category_id', 'per_page']);
        $entries = $this->entryService->listEntries($filters);

        return $this->paginatedResponse($entries, 'Cash book entries retrieved');
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $request->only(['start_date', 'end_date', 'type', 'category_id']);
        $summary = $this->entryService->summaryEntries($filters);

        return $this->successResponse($summary, 'Cash book summary retrieved');
    }

    public function show(CashBookEntry $entry): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($entry);
        $entry->load(CashBookEntry::EAGER_LOAD_RELATIONS);

        return $this->successResponse($entry, 'Cash book entry retrieved');
    }

    public function store(StoreCashBookEntryRequest $request): JsonResponse
    {
        $entry = $this->entryService->createEntry(
            $request->validated(),
            $request->file('proof'),
            $request->user()->id
        );

        return $this->successResponse($entry, 'Cash book entry created', 201);
    }

    public function update(UpdateCashBookEntryRequest $request, CashBookEntry $entry): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($entry);

        $entry = $this->entryService->updateEntry(
            $entry,
            $request->validated(),
            $request->file('proof'),
            (bool) $request->boolean('remove_proof'),
            $request->user()->id
        );

        return $this->successResponse($entry, 'Cash book entry updated');
    }

    public function destroy(CashBookEntry $entry): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($entry);
        $this->entryService->deleteEntry($entry);

        return $this->successResponse(null, 'Cash book entry deleted');
    }

    public function streamProof(CashBookEntry $entry): StreamedResponse|JsonResponse
    {
        $this->ensureBelongsToActiveSchool($entry);

        if ($entry->proof_file_path === null || $entry->proof_file_mime === null) {
            return $this->errorResponse('No proof file attached to this entry.', code: 404);
        }

        return $this->proofStorage->streamResponse($entry->proof_file_path, $entry->proof_file_mime);
    }

    /**
     * Hide cross-tenant entries as 404 so attackers cannot probe for existence by id.
     */
    private function ensureBelongsToActiveSchool(CashBookEntry $entry): void
    {
        $school = School::activeOrFail();

        abort_unless($entry->school_id === $school->id, 404);
    }
}
