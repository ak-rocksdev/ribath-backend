<?php

use App\Http\Controllers\Api\Admin\AcademicYearController;
use App\Http\Controllers\Api\Admin\ClassLevelController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SchoolController;
use App\Http\Controllers\Api\Admin\StudentController;
use App\Http\Controllers\Api\Admin\TeacherController;
use App\Http\Controllers\Api\Admin\SubjectBookController;
use App\Http\Controllers\Api\Admin\SubjectCategoryController;
use App\Http\Controllers\Api\Admin\TeachingScheduleController;
use App\Http\Controllers\Api\Admin\TeachingScheduleExportController;
use App\Http\Controllers\Api\Admin\TimeSlotController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Keuangan\CashBookActivityLogController;
use App\Http\Controllers\Api\Keuangan\CashBookCategoryController;
use App\Http\Controllers\Api\Keuangan\CashBookEntryController;
use App\Http\Controllers\Api\Keuangan\BillController;
use App\Http\Controllers\Api\Keuangan\FeeScheduleController;
use App\Http\Controllers\Api\Keuangan\FeeUnassignedStudentsController;
use App\Http\Controllers\Api\Keuangan\StudentFeeAssignmentController;
use App\Http\Controllers\Api\Keuangan\StudentPaymentController;
use App\Http\Controllers\Api\Keuangan\FeeTypeController;
use App\Http\Controllers\Api\PSB\RegistrationController;
use App\Http\Controllers\Api\PSB\RegistrationPeriodController;
use App\Http\Controllers\Api\Public\PublicPsbController;
use App\Http\Controllers\Api\Public\StudentCompletionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::put('change-password', [AuthController::class, 'changePassword']);
        });
    });

    // PSB Registration Management routes
    Route::prefix('psb/registrations')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [RegistrationController::class, 'index'])->middleware('permission:view-registrations');
        Route::get('/stats', [RegistrationController::class, 'stats'])->middleware('permission:view-registrations');
        Route::get('/{registration}', [RegistrationController::class, 'show'])->middleware('permission:view-registrations');
        Route::patch('/{registration}/status', [RegistrationController::class, 'updateStatus'])->middleware('permission:manage-registrations');
        Route::post('/{registration}/accept', [RegistrationController::class, 'accept'])->middleware('permission:manage-registrations');
        Route::post('/{registration}/reject', [RegistrationController::class, 'reject'])->middleware('permission:manage-registrations');
        Route::patch('/{registration}/archive', [RegistrationController::class, 'archive'])->middleware('permission:manage-registrations');
        Route::patch('/{registration}/unarchive', [RegistrationController::class, 'unarchive'])->middleware('permission:manage-registrations');
        Route::delete('/{registration}', [RegistrationController::class, 'destroy'])->middleware('permission:manage-registrations');
    });

    // User Management routes
    Route::prefix('users')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('permission:view-users');
        Route::post('/', [UserController::class, 'store'])->middleware('permission:create-users');
        Route::post('/check-email', [UserController::class, 'checkEmail'])->middleware('permission:view-users');
        Route::get('/{user}', [UserController::class, 'show'])->middleware('permission:view-users');
        Route::get('/{user}/relationships', [UserController::class, 'relationships'])->middleware('permission:view-users');
        Route::put('/{user}', [UserController::class, 'update'])->middleware('permission:edit-users');
        Route::delete('/{user}', [UserController::class, 'destroy'])->middleware('permission:delete-users');
        Route::patch('/{user}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('permission:edit-users');
        Route::patch('/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:edit-users');
        Route::post('/{user}/roles', [RoleController::class, 'assignRoles'])->middleware('permission:manage-roles');
        Route::delete('/{user}/roles/{role}', [RoleController::class, 'removeRole'])->middleware('permission:manage-roles');
    });

    // Role & Permission Management routes
    Route::prefix('roles')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('permission:manage-roles');
        Route::put('/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:manage-roles');
    });

    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware(['auth:sanctum', 'permission:manage-roles']);

    // Student Management routes
    Route::prefix('students')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [StudentController::class, 'index'])->middleware('permission:view-students');
        Route::post('/', [StudentController::class, 'store'])->middleware('permission:create-students');
        Route::get('/{student}', [StudentController::class, 'show'])->middleware('permission:view-students');
        Route::put('/{student}', [StudentController::class, 'update'])->middleware('permission:edit-students');
        Route::delete('/{student}', [StudentController::class, 'destroy'])->middleware('permission:delete-students');
        Route::patch('/{student}/status', [StudentController::class, 'updateStatus'])->middleware('permission:edit-students');
        Route::post('/{student}/documents', [StudentController::class, 'uploadDocument'])->middleware('permission:edit-students');
        Route::delete('/{student}/documents/{documentType}', [StudentController::class, 'deleteDocument'])->middleware('permission:edit-students');
    });

    // Schools routes
    Route::prefix('schools')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [SchoolController::class, 'index']);
        Route::get('/{school}', [SchoolController::class, 'show'])
            ->middleware('permission:manage-school-profile');
        Route::put('/{school}', [SchoolController::class, 'update'])
            ->middleware('permission:manage-school-profile');
        Route::post('/{school}/logo', [SchoolController::class, 'uploadLogo'])
            ->middleware('permission:manage-school-profile');
        Route::delete('/{school}/logo', [SchoolController::class, 'deleteLogo'])
            ->middleware('permission:manage-school-profile');
    });

    // Class Levels routes
    Route::get('/class-levels', [ClassLevelController::class, 'index'])->middleware('auth:sanctum');
    Route::prefix('class-levels')->middleware(['auth:sanctum', 'permission:manage-class-levels'])->group(function () {
        Route::get('/admin', [ClassLevelController::class, 'adminIndex']);
        Route::post('/', [ClassLevelController::class, 'store']);
        Route::put('/{classLevel}', [ClassLevelController::class, 'update']);
        Route::delete('/{classLevel}', [ClassLevelController::class, 'destroy']);
        Route::patch('/{classLevel}/status', [ClassLevelController::class, 'updateStatus']);
        Route::patch('/reorder', [ClassLevelController::class, 'reorder']);
    });

    // Academic Years routes
    Route::prefix('academic-years')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/active', [AcademicYearController::class, 'active'])
            ->middleware('permission:view-academic-years');
        Route::get('/', [AcademicYearController::class, 'index'])
            ->middleware('permission:view-academic-years');
        Route::post('/', [AcademicYearController::class, 'store'])
            ->middleware('permission:manage-academic-years');
        Route::get('/{academicYear}', [AcademicYearController::class, 'show'])
            ->middleware('permission:view-academic-years');
        Route::put('/{academicYear}', [AcademicYearController::class, 'update'])
            ->middleware('permission:manage-academic-years');
        Route::delete('/{academicYear}', [AcademicYearController::class, 'destroy'])
            ->middleware('permission:manage-academic-years');
        Route::patch('/{academicYear}/activate', [AcademicYearController::class, 'activate'])
            ->middleware('permission:manage-academic-years');
        Route::patch('/{academicYear}/semester', [AcademicYearController::class, 'switchSemester'])
            ->middleware('permission:manage-academic-years');
    });

    // Time Slots routes
    Route::prefix('time-slots')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [TimeSlotController::class, 'index'])
            ->middleware('permission:view-time-slots');
        Route::post('/', [TimeSlotController::class, 'store'])
            ->middleware('permission:manage-time-slots');
        Route::patch('/reorder', [TimeSlotController::class, 'reorder'])
            ->middleware('permission:manage-time-slots');
        Route::get('/{timeSlot}', [TimeSlotController::class, 'show'])
            ->middleware('permission:view-time-slots');
        Route::put('/{timeSlot}', [TimeSlotController::class, 'update'])
            ->middleware('permission:manage-time-slots');
        Route::delete('/{timeSlot}', [TimeSlotController::class, 'destroy'])
            ->middleware('permission:manage-time-slots');
    });

    // Subject Categories routes
    Route::prefix('subject-categories')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [SubjectCategoryController::class, 'index'])
            ->middleware('permission:view-subject-categories');
        Route::post('/', [SubjectCategoryController::class, 'store'])
            ->middleware('permission:manage-subject-categories');
        Route::get('/{subjectCategory}', [SubjectCategoryController::class, 'show'])
            ->middleware('permission:view-subject-categories');
        Route::put('/{subjectCategory}', [SubjectCategoryController::class, 'update'])
            ->middleware('permission:manage-subject-categories');
        Route::delete('/{subjectCategory}', [SubjectCategoryController::class, 'destroy'])
            ->middleware('permission:manage-subject-categories');
    });

    // Subject Books routes
    Route::prefix('subject-books')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/active', [SubjectBookController::class, 'activeList'])
            ->middleware('permission:view-subject-books');
        Route::get('/', [SubjectBookController::class, 'index'])
            ->middleware('permission:view-subject-books');
        Route::post('/', [SubjectBookController::class, 'store'])
            ->middleware('permission:manage-subject-books');
        Route::get('/{subjectBook}', [SubjectBookController::class, 'show'])
            ->middleware('permission:view-subject-books');
        Route::put('/{subjectBook}', [SubjectBookController::class, 'update'])
            ->middleware('permission:manage-subject-books');
        Route::delete('/{subjectBook}', [SubjectBookController::class, 'destroy'])
            ->middleware('permission:manage-subject-books');
    });

    // Teaching Schedules routes
    Route::prefix('teaching-schedules')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/export/teacher/{teacher}', [TeachingScheduleExportController::class, 'teacher'])
            ->middleware('permission:view-schedules');
        Route::get('/', [TeachingScheduleController::class, 'index'])
            ->middleware('permission:view-schedules');
        Route::post('/', [TeachingScheduleController::class, 'store'])
            ->middleware('permission:manage-schedules');
        Route::get('/{teachingSchedule}', [TeachingScheduleController::class, 'show'])
            ->middleware('permission:view-schedules');
        Route::put('/{teachingSchedule}', [TeachingScheduleController::class, 'update'])
            ->middleware('permission:manage-schedules');
        Route::delete('/{teachingSchedule}', [TeachingScheduleController::class, 'destroy'])
            ->middleware('permission:manage-schedules');
        Route::post('/clone-semester', [TeachingScheduleController::class, 'cloneSemester'])
            ->middleware('permission:manage-schedules');
        Route::post('/replace-teacher', [TeachingScheduleController::class, 'replaceTeacher'])
            ->middleware('permission:manage-schedules');
    });

    // Dashboard routes
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('auth:sanctum');

    // Teacher Management routes
    Route::prefix('teachers')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [TeacherController::class, 'index'])->middleware('permission:view-teachers');
        Route::post('/', [TeacherController::class, 'store'])->middleware('permission:create-teachers');
        Route::get('/{teacher}', [TeacherController::class, 'show'])->middleware('permission:view-teachers');
        Route::get('/{teacher}/relationships', [TeacherController::class, 'relationships'])->middleware('permission:view-teachers');
        Route::put('/{teacher}', [TeacherController::class, 'update'])->middleware('permission:edit-teachers');
        Route::delete('/{teacher}', [TeacherController::class, 'destroy'])->middleware('permission:delete-teachers');
        Route::patch('/{teacher}/status', [TeacherController::class, 'updateStatus'])->middleware('permission:edit-teachers');
        Route::post('/{teacher}/grant-access', [TeacherController::class, 'grantAccess'])->middleware('permission:edit-teachers');
    });

    // Notification routes
    Route::prefix('notifications')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);
        Route::post('/bulk-delete', [NotificationController::class, 'bulkDelete']);
    });

    // Cash Book (Buku Kas) routes
    Route::prefix('cash-book-entries')->middleware('auth:sanctum')->group(function () {
        // Specific routes BEFORE {entry} binding
        Route::get('/summary', [CashBookEntryController::class, 'summary'])->middleware('permission:view-cashbook');
        Route::get('/', [CashBookEntryController::class, 'index'])->middleware('permission:view-cashbook');
        Route::post('/', [CashBookEntryController::class, 'store'])->middleware('permission:manage-cashbook');

        Route::get('/{entry}', [CashBookEntryController::class, 'show'])->middleware('permission:view-cashbook');
        Route::get('/{entry}/proof', [CashBookEntryController::class, 'streamProof'])->middleware('permission:view-cashbook');
        // POST is supported with _method=PATCH so multipart uploads work from browsers.
        Route::match(['patch', 'post'], '/{entry}', [CashBookEntryController::class, 'update'])->middleware('permission:manage-cashbook');
        Route::delete('/{entry}', [CashBookEntryController::class, 'destroy'])->middleware('permission:manage-cashbook');
    });

    Route::prefix('cash-book-categories')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [CashBookCategoryController::class, 'index'])->middleware('permission:view-cashbook');
        Route::post('/', [CashBookCategoryController::class, 'store'])->middleware('permission:manage-cashbook');
        Route::patch('/{category}', [CashBookCategoryController::class, 'update'])->middleware('permission:manage-cashbook');
        Route::delete('/{category}', [CashBookCategoryController::class, 'destroy'])->middleware('permission:manage-cashbook');
    });

    Route::prefix('cash-book-activity-logs')->middleware(['auth:sanctum', 'permission:view-cashbook'])->group(function () {
        Route::get('/', [CashBookActivityLogController::class, 'index']);
    });

    // Fee Management — US1 Master Data (fee_types + fee_schedules)
    Route::prefix('fee-types')->middleware(['auth:sanctum', 'permission:manage-fee-types'])->group(function () {
        Route::get('/', [FeeTypeController::class, 'index']);
        Route::post('/', [FeeTypeController::class, 'store']);
        Route::patch('/{feeType}', [FeeTypeController::class, 'update']);
        Route::delete('/{feeType}', [FeeTypeController::class, 'destroy']);
    });

    Route::prefix('fee-schedules')->middleware(['auth:sanctum', 'permission:manage-fee-schedules'])->group(function () {
        Route::get('/', [FeeScheduleController::class, 'index']);
        Route::post('/', [FeeScheduleController::class, 'store']);
        Route::patch('/{feeSchedule}', [FeeScheduleController::class, 'update']);
        Route::delete('/{feeSchedule}', [FeeScheduleController::class, 'destroy']);
    });

    // Fee Management — US2 Student Fee Assignments
    Route::prefix('students/{student}/fee-assignments')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [StudentFeeAssignmentController::class, 'index'])
            ->middleware('permission:view-student-fees');
        Route::post('/snapshot', [StudentFeeAssignmentController::class, 'snapshot'])
            ->middleware('permission:manage-student-fees');
        Route::post('/manual', [StudentFeeAssignmentController::class, 'manual'])
            ->middleware('permission:manage-student-fees');
    });

    Route::prefix('fees/unassigned-students')->middleware(['auth:sanctum', 'permission:view-student-fees'])->group(function () {
        Route::get('/count', [FeeUnassignedStudentsController::class, 'count']);
        Route::get('/', [FeeUnassignedStudentsController::class, 'index']);
    });

    // Fee Management — US3 Bills + Payments + Cash Book auto-link
    Route::prefix('bills')->middleware('auth:sanctum')->group(function () {
        Route::get('/arrears-summary', [BillController::class, 'arrearsSummary'])
            ->middleware('permission:view-student-fees');
        Route::get('/arrears-students', [BillController::class, 'arrearsStudents'])
            ->middleware('permission:view-student-fees');
        Route::post('/generate', [BillController::class, 'generate'])
            ->middleware('permission:manage-student-fees');
        Route::get('/', [BillController::class, 'index'])
            ->middleware('permission:view-student-fees');
        Route::get('/{bill}', [BillController::class, 'show'])
            ->middleware('permission:view-student-fees');
        Route::delete('/{bill}', [BillController::class, 'destroy'])
            ->middleware('permission:manage-student-fees');
    });

    Route::prefix('student-payments')->middleware('auth:sanctum')->group(function () {
        Route::post('/bulk', [StudentPaymentController::class, 'bulkStore'])
            ->middleware('permission:record-payments');
        Route::get('/', [StudentPaymentController::class, 'index'])
            ->middleware('permission:view-student-fees');
        Route::post('/', [StudentPaymentController::class, 'store'])
            ->middleware('permission:record-payments');
        Route::get('/{studentPayment}/proof', [StudentPaymentController::class, 'streamProof'])
            ->middleware('permission:view-student-fees');
        Route::delete('/{studentPayment}', [StudentPaymentController::class, 'destroy'])
            ->middleware('permission:record-payments');
    });

    // PSB Period Management routes
    Route::prefix('psb/periods')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [RegistrationPeriodController::class, 'index'])
            ->middleware('permission:view-registration-periods');
        Route::post('/', [RegistrationPeriodController::class, 'store'])
            ->middleware('permission:manage-registration-periods');
        Route::get('/{registrationPeriod}', [RegistrationPeriodController::class, 'show'])
            ->middleware('permission:view-registration-periods');
        Route::put('/{registrationPeriod}', [RegistrationPeriodController::class, 'update'])
            ->middleware('permission:manage-registration-periods');
        Route::delete('/{registrationPeriod}', [RegistrationPeriodController::class, 'destroy'])
            ->middleware('permission:manage-registration-periods');
    });

});

// PSB Public routes (no auth required)
Route::prefix('v1/public/psb')->group(function () {
    Route::get('/active-period', [PublicPsbController::class, 'activePeriod']);
    Route::get('/active-periods', [PublicPsbController::class, 'activePeriods']);
    Route::get('/periods/{registrationPeriod}/biaya', [PublicPsbController::class, 'periodBiaya']);
    Route::post('/register', [PublicPsbController::class, 'register']);
});

// Student Completion (public, token-based, rate-limited)
Route::prefix('v1/public/student-completion/{registrationId}')->group(function () {
    Route::get('/', [StudentCompletionController::class, 'show'])
        ->middleware('throttle:30,1');
    Route::put('/', [StudentCompletionController::class, 'update'])
        ->middleware('throttle:10,1');
    Route::post('/documents', [StudentCompletionController::class, 'uploadDocument'])
        ->middleware('throttle:10,1');
    Route::delete('/documents/{documentType}', [StudentCompletionController::class, 'deleteDocument'])
        ->middleware('throttle:10,1');
});
