<?php

declare(strict_types=1);

namespace Integrity\Api;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use WP_REST_Request;

/**
 * Logs parameter-validation failures on Integrity routes.
 *
 * <b>Why not rest_pre_dispatch.</b> This hung there and could never fire.
 * That filter runs in WP_REST_Server::dispatch() *before*
 * match_request_to_handler() calls set_attributes(), so the request has no
 * route args yet, has_valid_params() walks an empty list and returns true.
 * The block only ever ran for a malformed JSON body — never for the
 * parameter validation it was written to diagnose.
 *
 * rest_request_before_callbacks fires inside respond_to_request(), after
 * attributes are set and after core has already run has_valid_params() and
 * sanitize_params(). It is handed their WP_Error as $response, so the error
 * is read from there rather than recomputed: correct, and cheaper.
 *
 * <b>Why the redaction matters more than the relocation.</b> The original
 * logged $request->get_params() with nothing removed. Moving it to a hook
 * where it works was the obvious fix and the dangerous one — a working
 * diagnostic would have started writing member personal_email and
 * mobile_number straight into the log file. Everything goes through
 * {@see AuditLogger::redact()} first.
 */
final class ValidationDiagnostic
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function register(): void
    {
        add_filter('rest_request_before_callbacks', [$this, 'handle'], 10, 3);
    }

    /**
     * Log a 400 on an Integrity route, then return the response untouched.
     *
     * A pure observer: whatever arrives is what leaves, on every path.
     *
     * @param mixed $response The response or WP_Error so far.
     * @param mixed $handler  The matched route handler.
     * @param mixed $request  The request being answered.
     * @return mixed The $response it was given.
     */
    public function handle($response, $handler = null, $request = null)
    {
        if (!is_wp_error($response) || !$request instanceof WP_REST_Request) {
            return $response;
        }

        if (strpos((string) $request->get_route(), '/integrity/') !== 0) {
            return $response;
        }

        // Validation failures only. A permission callback's 401/403 reaches
        // this filter too, and Integrity's auth path already audits those
        // itself — logging them again here would double every refusal.
        $data = $response->get_error_data();
        if (!is_array($data) || ($data['status'] ?? null) !== 400) {
            return $response;
        }

        $context = [
            'route'  => $request->get_route(),
            'errors' => $response->get_error_message(),
            'data'   => $data,
            'params' => $this->auditLogger->redact($request->get_params()),
        ];

        if (function_exists('wp_log')) {
            wp_log('integrity')->error('Integrity 400 validation failure', $context);
        } else {
            // Sentinel absent: the fallback keeps the route and the messages,
            // which are fixed strings, and drops the parameters entirely
            // rather than trusting error_log with them.
            error_log('Integrity 400 validation failure: ' . wp_json_encode([
                'route'  => $context['route'],
                'errors' => $context['errors'],
            ]));
        }

        return $response;
    }
}
