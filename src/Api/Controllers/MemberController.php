<?php

declare(strict_types=1);

namespace Integrity\Api\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use Integrity\Utils\Mask;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Plugin;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles /members REST API endpoints.
 */
class MemberController
{
    use ControllerTrait;

    private GroupController $groupController;
    private PositionController $positionController;
    private MeetingController $meetingController;

    public function __construct(
        AuditLogger $auditLogger,
        GroupController $groupController,
        PositionController $positionController,
        MeetingController $meetingController
    ) {
        $this->auditLogger = $auditLogger;
        $this->groupController = $groupController;
        $this->positionController = $positionController;
        $this->meetingController = $meetingController;
    }

    /**
     * Get arguments for members endpoint.
     */
    public function getMembersArgs(): array
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
            'home_group_id' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param > 0);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : absint($param);
                },
            ],
        ];
    }

    /**
     * Get arguments for update member endpoint.
     */
    public function getUpdateMemberArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'anonymous_name' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) <= 255;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'personal_email' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && ($param === '' || is_email($param));
                },
                'sanitize_callback' => 'sanitize_email',
            ],
            'mobile_number' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) <= 50;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'show_anonymous_name' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'show_member_profile' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'anonymous_profile' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
                'sanitize_callback' => 'wp_kses_post',
            ],
            'home_group_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'is_gsr' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'intergroup_position_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'intergroup_position_rotation' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    if ($param === '' || $param === null) {
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
     * Get arguments for create member endpoint.
     */
    public function getCreateMemberArgs(): array
    {
        return [
            'anonymous_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0 && strlen($param) <= 255;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'personal_email' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && ($param === '' || is_email($param));
                },
                'sanitize_callback' => 'sanitize_email',
            ],
            'mobile_number' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) <= 50;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'home_group_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'is_gsr' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'intergroup_position_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'intergroup_position_rotation' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    if ($param === '' || $param === null) {
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
     * Get all members.
     */
    public function getMembers(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);
            $groupRepo = $container->get(GroupRepository::class);
            $positionRepo = $container->get(PositionRepository::class);
            $meetingRepo = $container->get(MeetingRepository::class);

            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            $homeGroupId = $request->get_param('home_group_id');
            if ($homeGroupId !== null) {
                $args['meta_query'] = [
                    [
                        'key' => 'home_group',
                        'value' => $homeGroupId,
                        'compare' => '=',
                    ],
                ];
            }

            $members = $memberRepo->findAll($args);
            $total = $memberRepo->count($args);

            $perPage = (int) $request->get_param('per_page');

            // Collect all IDs needed for transformation
            $groupIds = [];
            $positionIds = [];
            $meetingIds = [];
            foreach ($members as $member) {
                $homeGroup = $member->getHomeGroup();
                if ($homeGroup > 0) {
                    $groupIds[] = $homeGroup;
                }
                $intergroupPosition = $member->getIntergroupPosition();
                if ($intergroupPosition > 0) {
                    $positionIds[] = $intergroupPosition;
                }
                $meetingPo = $member->getMeetingPO();
                if (is_numeric($meetingPo) && (int) $meetingPo > 0) {
                    $meetingIds[] = (int) $meetingPo;
                }
            }

            // Batch fetch using repositories
            $groupCache = $this->groupController->batchGetGroups($groupRepo, array_unique($groupIds));
            $positionCache = $this->positionController->batchGetPositions($positionRepo, array_unique($positionIds));
            $meetingCache = $this->meetingController->batchGetMeetings($meetingRepo, array_unique($meetingIds));

            // Transform with cached data
            $clear = $this->hasClearPermission($keyData);
            $transformedMembers = array_map(function ($member) use ($groupCache, $positionCache, $meetingCache, $clear) {
                return $this->transformMemberWithCache($member, $groupCache, $positionCache, $meetingCache, $clear);
            }, $members);

            $this->logRequest($keyData['api_key_id'], $request, $args, 200, $startTime);

            return $this->paginatedResponse(
                $transformedMembers,
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
     * Get a single member.
     */
    public function getMember(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);

            $member = $memberRepo->findById($id);

            if (!$member) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 404, $startTime);

                return $this->notFoundResponse('Member');
            }

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 200, $startTime);

            // Resolve related entities in one pass
            $groupRepo = $container->get(GroupRepository::class);
            $positionRepo = $container->get(PositionRepository::class);
            $meetingRepo = $container->get(MeetingRepository::class);

            $groupCache = $this->groupController->batchGetGroups($groupRepo, $member->getHomeGroup() > 0 ? [$member->getHomeGroup()] : []);
            $positionCache = $this->positionController->batchGetPositions($positionRepo, $member->getIntergroupPosition() > 0 ? [$member->getIntergroupPosition()] : []);
            $meetingPo = $member->getMeetingPO();
            $meetingCache = $this->meetingController->batchGetMeetings($meetingRepo, is_numeric($meetingPo) && (int) $meetingPo > 0 ? [(int) $meetingPo] : []);

            return $this->successResponse(
                $this->transformMemberWithCache(
                    $member,
                    $groupCache,
                    $positionCache,
                    $meetingCache,
                    $this->hasClearPermission($keyData)
                )
            );

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Update a member.
     */
    public function updateMember(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);
            $memberFactory = $container->get(MemberFactory::class);

            // Fetch existing member
            $existingMember = $memberRepo->findById($id);

            if (!$existingMember) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 404, $startTime);

                return $this->notFoundResponse('Member');
            }

            $homeGroupId = $request->has_param('home_group_id')
                ? (int) $request->get_param('home_group_id')
                : $existingMember->getHomeGroup();

            if ($request->has_param('home_group_id') && $homeGroupId > 0) {
                $groupRepo = $container->get(GroupRepository::class);
                $group = $groupRepo->findById($homeGroupId);
                if (!$group || !$group->isValid()) {
                    $this->logRequest($keyData['api_key_id'], $request, ['id' => $id, 'home_group_id' => $homeGroupId], 422, $startTime);

                    return $this->errorResponse('invalid_home_group', 'The specified home group does not exist', 422);
                }
            }

            $intergroupPositionId = $request->has_param('intergroup_position_id')
                ? (int) $request->get_param('intergroup_position_id')
                : $existingMember->getIntergroupPosition();

            if ($request->has_param('intergroup_position_id') && $intergroupPositionId > 0) {
                $positionRepo = $container->get(PositionRepository::class);
                $positions = $positionRepo->findAll([
                    'post__in' => [$intergroupPositionId],
                    'posts_per_page' => 1,
                ]);
                if (empty($positions)) {
                    $this->logRequest($keyData['api_key_id'], $request, ['id' => $id, 'intergroup_position_id' => $intergroupPositionId], 422, $startTime);

                    return $this->errorResponse('invalid_intergroup_position', 'The specified intergroup position does not exist', 422);
                }
            }

            $clear = $this->hasClearPermission($keyData);

            // Resolve personal_email: skip if the submitted value is obscured.
            // Keys holding members:clear receive and send values in the clear,
            // so the round-trip obscured-value guard does not apply to them.
            $personalEmail = $existingMember->getPersonalEmail();
            if ($request->has_param('personal_email')) {
                $submittedEmail = $request->get_param('personal_email');
                if ($clear || !$this->isObscuredEmail($submittedEmail)) {
                    $personalEmail = $submittedEmail;
                }
            }

            // Resolve mobile_number: skip if the submitted value is obscured
            // (see personal_email above for the clear-key exception).
            $mobileNumber = $existingMember->getMobileNumber();
            if ($request->has_param('mobile_number')) {
                $submittedMobile = $request->get_param('mobile_number');
                if ($clear || !$this->isObscuredPhone($submittedMobile)) {
                    $mobileNumber = $submittedMobile;
                }
            }

            // Build updated member using existing values as defaults (partial update)
            $updatedMember = $memberFactory->createNew(
                $id,
                $request->has_param('anonymous_name')
                    ? $request->get_param('anonymous_name')
                    : $existingMember->getAnonymousName(),
                $request->has_param('show_anonymous_name')
                    ? $request->get_param('show_anonymous_name')
                    : $existingMember->showAnonymousName(),
                $request->has_param('show_member_profile')
                    ? $request->get_param('show_member_profile')
                    : $existingMember->showMemberProfile(),
                $request->has_param('anonymous_profile')
                    ? $request->get_param('anonymous_profile')
                    : $existingMember->getAnonymousProfile(),
                $intergroupPositionId,
                $request->has_param('intergroup_position_rotation')
                    ? $request->get_param('intergroup_position_rotation')
                    : $existingMember->getIntergroupPositionRotation(),
                $homeGroupId,
                $request->has_param('is_gsr')
                    ? $request->get_param('is_gsr')
                    : $existingMember->isGSR(),
                $existingMember->getMeetingPO(),
                $personalEmail,
                $mobileNumber,
            );

            // Save
            $saved = $memberRepo->save($updatedMember);

            if (!$saved) {
                $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

                return $this->errorResponse('save_failed', 'Failed to update member', 500);
            }

            // Re-fetch the saved member to return the latest state
            $savedMember = $memberRepo->findById($id);

            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 200, $startTime);

            $returnMember = $savedMember ?? $updatedMember;

            return $this->successResponse(
                $this->buildMemberResponse($container, $returnMember, $clear)
            );

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, ['id' => $id], 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Create a new member.
     */
    public function createMember(WP_REST_Request $request): WP_REST_Response
    {
        ['start_time' => $startTime, 'key_data' => $keyData] = $this->extractRequestContext($request);

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);
            $memberFactory = $container->get(MemberFactory::class);

            $anonymousName = $request->get_param('anonymous_name');
            $personalEmail = $request->get_param('personal_email') ?? '';
            $mobileNumber = $request->get_param('mobile_number') ?? '';
            $homeGroupId = $request->has_param('home_group_id')
                ? (int) $request->get_param('home_group_id')
                : 0;
            $isGsr = $request->has_param('is_gsr')
                ? $request->get_param('is_gsr')
                : false;
            $intergroupPositionId = $request->has_param('intergroup_position_id')
                ? (int) $request->get_param('intergroup_position_id')
                : 0;
            $intergroupPositionRotation = $request->has_param('intergroup_position_rotation')
                ? $request->get_param('intergroup_position_rotation')
                : '';

            // Validate referenced entities exist
            if ($homeGroupId > 0) {
                $groupRepo = $container->get(GroupRepository::class);
                $group = $groupRepo->findById($homeGroupId);
                if (!$group || !$group->isValid()) {
                    $this->logRequest($keyData['api_key_id'], $request, ['home_group_id' => $homeGroupId], 422, $startTime);

                    return $this->errorResponse('invalid_home_group', 'The specified home group does not exist', 422);
                }
            }

            if ($intergroupPositionId > 0) {
                $positionRepo = $container->get(PositionRepository::class);
                $positions = $positionRepo->findAll([
                    'post__in' => [$intergroupPositionId],
                    'posts_per_page' => 1,
                ]);
                if (empty($positions)) {
                    $this->logRequest($keyData['api_key_id'], $request, ['intergroup_position_id' => $intergroupPositionId], 422, $startTime);

                    return $this->errorResponse('invalid_intergroup_position', 'The specified intergroup position does not exist', 422);
                }
            }

            // Create the WordPress post for the new member
            $postId = wp_insert_post([
                'post_type' => 'intergroup-member',
                'post_title' => $anonymousName,
                'post_status' => 'publish',
            ], true);

            if (is_wp_error($postId)) {
                \Integrity\Plugin::logError('Integrity: wp_insert_post failed for member create', [
                    'error'          => $postId->get_error_message(),
                    'anonymous_name' => $anonymousName,
                ]);

                $this->logRequest($keyData['api_key_id'], $request, ['anonymous_name' => $anonymousName], 500, $startTime);

                return $this->errorResponse('create_failed', 'Failed to create member', 500);
            }

            // Build the member object with all fields via the factory
            $newMember = $memberFactory->createNew(
                $postId,
                $anonymousName,
                false,   // show_anonymous_name
                false,   // show_member_profile
                '',      // anonymous_profile
                $intergroupPositionId,
                $intergroupPositionRotation,
                $homeGroupId,
                $isGsr,
                null,    // meeting_po
                $personalEmail,
                $mobileNumber,
            );

            // Save ACF / meta fields
            $saved = $memberRepo->save($newMember);

            if (!$saved) {
                // Clean up the orphaned post
                wp_delete_post($postId, true);

                $this->logRequest($keyData['api_key_id'], $request, ['post_id' => $postId], 500, $startTime);

                return $this->errorResponse('save_failed', 'Failed to save member fields', 500);
            }

            // Re-fetch the saved member to return the latest state
            $savedMember = $memberRepo->findById($postId);

            $this->logRequest(
                $keyData['api_key_id'],
                $request,
                ['id' => $postId, 'anonymous_name' => $anonymousName],
                201,
                $startTime
            );

            $returnMember = $savedMember ?? $newMember;

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->buildMemberResponse($container, $returnMember, $this->hasClearPermission($keyData)),
            ], 201);

        } catch (\Exception $e) {
            $this->logRequest($keyData['api_key_id'], $request, null, 500, $startTime);

            return $this->internalErrorResponse();
        }
    }

    /**
     * Determine whether the current API key is permitted to see member
     * personal contact details (personal email, mobile number) in the clear.
     *
     * Granted by the `members:clear` permission, or the wildcard `*`.
     * Defaults to no: without this permission, contact details are masked
     * via the Mask utility in the same way they always have been.
     *
     * @param array|null $keyData Key data from extractRequestContext
     */
    private function hasClearPermission(?array $keyData): bool
    {
        if (!is_array($keyData) || empty($keyData['permissions']) || !is_array($keyData['permissions'])) {
            return false;
        }

        $permissions = $keyData['permissions'];

        return in_array('members:clear', $permissions, true)
            || in_array('*', $permissions, true);
    }

    /**
     * Build a full member response with resolved relationships.
     *
     * @param \Psr\Container\ContainerInterface $container
     * @param Member $member
     * @param bool $clear If true, return personal_email and mobile_number
     *                    unmasked. Requires the `members:clear` permission
     *                    on the calling key; resolved by the caller.
     * @return array
     */
    private function buildMemberResponse($container, Member $member, bool $clear = false): array
    {
        $groupRepo = $container->get(GroupRepository::class);
        $positionRepo = $container->get(PositionRepository::class);
        $meetingRepo = $container->get(MeetingRepository::class);

        $groupCache = $this->groupController->batchGetGroups($groupRepo, $member->getHomeGroup() > 0 ? [$member->getHomeGroup()] : []);
        $positionCache = $this->positionController->batchGetPositions($positionRepo, $member->getIntergroupPosition() > 0 ? [$member->getIntergroupPosition()] : []);
        $meetingPo = $member->getMeetingPO();
        $meetingCache = $this->meetingController->batchGetMeetings($meetingRepo, is_numeric($meetingPo) && (int) $meetingPo > 0 ? [(int) $meetingPo] : []);

        return $this->transformMemberWithCache($member, $groupCache, $positionCache, $meetingCache, $clear);
    }

    /**
     * Transform a Member object to API response format using cached entities.
     *
     * @param Member $member
     * @param array<int, Group> $groupCache
     * @param array<int, Position> $positionCache
     * @param array<int, Meeting> $meetingCache
     * @param bool $clear If true, return personal_email and mobile_number
     *                    unmasked. Defaults to false (masked), matching the
     *                    historical behaviour when no `members:clear`
     *                    permission is granted.
     * @return array
     */
    private function transformMemberWithCache(
        Member $member,
        array $groupCache,
        array $positionCache,
        array $meetingCache,
        bool $clear = false
    ): array {
        // Get home group details
        $homeGroupId = null;
        $homeGroupName = '';
        $homeGroup = $member->getHomeGroup();
        if ($homeGroup > 0) {
            $homeGroupId = $homeGroup;
            if (isset($groupCache[$homeGroupId])) {
                $homeGroupName = $groupCache[$homeGroupId]->getTitle();
            }
        }

        // Get intergroup position details
        $intergroupPositionId = null;
        $intergroupPositionName = '';
        $intergroupPositionIdValue = $member->getIntergroupPosition();
        if ($intergroupPositionIdValue > 0) {
            $intergroupPositionId = $intergroupPositionIdValue;
            if (isset($positionCache[$intergroupPositionId])) {
                $intergroupPositionName = $positionCache[$intergroupPositionId]->getLongName();
            }
        }

        // Get meeting PO
        $meetingPo = '';
        $meetingPoValue = $member->getMeetingPO();
        if (is_numeric($meetingPoValue)) {
            $meetingPoId = (int) $meetingPoValue;
            if (isset($meetingCache[$meetingPoId])) {
                $meetingPo = $meetingCache[$meetingPoId]->getName();
            }
        } elseif (is_string($meetingPoValue)) {
            $meetingPo = $meetingPoValue;
        }

        // Get permalink
        $link = '';
        if (function_exists('get_permalink')) {
            $permalink = get_permalink($member->getId());
            $link = is_string($permalink) ? $permalink : '';
        }

        return [
            'id' => $member->getId(),
            'anonymous_name' => $member->getAnonymousName(),
            'personal_email' => $clear
                ? $member->getPersonalEmail()
                : Mask::email($member->getPersonalEmail()),
            'mobile_number' => $clear
                ? $member->getMobileNumber()
                : Mask::phone($member->getMobileNumber()),
            'show_anonymous_name' => $member->showAnonymousName(),
            'show_member_profile' => $member->showMemberProfile(),
            'anonymous_profile' => $member->getAnonymousProfile(),
            'home_group_id' => $homeGroupId,
            'home_group_name' => $homeGroupName,
            'is_gsr' => $member->isGSR(),
            'meeting_po' => $meetingPo,
            'intergroup_position_id' => $intergroupPositionId,
            'intergroup_position_name' => $intergroupPositionName,
            'intergroup_position_rotation' => $member->getIntergroupPositionRotation(),
            'link' => $link,
            'updated' => $this->formatUpdatedTimestamp($member->getUpdated()),
        ];
    }
}