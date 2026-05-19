<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\StoreCashBookCategoryRequest;
use App\Http\Requests\Keuangan\UpdateCashBookCategoryRequest;
use App\Models\CashBookCategory;
use App\Services\Keuangan\CashBookCategoryService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBookCategoryController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private CashBookCategoryService $categoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['is_active', 'with_entries_count']);
        $categories = $this->categoryService->listCategories($filters);

        return $this->successResponse($categories, 'Cash book categories retrieved');
    }

    public function store(StoreCashBookCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());

        return $this->successResponse(
            $category->loadCount('entries'),
            'Cash book category created',
            201
        );
    }

    public function update(UpdateCashBookCategoryRequest $request, CashBookCategory $category): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($category);

        // System category (mis. "Saldo Awal") tidak boleh di-rename atau di-nonaktifkan
        if ($category->is_system) {
            $hasNameChange = $request->has('name');
            $deactivating = $request->has('is_active') && $request->boolean('is_active') === false;

            if ($hasNameChange || $deactivating) {
                return $this->errorResponse(
                    'Kategori sistem tidak dapat diubah.',
                    code: 403
                );
            }
        }

        $updated = $this->categoryService->updateCategory($category, $request->validated());

        return $this->successResponse($updated->loadCount('entries'), 'Cash book category updated');
    }

    public function destroy(CashBookCategory $category): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($category);

        if ($category->is_system) {
            return $this->errorResponse(
                'Kategori sistem tidak dapat dihapus.',
                code: 403
            );
        }

        $entriesCount = $category->entries()->count();
        if ($entriesCount > 0) {
            return $this->errorResponse(
                "Kategori masih dipakai {$entriesCount} transaksi. Set nonaktif saja agar tidak muncul di dropdown.",
                errors: ['entries_count' => $entriesCount],
                code: 409
            );
        }

        $this->categoryService->deleteCategory($category);

        return $this->successResponse(null, 'Cash book category deleted');
    }

}
