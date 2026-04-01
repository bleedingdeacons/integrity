<?php

declare(strict_types=1);

namespace Integrity\Api\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Plugin;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles /groups REST API endpoints.
 */
class GroupController
{
    use ControllerTrait;

    public function __construct(AuditLogger $auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Get arguments for groups endpoint.
     */
    public function getGroupsArgs(): array
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
            'district_id' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param > 0);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : absint($param);
                },
            ],
            'expand' => [
                'default' => '',
                'validate_callback' => function ($param) {
                    $allowed = ['meetings'];
                    $requested = array_filter(array_map('trim', explode(',', $param)));
                    return empty(array_diff($requested, $allowed));
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get all groups.
     */
    public function getGroups(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);

        try {
            $container = Plugin::getContainer();
            $groupRepo = $container->get(GroupRepository::class);

            $perPage = (int) $request->get_param('per_page');
            $page = (int) $request->get_param('page');

            // Build query args
            $args = [
                'posts_per_page' => $perPage,
                'paged' => $page,
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            // Get groups for the current page
            $groups = $groupRepo->findAll($args);

            // Filter by district if specified
            $districtId = $request->get_param('district_id');
            if ($districtId !== null) {
                $groups = array_filter($groups, function ($group) use ($districtId) {
                    return $group->getDistrictId() === $districtId;
                });
            }

            // Get the true total across all pages (lightweight ID-only query)
            $countArgs = array_diff_key($args, ['posts_per_page' => 0, 'paged' => 0]);
            $countArgs['posts_per_page'] = -1;
            $countArgs['fields'] = 'ids';
            $total = count($groupRepo->findAll($countArgs));

            // Parse expand parameter
            $expandParam = $request->get_param('expand');
            $expand = !empty($expandParam) ? array_filter(array_map('trim', explode(',', $expandParam))) : [];

            // Transform to API response format
            $data = array_map(function ($group) use ($expand) {
                return $this->transformGroup($group, $expand);
            }, $groups);

            // Log successful request
            $this->logRequest(
                $keyData['api_key_id'],
                $request,
                ['per_page' => $perPage, 'page' => $page],
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
     * Get a single group.
     */
    public function getGroup(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $groupRepo = $container->get(GroupRepository::class);

            $group = $groupRepo->findById($id);

            if (!$group || !$group->isValid()) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 404, $startTime);

                return $this->notFoundResponse('Group');
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 200, $startTime);

            // Parse expand parameter
            $expandParam = $request->get_param('expand');
            $expand = !empty($expandParam) ? array_filter(array_map('trim', explode(',', $expandParam))) : [];

            return $this->successResponse($this->transformGroup($group, $expand));

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Transform a Group object to API response format.
     */
    private function transformGroup(Group $group, array $expand = []): array
    {
        $contacts = $group->getContacts();
        $meetings = $group->getMeetings();

        // Check if meetings should be expanded with full data
        $expandMeetings = in_array('meetings', $expand, true);

        if ($expandMeetings) {
            // Return full meeting data
            $meetingData = array_map([$this, 'transformMeetingForGroup'], $meetings);
        } else {
            // Return just meeting IDs for backwards compatibility
            $meetingData = array_map(function ($meeting) {
                return $meeting->getId();
            }, $meetings);
        }

        return [
            'id' => $group->getId(),
            'title' => $group->getTitle(),
            'email' => $group->getEmail(),
            'phone' => $group->getPhone(),
            'website' => $group->getWebsite(),
            'link' => $group->getLink(),
            'notes' => $group->getGroupNotes(),
            'group_email' => $group->getEmail(),
            'district_id' => $group->getDistrictId(),
            'last_contact' => $group->getLastContact(),
            $expandMeetings ? 'meetings' : 'meeting_ids' => $meetingData,
            'contacts' => !empty($contacts) ? array_map([$this, 'transformContact'], $contacts) : [],
            'contribution_options' => [
                'venmo' => $group->getVenmo(),
                'paypal' => $group->getPaypal(),
                'square' => $group->getSquare(),
                'has_options' => $group->hasContributionOptions(),
            ],
            'updated' => $this->formatUpdatedTimestamp($group->getUpdated()),
        ];
    }

    /**
     * Transform a Meeting object when expanding group meetings.
     *
     * Replicates the same shape as MeetingController::transformMeeting()
     * without introducing a cross-controller dependency.
     */
    private function transformMeetingForGroup(Meeting $meeting): array
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
     * Batch fetch groups by IDs using repository.
     *
     * @param GroupRepository $groupRepo
     * @param array<int> $groupIds
     * @return array<int, Group> Map of group ID to group object
     */
    public function batchGetGroups(GroupRepository $groupRepo, array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $groups = $groupRepo->findAll([
            'post__in' => $groupIds,
            'posts_per_page' => -1,
        ]);

        $groupMap = [];
        foreach ($groups as $group) {
            $groupMap[$group->getId()] = $group;
        }

        return $groupMap;
    }
}