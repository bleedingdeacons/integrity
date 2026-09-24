<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api;

use Integrity\Api\ValidationDiagnostic;
use Integrity\Auth\AuditLogger;
use Mockery;

/*
 * Tests for the REST validation diagnostic.
 *
 * Two things are under test and the second is the one that matters. That the
 * diagnostic fires at all — it hung on rest_pre_dispatch, which runs before
 * route attributes exist, so it never did. And that what it writes is
 * redacted, because relocating it to a hook where it works is precisely what
 * would have started spilling member contact details into the log file.
 */

function validationDiagnosticError(): \WP_Error
{
    return new \WP_Error('rest_invalid_param', 'Invalid parameter(s): date', ['status' => 400]);
}

beforeEach(function () {
    $this->auditLogger = Mockery::mock(AuditLogger::class);
    $this->diagnostic  = new ValidationDiagnostic($this->auditLogger);
});

it('hooks the filter that actually carries the validation error', function () {
    $this->diagnostic->register();

    $this->assertFilterAdded('rest_request_before_callbacks');
});

it('redacts the parameters before logging them', function () {
    $request = $this->createMockRequest([
        'personal_email' => 'member@example.com',
        'mobile_number'  => '07700900000',
        'page'           => 2,
        '_route'         => '/integrity/v1/members/create',
    ]);

    // The whole point: the diagnostic never touches get_params() output
    // directly, it hands it to the redactor first.
    $this->auditLogger->shouldReceive('redact')
        ->once()
        ->with(Mockery::type('array'))
        ->andReturn(['personal_email' => '[REDACTED]', 'mobile_number' => '[REDACTED]', 'page' => 2]);

    $result = $this->diagnostic->handle(validationDiagnosticError(), null, $request);

    expect($result->get_error_code())->toBe('rest_invalid_param');
});

it('ignores a successful response', function () {
    $this->auditLogger->shouldNotReceive('redact');

    $response = new \WP_REST_Response(['ok' => true]);
    expect($this->diagnostic->handle($response, null, null))->toBe($response);
});

it('ignores routes belonging to other plugins', function () {
    $request = $this->createMockRequest(['_route' => '/wp/v2/posts']);

    $this->auditLogger->shouldNotReceive('redact');

    $this->diagnostic->handle(validationDiagnosticError(), null, $request);
});

it('ignores an authentication refusal', function () {
    // Integrity's own auth path already audits its 401s and 403s; logging
    // them here as well would double every refusal.
    $request = $this->createMockRequest(['_route' => '/integrity/v1/groups']);

    $this->auditLogger->shouldNotReceive('redact');

    $unauthorised = new \WP_Error('invalid_api_key', 'Invalid', ['status' => 401]);
    $this->diagnostic->handle($unauthorised, null, $request);
});

it('returns whatever it was given on every path', function () {
    // A filter that is only an observer must never alter the response.
    $request = $this->createMockRequest(['_route' => '/integrity/v1/groups']);
    $this->auditLogger->shouldReceive('redact')->andReturn([]);

    $error = validationDiagnosticError();
    expect($this->diagnostic->handle($error, null, $request))->toBe($error);
});
