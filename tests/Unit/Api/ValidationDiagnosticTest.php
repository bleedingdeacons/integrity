<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api;

use Integrity\Api\ValidationDiagnostic;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;

/**
 * Tests for the REST validation diagnostic.
 *
 * Two things are under test and the second is the one that matters. That the
 * diagnostic fires at all — it hung on rest_pre_dispatch, which runs before
 * route attributes exist, so it never did. And that what it writes is
 * redacted, because relocating it to a hook where it works is precisely what
 * would have started spilling member contact details into the log file.
 */
final class ValidationDiagnosticTest extends TestCase
{
    private AuditLogger|Mockery\MockInterface $auditLogger;
    private ValidationDiagnostic $diagnostic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditLogger = Mockery::mock(AuditLogger::class);
        $this->diagnostic  = new ValidationDiagnostic($this->auditLogger);
    }

    /** @test */
    public function it_hooks_the_filter_that_actually_carries_the_validation_error(): void
    {
        $this->diagnostic->register();

        $this->assertFilterAdded('rest_request_before_callbacks');
    }

    /** @test */
    public function it_redacts_the_parameters_before_logging_them(): void
    {
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

        $result = $this->diagnostic->handle($this->validationError(), null, $request);

        $this->assertSame('rest_invalid_param', $result->get_error_code());
    }

    /** @test */
    public function it_ignores_a_successful_response(): void
    {
        $this->auditLogger->shouldNotReceive('redact');

        $response = new \WP_REST_Response(['ok' => true]);
        $this->assertSame($response, $this->diagnostic->handle($response, null, null));
    }

    /** @test */
    public function it_ignores_routes_belonging_to_other_plugins(): void
    {
        $request = $this->createMockRequest(['_route' => '/wp/v2/posts']);

        $this->auditLogger->shouldNotReceive('redact');

        $this->diagnostic->handle($this->validationError(), null, $request);
    }

    /** @test */
    public function it_ignores_an_authentication_refusal(): void
    {
        // Integrity's own auth path already audits its 401s and 403s; logging
        // them here as well would double every refusal.
        $request = $this->createMockRequest(['_route' => '/integrity/v1/groups']);

        $this->auditLogger->shouldNotReceive('redact');

        $unauthorised = new \WP_Error('invalid_api_key', 'Invalid', ['status' => 401]);
        $this->diagnostic->handle($unauthorised, null, $request);
    }

    /** @test */
    public function it_returns_whatever_it_was_given_on_every_path(): void
    {
        // A filter that is only an observer must never alter the response.
        $request = $this->createMockRequest(['_route' => '/integrity/v1/groups']);
        $this->auditLogger->shouldReceive('redact')->andReturn([]);

        $error = $this->validationError();
        $this->assertSame($error, $this->diagnostic->handle($error, null, $request));
    }

    private function validationError(): \WP_Error
    {
        return new \WP_Error('rest_invalid_param', 'Invalid parameter(s): date', ['status' => 400]);
    }
}
