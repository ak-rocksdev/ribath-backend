<?php

use App\Http\Controllers\Api\Admin\ClassLevelController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SchoolController;
use App\Http\Controllers\Api\Admin\StudentController;
use App\Http\Controllers\Api\Admin\TeacherController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\PSB\RegistrationController;
use App\Http\Controllers\Api\PSB\RegistrationPeriodController;
use App\Http\Controllers\Api\Public\PublicPsbController;
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
    });

    // Schools route
    Route::get('/schools', [SchoolController::class, 'index'])->middleware('auth:sanctum');

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
    Route::post('/register', [PublicPsbController::class, 'register']);
});
