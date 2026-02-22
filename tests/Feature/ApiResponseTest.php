<?php

use App\Http\Controllers\Controller;

test('success response has correct structure', function () {
    $controller = new class extends Controller
    {
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
    $controller = new class extends Controller
    {
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
