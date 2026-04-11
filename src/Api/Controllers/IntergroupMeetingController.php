<?php

declare(strict_types=1);

namespace Integrity\Api\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Plugin;
use Unity\Positions\Interfaces\PositionViewFactory;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles /intergroup-meetings REST API endpoints.
 */
class IntergroupMeetingController
{
    use ControllerTrait;

    public function __construct(AuditLogger $auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Get arguments for intergroup meetings endpoint.
     */
    public function getIntergroupMeetingsArgs(): array
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
            'date_from' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    if ($param === null) {
                        return true;
                    }
                    $date = \DateTime::createFromFormat('Y-m-d', $param);
                    return $date && $date->format('Y-m-d') === $param;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_to' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    if ($param === null) {
                        return true;
                    }
                    $date = \DateTime::createFromFormat('Y-m-d', $param);
                    return $date && $date->format('Y-m-d') === $param;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for register attendee endpoint.
     */
    public function getRegisterAttendeeArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'group_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'member_id' => [
                'required' => false,
                'default' => 0,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'gsr_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'gsr_proxy' => [
                'required' => false,
                'default' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || $param === '0' || $param === '1'
                        || $param === 'true' || $param === 'false';
                },
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'gsr_proxy_name' => [
                'required' => false,
                'default' => '',
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for unregister attendee endpoint.
     */
    public function getUnregisterAttendeeArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'group_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Get arguments for register officer endpoint.
     */
    public function getRegisterOfficerArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'officer_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'position_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'officer_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for unregister officer endpoint.
     */
    public function getUnregisterOfficerArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'officer_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Get all intergroup meetings.
     */
    public function getIntergroupMeetings(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $memberRepo = $container->get(MemberRepository::class);

            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $dateFrom = $request->get_param('date_from');
            $dateTo = $request->get_param('date_to');

            if ($dateFrom !== null || $dateTo !== null) {
                $metaQuery = [];
                if ($dateFrom !== null) {
                    $dateFromFormatted = str_replace('-', '', $dateFrom);
                    $metaQuery[] = [
                        'key' => 'intergroup-meeting_date',
                        'value' => $dateFromFormatted,
                        'compare' => '>=',
                    ];
                }
                if ($dateTo !== null) {
                    $dateToFormatted = str_replace('-', '', $dateTo);
                    $metaQuery[] = [
                        'key' => 'intergroup-meeting_date',
                        'value' => $dateToFormatted,
                        'compare' => '<=',
                    ];
                }
                if (count($metaQuery) > 1) {
                    $metaQuery['relation'] = 'AND';
                }
                $args['meta_query'] = $metaQuery;
            }

            $intergroupMeetings = $intergroupMeetingRepo->findAll($args);
            $total = $intergroupMeetingRepo->count($args);

            $perPage = (int) $request->get_param('per_page');

            // Collect all member IDs needed for transformation
            $allMemberIds = [];
            foreach ($intergroupMeetings as $meeting) {
                $allMemberIds = array_merge($allMemberIds, $meeting->getGroupAttendees());
            }
            $allMemberIds = array_unique(array_filter($allMemberIds));

            // Batch fetch all members at once using repository
            $memberCache = $this->batchGetMembers($memberRepo, $allMemberIds);

            // Batch fetch officer attendance records keyed by position ID
            $officerAttendanceRepo = $container->get(IntergroupMeetingOfficerAttendanceRepository::class);
            $allOfficerAttendanceCache = [];
            foreach ($intergroupMeetings as $meeting) {
                $meetingOfficerRecords = $officerAttendanceRepo->findByIntergroupMeeting($meeting->getId());
                $perMeetingCache = [];
                foreach ($meetingOfficerRecords as $record) {
                    $perMeetingCache[$record->getOfficerId()] = $record;
                }
                $allOfficerAttendanceCache[$meeting->getId()] = $perMeetingCache;
            }

            // Transform with cached members and officer attendance
            $transformedMeetings = array_map(function ($meeting) use ($memberCache, $allOfficerAttendanceCache) {
                $officerAttendanceCache = $allOfficerAttendanceCache[$meeting->getId()] ?? [];
                return $this->transformIntergroupMeetingWithCache($meeting, $memberCache, $officerAttendanceCache);
            }, $intergroupMeetings);

            $this->logRequest($keyData['api_key_id'], $request, $args, 200, $startTime);

            return $this->paginatedResponse(
                $transformedMeetings,
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
     * Get a single intergroup meeting.
     */
    public function getIntergroupMeeting(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);

            $intergroupMeeting = $intergroupMeetingRepo->findById($id);

            if (!$intergroupMeeting) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 404, $startTime);

                return $this->notFoundResponse('Intergroup meeting');
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 200, $startTime);

            // Batch-fetch members for group attendee names
            $memberRepo = $container->get(MemberRepository::class);
            $memberCache = $this->batchGetMembers(
                $memberRepo,
                array_filter($intergroupMeeting->getGroupAttendees())
            );

            // Fetch officer attendance records keyed by position ID
            $officerAttendanceRepo = $container->get(IntergroupMeetingOfficerAttendanceRepository::class);
            $officerRecords = $officerAttendanceRepo->findByIntergroupMeeting($id);
            $officerAttendanceCache = [];
            foreach ($officerRecords as $record) {
                $officerAttendanceCache[$record->getOfficerId()] = $record;
            }

            return $this->successResponse(
                $this->transformIntergroupMeetingWithCache($intergroupMeeting, $memberCache, $officerAttendanceCache)
            );

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Register a group as an attendee of an intergroup meeting.
     */
    public function registerIntergroupMeetingAttendee(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $meetingId = (int) $request->get_param('id');
        $groupId = (int) $request->get_param('group_id');
        $memberId = (int) $request->get_param('member_id');
        $gsrName = (string) $request->get_param('gsr_name');
        $gsrProxy = (bool) $request->get_param('gsr_proxy');
        $gsrProxyName = (string) $request->get_param('gsr_proxy_name');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $groupRepo = $container->get(GroupRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingGroupAttendanceRepository::class);
            $attendanceFactory = $container->get(
                'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingGroupAttendanceFactory'
            );

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->findById($meetingId);

            if (!$intergroupMeeting) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 404, $startTime);

                return $this->notFoundResponse('Intergroup meeting');
            }

            // Validate group exists and look up the group name
            $group = $groupRepo->findById($groupId);

            if (!$group) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 404, $startTime);

                return $this->errorResponse('group_not_found', 'Group not found', 404);
            }

            $meetingGroup = $group->getTitle();

            // Resolve all GSR names for this group using the group view
            $groupViewFactory = $container->get(GroupViewFactory::class);
            $groupView = $groupViewFactory->createFrom($groupId);

            if ($groupView) {
                $gsrNames = [];
                foreach ($groupView->getMembers() as $groupMember) {
                    if ($groupMember->isGSR()) {
                        $gsrNames[] = $groupMember->getAnonymousName();
                    }
                }
                if (!empty($gsrNames)) {
                    $gsrName = implode(', ', $gsrNames);
                }
            }

            // Build a denormalised label for the attendance record
            $meetingLabel = $this->buildMeetingLabel($intergroupMeeting);

            // Check if group is already registered
            if ($attendanceRepo->existsForMeetingAndGroup($meetingId, $groupId)) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 409, $startTime);

                return $this->errorResponse('already_registered', 'Group is already registered for this intergroup meeting', 409);
            }

            // Create the attendance record in the custom table first
            $attendance = $attendanceFactory->createNew(
                $meetingId,
                $meetingLabel,
                $groupId,
                $memberId,
                $meetingGroup,
                $gsrName,
                $gsrProxy,
                $gsrProxyName
            );

            $attendanceSaved = $attendanceRepo->save($attendance);

            if (!$attendanceSaved) {
                // Distinguish a genuine duplicate (concurrent race) from other failures
                global $wpdb;
                if ($wpdb->last_error && str_contains($wpdb->last_error, 'Duplicate entry')) {
                    $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 409, $startTime);

                    return $this->errorResponse('already_registered', 'Group is already registered for this intergroup meeting', 409);
                }

                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 500, $startTime);

                return $this->errorResponse('attendance_save_failed', 'Failed to save attendance record', 500);
            }

            // Add the group to the ACF relationship field (post meta)
            $intergroupMeeting->addGroupAttendee($groupId);

            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 500, $startTime);

                return $this->errorResponse('save_failed', 'Failed to register attendee', 500);
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 201, $startTime);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'intergroup_meeting_id' => $meetingId,
                    'group_id' => $groupId,
                    'member_id' => $memberId,
                    'meeting_group' => $meetingGroup,
                    'gsr_name' => $gsrName,
                    'gsr_proxy' => $gsrProxy,
                    'gsr_proxy_name' => $gsrProxyName,
                    'registered' => true,
                ],
            ], 201);

        } catch (\Throwable $e) {
            \Integrity\Plugin::logError('Integrity: registerIntergroupMeetingAttendee error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Unregister a group from an intergroup meeting.
     */
    public function unregisterIntergroupMeetingAttendee(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $meetingId = (int) $request->get_param('id');
        $groupId = (int) $request->get_param('group_id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingGroupAttendanceRepository::class);

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->findById($meetingId);

            if (!$intergroupMeeting) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 404, $startTime);

                return $this->notFoundResponse('Intergroup meeting');
            }

            // Check if group is actually registered
            if (!$intergroupMeeting->hasGroupAttendee($groupId)) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 404, $startTime);

                return $this->errorResponse('not_registered', 'Group is not registered for this intergroup meeting', 404);
            }

            // Remove the group
            $intergroupMeeting->removeGroupAttendee($groupId);

            // Save the updated intergroup meeting
            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 500, $startTime);

                return $this->errorResponse('save_failed', 'Failed to unregister attendee', 500);
            }

            // Delete the attendance record for this group at this meeting
            $attendanceRepo->deleteByIntergroupMeetingAndGroup($meetingId, $groupId);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 200, $startTime);

            return $this->successResponse([
                'intergroup_meeting_id' => $meetingId,
                'group_id' => $groupId,
                'registered' => false,
            ]);

        } catch (\Throwable $e) {
            \Integrity\Plugin::logError('Integrity: unregisterIntergroupMeetingAttendee error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'group_id' => $groupId], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Register an officer as an attendee of an intergroup meeting.
     */
    public function registerIntergroupMeetingOfficer(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $meetingId = (int) $request->get_param('id');
        $officerId = (int) $request->get_param('officer_id');
        $positionName = (string) $request->get_param('position_name');
        $officerName = (string) $request->get_param('officer_name');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $memberRepo = $container->get(MemberRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingOfficerAttendanceRepository::class);
            $attendanceFactory = $container->get(
                'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingOfficerAttendanceFactory'
            );

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->findById($meetingId);

            if (!$intergroupMeeting) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 404, $startTime);

                return $this->notFoundResponse('Intergroup meeting');
            }

            // Validate officer (member) exists
            $member = $memberRepo->findById($officerId);

            if (!$member) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 404, $startTime);

                return $this->errorResponse('officer_not_found', 'Officer not found', 404);
            }

            // Resolve the member's intergroup position ID
            $positionId = $member->getIntergroupPosition();

            if ($positionId <= 0) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 422, $startTime);

                return $this->errorResponse('no_intergroup_position', 'Officer does not have an intergroup position assigned', 422);
            }

            // Resolve position name and officer name(s) server-side
            $positionViewFactory = $container->get(PositionViewFactory::class);
            $positionView = $positionViewFactory->createFrom($positionId);

            if ($positionView) {
                $positionName = $positionView->getPosition()->getLongName();
                $officerName = $positionView->getOfficerDisplayName();
            }

            // Check if officer's position is already registered
            if ($attendanceRepo->existsForMeetingAndOfficer($meetingId, $positionId)) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 409, $startTime);

                return $this->errorResponse('already_registered', 'Officer is already registered for this intergroup meeting', 409);
            }

            // Create the attendance record in the custom table first
            $meetingLabel = $this->buildMeetingLabel($intergroupMeeting);
            $attendance = $attendanceFactory->createNew(
                $meetingId,
                $meetingLabel,
                $positionId,
                $positionName,
                $officerName
            );

            $attendanceSaved = $attendanceRepo->save($attendance);

            if (!$attendanceSaved) {
                // Distinguish a genuine duplicate (concurrent race) from other failures
                global $wpdb;
                if ($wpdb->last_error && str_contains($wpdb->last_error, 'Duplicate entry')) {
                    $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 409, $startTime);

                    return $this->errorResponse('already_registered', 'Officer is already registered for this intergroup meeting', 409);
                }

                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 500, $startTime);

                return $this->errorResponse('attendance_save_failed', 'Failed to save officer attendance record', 500);
            }

            // Add the officer's position to the ACF relationship field (post meta)
            $intergroupMeeting->addOfficerAttendee($positionId);

            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 500, $startTime);

                return $this->errorResponse('save_failed', 'Failed to register officer', 500);
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 201, $startTime);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'intergroup_meeting_id' => $meetingId,
                    'officer_id' => $officerId,
                    'officer_name' => $officerName,
                    'position_name' => $positionName,
                    'registered' => true,
                ],
            ], 201);

        } catch (\Throwable $e) {
            \Integrity\Plugin::logError('Integrity: registerIntergroupMeetingOfficer error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Unregister an officer from an intergroup meeting.
     */
    public function unregisterIntergroupMeetingOfficer(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $meetingId = (int) $request->get_param('id');
        $officerId = (int) $request->get_param('officer_id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingOfficerAttendanceRepository::class);
            $memberRepo = $container->get(MemberRepository::class);

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->findById($meetingId);

            if (!$intergroupMeeting) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 404, $startTime);

                return $this->notFoundResponse('Intergroup meeting');
            }

            // Resolve the member's intergroup position ID
            $member = $memberRepo->findById($officerId);

            if (!$member) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 404, $startTime);

                return $this->errorResponse('officer_not_found', 'Officer not found', 404);
            }

            $positionId = $member->getIntergroupPosition();

            if ($positionId <= 0) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 422, $startTime);

                return $this->errorResponse('no_intergroup_position', 'Officer does not have an intergroup position assigned', 422);
            }

            // Check if officer's position is actually registered
            if (!$intergroupMeeting->hasOfficerAttendee($positionId)) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 404, $startTime);

                return $this->errorResponse('not_registered', 'Officer is not registered for this intergroup meeting', 404);
            }

            // Remove the officer's position from the ACF relationship field
            $intergroupMeeting->removeOfficerAttendee($positionId);

            // Save the updated intergroup meeting
            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 500, $startTime);

                return $this->errorResponse('save_failed', 'Failed to unregister officer', 500);
            }

            // Delete the attendance record for this officer's position at this meeting
            $attendanceRepo->deleteByIntergroupMeetingAndOfficer($meetingId, $positionId);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 200, $startTime);

            return $this->successResponse([
                'intergroup_meeting_id' => $meetingId,
                'officer_id' => $officerId,
                'registered' => false,
            ]);

        } catch (\Throwable $e) {
            \Integrity\Plugin::logError('Integrity: unregisterIntergroupMeetingOfficer error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $meetingId, 'officer_id' => $officerId], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Transform an IntergroupMeeting object to API response format using cached data.
     *
     * The ACF attending_officers field stores intergroup-position CPT post IDs,
     * while the officer attendance DB table stores member IDs, position names,
     * and officer names. The attendance cache is keyed by position ID so it
     * can be matched against the ACF field values.
     *
     * @param IntergroupMeeting $intergroupMeeting
     * @param array<int, Member> $memberCache
     * @param array<int, IntergroupMeetingOfficerAttendance> $officerAttendanceCache Map of position ID to attendance record
     * @return array
     */
    private function transformIntergroupMeetingWithCache(
        IntergroupMeeting $intergroupMeeting,
        array $memberCache,
        array $officerAttendanceCache = []
    ): array {
        $groupAttendeeIds = $intergroupMeeting->getGroupAttendees();
        $groupAttendees = [];

        // Resolve group names from the group CPT
        foreach ($groupAttendeeIds as $groupId) {
            $groupTitle = get_the_title($groupId);
            $groupAttendees[] = [
                'id' => $groupId,
                'name' => is_string($groupTitle) ? $groupTitle : '',
            ];
        }

        // The ACF field stores position IDs (intergroup-position CPT post IDs).
        // Resolve officer details from the attendance DB table records.
        $positionIds = $intergroupMeeting->getOfficersAttending();
        $officersAttending = [];

        foreach ($positionIds as $positionId) {
            $entry = ['id' => $positionId];

            if (isset($officerAttendanceCache[$positionId])) {
                $record = $officerAttendanceCache[$positionId];
                $entry['officer_id'] = $record->getOfficerId();
                $entry['officer_name'] = $record->getOfficerName();
                $entry['position_name'] = $record->getPositionName();
            }

            $officersAttending[] = $entry;
        }

        return [
            'id' => $intergroupMeeting->getId(),
            'title' => $intergroupMeeting->getTitle(),
            'date' => $intergroupMeeting->getDate(),
            'group_attendee_ids' => $groupAttendeeIds,
            'group_attendees' => $groupAttendees,
            'officers_attending_ids' => $positionIds,
            'officers_attending' => $officersAttending,
            'attending_groups' => $groupAttendeeIds,
            'attending_officers' => $positionIds,
            'updated' => $this->formatUpdatedTimestamp($intergroupMeeting->getUpdated()),
        ];
    }

    /**
     * Build a human-readable label for an intergroup meeting.
     *
     * Combines the meeting title and formatted date into a single string
     * suitable for display and filtering in attendance records.
     *
     * Format: "Title — Month Day, Year" (or just the title or date when
     * only one is available).
     */
    private function buildMeetingLabel(IntergroupMeeting $meeting): string
    {
        $title = $meeting->getTitle();
        $date  = $meeting->getDate();

        $formattedDate = '';
        if (!empty($date)) {
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $formattedDate = gmdate('F j, Y', $timestamp);
            } else {
                $formattedDate = $date;
            }
        }

        if (!empty($title) && !empty($formattedDate)) {
            return $title . ' — ' . $formattedDate;
        }

        if (!empty($title)) {
            return $title;
        }

        if (!empty($formattedDate)) {
            return $formattedDate;
        }

        return 'Meeting (ID: ' . $meeting->getId() . ')';
    }

    /**
     * Batch fetch members by IDs using repository.
     *
     * @param MemberRepository $memberRepo
     * @param array<int> $memberIds
     * @return array<int, Member> Map of member ID to member object
     */
    private function batchGetMembers(MemberRepository $memberRepo, array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }

        $members = $memberRepo->findAll([
            'post__in' => $memberIds,
            'posts_per_page' => -1,
        ]);

        $memberMap = [];
        foreach ($members as $member) {
            $memberMap[$member->getId()] = $member;
        }

        return $memberMap;
    }
}