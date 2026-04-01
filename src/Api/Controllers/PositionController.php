<?php

declare(strict_types=1);

namespace Integrity\Api\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use Unity\Plugin;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles /positions REST API endpoints.
 */
class PositionController
{
    use ControllerTrait;

    public function __construct(AuditLogger $auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Get arguments for positions endpoint.
     */
    public function getPositionsArgs(): array
    {
        return [
            'per_page' => [
                'default' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0 && $param <= 500;
                },
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get all positions.
     */
    public function getPositions(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);

        try {
            $container = Plugin::getContainer();
            $positionRepo = $container->get(PositionRepository::class);

            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            $positions = $positionRepo->findAll($args);
            $total = $positionRepo->count($args);

            $perPage = (int) $request->get_param('per_page');

            $this->logRequest($keyData['api_key_id'], $request, $args, 200, $startTime);

            return $this->paginatedResponse(
                array_map([$this, 'transformPosition'], $positions),
                $total,
                (int) $request->get_param('page'),
                $perPage
            );

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, null, 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Get a single position.
     */
    public function getPosition(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $positionRepo = $container->get(PositionRepository::class);

            $position = $positionRepo->findById($id);

            if (!$position) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 404, $startTime);

                return $this->notFoundResponse('Position');
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 200, $startTime);

            return $this->successResponse($this->transformPosition($position));

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Transform a Position object to API response format.
     */
    private function transformPosition(Position $position): array
    {
        return [
            'id' => $position->getId(),
            'long_name' => $position->getLongName(),
            'short_description' => $position->getShortDescription(),
            'summary' => $position->getSummary(),
            'email' => $position->getEmail(),
            'minimum_sobriety' => $position->getMinimumSobriety(),
            'term_years' => $position->getTermYears(),
            'link' => $position->getLink(),
            'updated' => $this->formatUpdatedTimestamp($position->getUpdated()),
        ];
    }

    /**
     * Batch fetch positions by IDs using repository.
     *
     * @param PositionRepository $positionRepo
     * @param array<int> $positionIds
     * @return array<int, Position> Map of position ID to position object
     */
    public function batchGetPositions(PositionRepository $positionRepo, array $positionIds): array
    {
        if (empty($positionIds)) {
            return [];
        }

        $positions = $positionRepo->findAll([
            'post__in' => $positionIds,
            'posts_per_page' => -1,
        ]);

        $positionMap = [];
        foreach ($positions as $position) {
            $positionMap[$position->getId()] = $position;
        }

        return $positionMap;
    }
}