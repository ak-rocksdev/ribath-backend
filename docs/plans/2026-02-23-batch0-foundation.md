# Batch 0 Foundation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete Batch 0 foundation — User model traits, API response structure, role/permission seeder, auth endpoints, CORS, and Gate::before for super_admin.

**Architecture:** API-only Laravel 12 with Sanctum token auth and Spatie Permission v6 for RBAC. Controllers delegate to Service classes. Consistent JSON responses via trait on base Controller. All validation in Form Request classes.

**Tech Stack:** Laravel 12, PHP 8.2, Sanctum 4.3, Spatie Permission 6.24, PostgreSQL 18, Pest

---

### Task 1: Enable RefreshDatabase in Pest + User Model Traits

**Files:**
- Modify: `tests/Pest.php:14-16`
- Modify: `app/Models/User.php:6-13`

**Step 1: Enable RefreshDatabase in Pest**

In `tests/Pest.php`, uncomment the RefreshDatabase line:

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

**Step 2: Add HasApiTokens and HasRoles traits to User model**

In `app/Models/User.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

**Step 3: Run tests to verify nothing broke**

Run: `php artisan test`
Expected: 2 passing tests

**Step 4: Commit**

```bash
git add app/Models/User.php tests/Pest.php
git commit -m "feat: add HasApiTokens and HasRoles traits to User model"
```

---

### Task 2: API Response Trait + Base Controller

**Files:**
- Create: `app/Traits/ApiResponseTrait.php`
- Modify: `app/Http/Controllers/Controller.php`
- Create: `tests/Feature/ApiResponseTest.php`

**Step 1: Write the test**

Create `tests/Feature/ApiResponseTest.php`:

```php
<?php

use App\Http\Controllers\Controller;

test('success response has correct structure', function () {
    $controller = new class extends Controller {
        public function testSuccess()
        {
            return $this->successResponse(['key' => 'value'], 'It worked');
        }
    };

    $response = $controller->testSuccess();
    $data = $response->getData(true);

    expect($data)->toHaveKeys(['success', 'data', 'message'])
        ->and($data['success'])->toBeTrue()
        ->and($data['data'])->toBe(['key' => 'value'])
        ->and($data['message'])->toBe('It worked');
});

test('error response has correct structure', function () {
    $controller = new class extends Controller {
        public function testError()
        {
            return $this->errorResponse('Something failed', ['field' => ['Required']], 422);
        }
    };

    $response = $controller->testError();
    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(422)
        ->and($data['success'])->toBeFalse()
        ->and($data['message'])->toBe('Something failed')
        ->and($data['errors'])->toBe(['field' => ['Required']]);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ApiResponseTest`
Expected: FAIL — method successResponse not found

**Step 3: Create the ApiResponseTrait**

Create `app/Traits/ApiResponseTrait.php`:

```php
<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponseTrait
{
    protected function successResponse(mixed $data = null, string $message = 'Operation successful', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $code);
    }

    protected function errorResponse(string $message = 'An error occurred', mixed $errors = null, int $code = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    protected function paginatedResponse(LengthAwarePaginator $paginator, string $message = 'Data retrieved successfully'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => $message,
        ]);
    }
}
```

**Step 4: Use the trait in base Controller**

Modify `app/Http/Controllers/Controller.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponseTrait;

abstract class Controller
{
    use ApiResponseTrait;
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ApiResponseTest`
Expected: 2 passing tests

**Step 6: Commit**

```bash
git add app/Traits/ApiResponseTrait.php app/Http/Controllers/Controller.php tests/Feature/ApiResponseTest.php
git commit -m "feat: add ApiResponseTrait for consistent JSON responses"
```

---

### Task 3: Gate::before for super_admin

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/SuperAdminGateTest.php`

**Step 1: Write the test**

Create `tests/Feature/SuperAdminGateTest.php`:

```php
<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

test('super_admin bypasses all permission checks', function () {
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('any-random-permission'))->toBeTrue();
});

test('non super_admin does not bypass permission checks', function () {
    $role = Role::create(['name' => 'pengurus_pesantren', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('any-random-permission'))->toBeFalse();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SuperAdminGateTest`
Expected: First test FAILS (super_admin has no special bypass yet)

**Step 3: Add Gate::before in AppServiceProvider**

Modify `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SuperAdminGateTest`
Expected: 2 passing tests

**Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/SuperAdminGateTest.php
git commit -m "feat: super_admin bypasses all permission checks via Gate::before"
```

---

### Task 4: RolePermissionSeeder

**Files:**
- Create: `database/seeders/RolePermissionSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/RolePermissionSeederTest.php`

**Step 1: Write the test**

Create `tests/Feature/RolePermissionSeederTest.php`:

```php
<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('seeder creates roles and permissions', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    expect(Role::where('name', 'super_admin')->exists())->toBeTrue()
        ->and(Role::where('name', 'pengurus_pesantren')->exists())->toBeTrue()
        ->and(Role::count())->toBe(2);

    expect(Permission::where('name', 'view-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'create-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'edit-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'delete-users')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-roles')->exists())->toBeTrue()
        ->and(Permission::where('name', 'manage-settings')->exists())->toBeTrue();
});

test('seeder assigns permissions to pengurus_pesantren', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $pengurusPesantren = Role::findByName('pengurus_pesantren');

    expect($pengurusPesantren->hasPermissionTo('view-users'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('manage-settings'))->toBeTrue()
        ->and($pengurusPesantren->hasPermissionTo('delete-users'))->toBeFalse();
});

test('seeder creates admin user with super_admin role', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $adminUser = User::where('email', 'akhabsy110@gmail.com')->first();

    expect($adminUser)->not->toBeNull()
        ->and($adminUser->name)->toBe('Abdul Kadir Habsyi')
        ->and($adminUser->hasRole('super_admin'))->toBeTrue();
});

test('seeder is idempotent', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    expect(Role::count())->toBe(2)
        ->and(User::where('email', 'akhabsy110@gmail.com')->count())->toBe(1);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RolePermissionSeederTest`
Expected: FAIL — seeder class not found

**Step 3: Create RolePermissionSeeder**

Create `database/seeders/RolePermissionSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            'manage-roles',
            'manage-settings',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);

        $pengurusPesantren = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
        $pengurusPesantren->syncPermissions(['view-users', 'manage-settings']);

        $adminUser = User::firstOrCreate(
            ['email' => 'akhabsy110@gmail.com'],
            [
                'name' => 'Abdul Kadir Habsyi',
                'password' => Hash::make('kadir9263606'),
            ]
        );
        $adminUser->assignRole($superAdmin);
    }
}
```

**Step 4: Update DatabaseSeeder to call RolePermissionSeeder**

Modify `database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=RolePermissionSeederTest`
Expected: 4 passing tests

**Step 6: Run the seeder against the real database**

Run: `php artisan db:seed --class=RolePermissionSeeder`

**Step 7: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/RolePermissionSeederTest.php
git commit -m "feat: add RolePermissionSeeder with admin user, roles, and permissions"
```

---

### Task 5: Auth Service + Form Requests

**Files:**
- Create: `app/Services/AuthService.php`
- Create: `app/Http/Requests/Auth/LoginRequest.php`
- Create: `app/Http/Requests/Auth/ChangePasswordRequest.php`

**Step 1: Create LoginRequest**

Create `app/Http/Requests/Auth/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

**Step 2: Create ChangePasswordRequest**

Create `app/Http/Requests/Auth/ChangePasswordRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'new_password' => ['required', 'string', Password::min(8), 'confirmed'],
        ];
    }
}
```

**Step 3: Create AuthService**

Create `app/Services/AuthService.php`:

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function attemptLogin(string $email, string $password): ?array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function getAuthenticatedUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ];
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $user->update([
            'password' => Hash::make($newPassword),
        ]);
    }
}
```

**Step 4: Run all tests to make sure nothing broke**

Run: `php artisan test`
Expected: All passing

**Step 5: Commit**

```bash
git add app/Services/AuthService.php app/Http/Requests/Auth/LoginRequest.php app/Http/Requests/Auth/ChangePasswordRequest.php
git commit -m "feat: add AuthService and auth form request classes"
```

---

### Task 6: Auth Controller + Routes

**Files:**
- Create: `app/Http/Controllers/Api/Auth/AuthController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Auth/AuthTest.php`

**Step 1: Write the tests**

Create `tests/Feature/Auth/AuthTest.php`:

```php
<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::create(['name' => 'super_admin']);

    $this->adminUser = User::factory()->create([
        'email' => 'admin@test.com',
        'password' => bcrypt('password123'),
    ]);
    $this->adminUser->assignRole('super_admin');
});

test('user can login with valid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => ['user' => ['id', 'name', 'email', 'roles'], 'token'],
            'message',
        ])
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'admin@test.com')
        ->assertJsonPath('data.user.roles.0', 'super_admin');
});

test('login fails with invalid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('login validates required fields', function () {
    $response = $this->postJson('/api/v1/auth/login', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('authenticated user can get profile', function () {
    $response = $this->actingAs($this->adminUser)
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'admin@test.com')
        ->assertJsonPath('data.roles.0', 'super_admin');
});

test('unauthenticated user cannot get profile', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertUnauthorized();
});

test('authenticated user can logout', function () {
    $token = $this->adminUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson('/api/v1/auth/logout');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('authenticated user can change password', function () {
    $response = $this->actingAs($this->adminUser)
        ->putJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'new-password456',
            'new_password_confirmation' => 'new-password456',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    // Verify new password works
    expect(Hash::check('new-password456', $this->adminUser->fresh()->password))->toBeTrue();
});

test('change password fails with wrong current password', function () {
    $response = $this->actingAs($this->adminUser)
        ->putJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-current',
            'new_password' => 'new-password456',
            'new_password_confirmation' => 'new-password456',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AuthTest`
Expected: FAIL — routes don't exist yet

**Step 3: Create AuthController**

Create `app/Http/Controllers/Api/Auth/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function login(LoginRequest $loginRequest): JsonResponse
    {
        $result = $this->authService->attemptLogin(
            $loginRequest->validated('email'),
            $loginRequest->validated('password'),
        );

        if (! $result) {
            return $this->errorResponse('Invalid credentials', null, 401);
        }

        return $this->successResponse($result, 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        $userData = $this->authService->getAuthenticatedUser($request->user());

        return $this->successResponse($userData);
    }

    public function changePassword(ChangePasswordRequest $changePasswordRequest): JsonResponse
    {
        $this->authService->changePassword(
            $changePasswordRequest->user(),
            $changePasswordRequest->validated('new_password'),
        );

        return $this->successResponse(null, 'Password changed successfully');
    }
}
```

**Step 4: Set up API routes**

Replace `routes/api.php`:

```php
<?php

use App\Http\Controllers\Api\Auth\AuthController;
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

});
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=AuthTest`
Expected: 8 passing tests

**Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Auth/AuthController.php routes/api.php tests/Feature/Auth/AuthTest.php
git commit -m "feat: add auth endpoints (login, logout, me, change-password)"
```

---

### Task 7: CORS Configuration

**Files:**
- Modify: `bootstrap/app.php`

**Step 1: Configure CORS in bootstrap/app.php**

Modify `bootstrap/app.php`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

Also add Sanctum's stateful domains and CORS config to `.env`:

```
SANCTUM_STATEFUL_DOMAINS=localhost:8080,ribath-masjid-hub.local,ribath-backend.local:8181
FRONTEND_URL=http://localhost:8080
```

**Step 2: Verify the CORS headers work**

Run: `php artisan route:list --path=api`
Expected: Shows all v1/auth routes

**Step 3: Commit**

```bash
git add bootstrap/app.php
git commit -m "feat: configure CORS and Sanctum stateful domains"
```

---

### Task 8: Handle JSON Exception Responses for API

**Files:**
- Modify: `bootstrap/app.php`

**Step 1: Configure exception handler for JSON responses**

API requests should always get JSON error responses (not HTML). Update `bootstrap/app.php` exceptions:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(function ($request) {
        return $request->is('api/*') || $request->expectsJson();
    });
})->create();
```

**Step 2: Verify by calling a non-existent route**

Run: `php artisan test` (all tests should still pass)

**Step 3: Commit**

```bash
git add bootstrap/app.php
git commit -m "feat: force JSON error responses for API routes"
```

---

### Task 9: Run Full Test Suite + Seed Real Database

**Step 1: Run all tests**

Run: `php artisan test`
Expected: All tests pass (existing + new)

**Step 2: Seed the real database**

Run: `php artisan db:seed`

**Step 3: Verify login works with real credentials**

Run: `php artisan tinker` and test:
```php
$user = User::where('email', 'akhabsy110@gmail.com')->first();
$user->getRoleNames(); // should return ['super_admin']
```

**Step 4: Run Pint for code style**

Run: `./vendor/bin/pint`

**Step 5: Final commit**

```bash
git add -A
git commit -m "chore: code style fixes via Pint"
```
