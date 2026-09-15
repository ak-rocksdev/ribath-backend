<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** The password an Akun Ustadz picks when he changes his temporary one at the first login. */
const PASSWORD_CHOSEN_AT_FIRST_LOGIN = 'password-pilihan-123';

/**
 * Wajib ganti password: an account made through "Beri Akses" must change its
 * temporary password before any other endpoint answers. Changes it through
 * PUT /auth/change-password, as the user does at his first login, and returns
 * the account as stored now — use the returned model, since an older copy
 * still carries the requirement.
 */
function completeFirstLoginPasswordChange($testCase, User $account, string $temporaryPassword): User
{
    $testCase->actingAs($account)
        ->putJson('/api/v1/auth/change-password', [
            'current_password' => $temporaryPassword,
            'new_password' => PASSWORD_CHOSEN_AT_FIRST_LOGIN,
            'new_password_confirmation' => PASSWORD_CHOSEN_AT_FIRST_LOGIN,
        ])
        ->assertOk();

    return $account->fresh();
}
