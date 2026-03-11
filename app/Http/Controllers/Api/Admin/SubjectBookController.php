<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\HasDependentsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubjectBookRequest;
use App\Http\Requests\Admin\UpdateSubjectBookRequest;
use App\Models\SubjectBook;
use App\Services\SubjectBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectBookController extends Controller
{
    public function __construct(
        private SubjectBookService $subjectBookService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['subject_category_id', 'is_active', 'search']);
        $perPage = $request->integer('per_page', 15);

        $books = $this->subjectBookService->listBooks($filters, $perPage);

        return $this->paginatedResponse($books, 'Subject books retrieved');
    }

    public function store(StoreSubjectBookRequest $request): JsonResponse
    {
        $book = $this->subjectBookService->createBook($request->validated());

        return $this->successResponse($book, 'Subject book created', 201);
    }

    public function show(SubjectBook $subjectBook): JsonResponse
    {
        $subjectBook->load('subjectCategory:id,name,color');

        return $this->successResponse($subjectBook, 'Subject book retrieved');
    }

    public function update(UpdateSubjectBookRequest $request, SubjectBook $subjectBook): JsonResponse
    {
        $updatedBook = $this->subjectBookService->updateBook($subjectBook, $request->validated());

        return $this->successResponse($updatedBook, 'Subject book updated');
    }

    public function destroy(SubjectBook $subjectBook): JsonResponse
    {
        try {
            $this->subjectBookService->deleteBook($subjectBook);
        } catch (HasDependentsException $e) {
            return $this->errorResponse($e->getMessage(), code: 422);
        }

        return $this->successResponse(null, 'Subject book deleted');
    }

    public function activeList(): JsonResponse
    {
        $books = $this->subjectBookService->listAllActive();

        return $this->successResponse($books, 'Active subject books retrieved');
    }
}
