<?php

declare(strict_types=1);

namespace Integrity\Api\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Plugin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles /meetings REST API endpoints.
 */
class MeetingController
{
    use ControllerTrait;

    public function __construct(AuditLogger $auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Get arguments for meetings endpoint.
     */
    public function getMeetingsArgs(): array
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
            'day' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param >= 0 && $param <= 6);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : (int) $param;
                },
            ],
            'online' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || in_array($param, ['true', 'false', '1', '0', true, false], true);
                },
                'sanitize_callback' => function ($param) {
                    if ($param === null) {
                        return null;
                    }
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                },
            ],
            'group_id' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param > 0);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : absint($param);
                },
            ],
            'search' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get all meetings.
     */
    public function getMeetings(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);

        try {
            $container = Plugin::getContainer();
            $meetingRepo = $container->get(MeetingRepository::class);

            // Build query args
            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $groupId = $request->get_param('group_id');
            if ($groupId !== null) {
                $args['meta_query'] = $args['meta_query'] ?? [];
                $args['meta_query'][] = [
                    'key' => 'group_id',
                    'value' => (int) $groupId,
                    'compare' => '=',
                ];
            }

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            // Get day parameter
            $day = $request->get_param('day');
            $online = $request->get_param('online');

            // Determine which repository method to use based on filters
            $meetings = [];

            if ($day !== null && $online !== null) {
                $onlineFilter = in_array($online, ['true', '1', true], true);
                $args['meta_query'] = $args['meta_query'] ?? [];
                $args['meta_query'][] = [
                    'key' => 'attendance_option',
                    'value' => $onlineFilter ? 'online' : 'in_person',
                    'compare' => '=',
                ];
                $meetings = $meetingRepo->findByDay((int) $day, $args);
            } elseif ($day !== null) {
                $meetings = $meetingRepo->findByDay((int) $day, $args);
            } elseif ($online !== null) {
                $onlineFilter = in_array($online, ['true', '1', true], true);
                if ($onlineFilter) {
                    $meetings = $meetingRepo->findOnline($args);
                } else {
                    $meetings = $meetingRepo->findInPerson($args);
                }
            } else {
                $meetings = $meetingRepo->findAll($args);
            }

            // Get the true total across all pages
            $countArgs = array_diff_key($args, ['posts_per_page' => 0, 'paged' => 0]);

            if ($day !== null && $online !== null) {
                $total = $meetingRepo->count($countArgs);
            } elseif ($day !== null) {
                $total = $meetingRepo->count($countArgs);
            } elseif ($online !== null) {
                $onlineFilter = in_array($online, ['true', '1', true], true);
                if ($onlineFilter) {
                    $total = count($meetingRepo->findOnline($countArgs));
                } else {
                    $total = count($meetingRepo->findInPerson($countArgs));
                }
            } else {
                $total = $meetingRepo->count($countArgs);
            }

            // Transform to API response format
            $data = array_map([$this, 'transformMeeting'], $meetings);

            $perPage = $request->get_param('per_page');
            $page = $request->get_param('page');

            $this->logRequest(
                $keyData['api_key_id'],
                $request,
                ['per_page' => $perPage, 'page' => $page, 'day' => $day, 'online' => $online],
                200,
                $startTime
            );

            return $this->paginatedResponse(array_values($data), $total, $page, $perPage);

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, null, 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Get a single meeting.
     */
    public function getMeeting(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $meetingRepo = $container->get(MeetingRepository::class);

            $meeting = $meetingRepo->find($id);

            if (!$meeting) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 404, $startTime);

                return $this->notFoundResponse('Meeting');
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 200, $startTime);

            return $this->successResponse($this->transformMeeting($meeting));

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Transform a Meeting object to API response format.
     */
    private function transformMeeting(Meeting $meeting): array
    {
        $contacts = $meeting->getContacts();
        $location = $meeting->getLocation();

        return [
            'id' => $meeting->getId(),
            'name' => $meeting->getName(),
            'slug' => $meeting->getSlug(),
            'location' => $location !== null ? $this->transformLocation($location) : null,
            'url' => $meeting->getUrl(),
            'day' => $meeting->getDay(),
            'day_of_week' => $meeting->getDayOfWeek(),
            'time' => $meeting->getTime(),
            'end_time' => $meeting->getEndTime(),
            'types' => $meeting->getTypes(),
            'state' => $meeting->getState(),
            'is_online' => $meeting->isOnline(),
            'online_link' => $meeting->getOnlineLink(),
            'online_notes' => $meeting->getOnlineNotes(),
            'contacts' => !empty($contacts) ? array_map([$this, 'transformContact'], $contacts) : [],
            'meta' => $meeting->getMeta(),
            'updated' => $this->formatUpdatedTimestamp($meeting->getUpdated()),
        ];
    }

    /**
     * Batch fetch meetings by IDs using repository.
     *
     * @param MeetingRepository $meetingRepo
     * @param array<int> $meetingIds
     * @return array<int, Meeting> Map of meeting ID to meeting object
     */
    public function batchGetMeetings(MeetingRepository $meetingRepo, array $meetingIds): array
    {
        if (empty($meetingIds)) {
            return [];
        }

        $meetings = $meetingRepo->findAll([
            'post__in' => $meetingIds,
            'posts_per_page' => -1,
        ]);

        $meetingMap = [];
        foreach ($meetings as $meeting) {
            $meetingMap[$meeting->getId()] = $meeting;
        }

        return $meetingMap;
    }
}