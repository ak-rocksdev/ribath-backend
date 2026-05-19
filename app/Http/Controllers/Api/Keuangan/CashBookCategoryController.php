<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Services\Keuangan\CashBookCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBookCategoryController extends Controller
{
    public function __construct(
        private CashBookCategoryService $categoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['is_active']);
        $categories = $this->categoryService->listCategories($filters);

        return $this->successResponse($categories, 'Cash book categories retrieved');
    }
}
