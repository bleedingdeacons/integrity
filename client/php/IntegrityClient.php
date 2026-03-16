<?php

declare(strict_types=1);

/**
 * Integrity API PHP Client
 *
 * A PHP REST client for the Integrity WordPress plugin API.
 * Provides access to Groups, Meetings, Positions, Members,
 * and Intergroup Meetings from the Unity plugin.
 *
 * Authentication: Bearer token via Authorization header
 * Base URL format: https://your-wordpress-site.com/wp-json/integrity/v1
 *
 * @see https://github.com/your-org/integrity
 */

// ---------------------------------------------------------------------------
// Response DTOs
// ---------------------------------------------------------------------------

/**
 * Rate limit information included in every authenticated response.
 */
class RateLimit
{
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $reset,
    ) {}

    public function resetDateTime(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->reset);
    }
}

/**
 * Pagination metadata returned in list responses.
 */
class PaginationMeta
{
    public function __construct(
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
    ) {}
}

/**
 * Generic API response wrapping a single item.
 *
 * @template T
 */
class ApiResponse
{
    /**
     * @param bool           $success
     * @param T|null         $data
     * @param string|null    $errorCode
     * @param string|null    $errorMessage
     * @param int            $httpStatus
     * @param RateLimit|null $rateLimit
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $httpStatus,
        public readonly ?RateLimit $rateLimit = null,
    ) {}
}

/**
 * Generic API response wrapping a list of items.
 *
 * @template T
 */
class ApiListResponse
{
    /**
     * @param bool               $success
     * @param T[]                $data
     * @param PaginationMeta|null $meta
     * @param string|null        $errorCode
     * @param string|null        $errorMessage
     * @param int                $httpStatus
     * @param RateLimit|null     $rateLimit
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $data,
        public readonly ?PaginationMeta $meta,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly int $httpStatus,
        public readonly ?RateLimit $rateLimit = null,
    ) {}
}

// ---------------------------------------------------------------------------
// Model classes
// ---------------------------------------------------------------------------

class ContributionOptions
{
    public function __construct(
        public readonly ?string $venmo,
        public readonly ?string $paypal,
        public readonly ?string $square,
        public readonly bool $hasOptions,
    ) {}
}

class Contact
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $updated,
    ) {}
}

class Location
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $address,
        public readonly string $city,
        public readonly string $state,
        public readonly string $postalCode,
        public readonly string $country,
        public readonly string $region,
        public readonly string $notes,
        public readonly string $link,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly string $timezone,
        public readonly string $formattedAddress,
        public readonly string $updated,
    ) {}
}

class Meeting
{
    /** @param Contact[] $contacts */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?Location $location,
        public readonly string $url,
        public readonly ?int $day,
        public readonly string $dayOfWeek,
        public readonly string $time,
        public readonly string $endTime,
        public readonly array $types,
        public readonly string $state,
        public readonly bool $isOnline,
        public readonly string $onlineLink,
        public readonly string $onlineNotes,
        public readonly array $contacts,
        public readonly array $meta,
        public readonly string $updated,
    ) {}
}

class Group
{
    /**
     * @param Contact[]             $contacts
     * @param int[]|Meeting[]       $meetingIds  IDs or full Meeting objects depending on expand flag
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $website,
        public readonly string $link,
        public readonly string $notes,
        public readonly string $groupEmail,
        public readonly ?int $districtId,
        public readonly string $lastContact,
        public readonly array $meetingIds,
        public readonly array $contacts,
        public readonly ContributionOptions $contributionOptions,
        public readonly string $updated,
    ) {}
}

class Position
{
    public function __construct(
        public readonly int $id,
        public readonly string $longName,
        public readonly string $shortDescription,
        public readonly string $summary,
        public readonly string $email,
        public readonly ?int $minimumSobriety,
        public readonly ?int $termYears,
        public readonly string $link,
        public readonly string $updated,
    ) {}
}

class Member
{
    public function __construct(
        public readonly int $id,
        public readonly string $anonymousName,
        public readonly string $personalEmail,
        public readonly string $mobileNumber,
        public readonly bool $showAnonymousName,
        public readonly bool $showMemberProfile,
        public readonly string $anonymousProfile,
        public readonly ?int $homeGroupId,
        public readonly string $homeGroupName,
        public readonly bool $isGsr,
        public readonly string $meetingPo,
        public readonly ?int $intergroupPositionId,
        public readonly string $intergroupPositionName,
        public readonly string $intergroupPositionRotation,
        public readonly string $link,
        public readonly string $updated,
    ) {}
}

class GroupAttendee
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}

class OfficerAttendee
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}

class IntergroupMeeting
{
    /**
     * @param int[]             $groupAttendeeIds
     * @param GroupAttendee[]   $groupAttendees
     * @param int[]             $officersAttendingIds
     * @param OfficerAttendee[] $officersAttending
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $date,
        public readonly array $groupAttendeeIds,
        public readonly array $groupAttendees,
        public readonly array $officersAttendingIds,
        public readonly array $officersAttending,
        public readonly string $updated,
    ) {}
}

class HealthStatus
{
    public function __construct(
        public readonly string $status,
        public readonly string $timestamp,
        public readonly string $version,
        public readonly bool $unityAvailable,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }
}

// ---------------------------------------------------------------------------
// Request parameter objects
// ---------------------------------------------------------------------------

class GroupsQuery
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $search = null,
        public ?int $districtId = null,
        public bool $expandMeetings = false,
    ) {}
}

class MeetingsQuery
{
    /**
     * @param int|null  $day     0 = Sunday … 6 = Saturday
     * @param bool|null $online  true = online only, false = in-person only
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?int $day = null,
        public ?bool $online = null,
        public ?int $groupId = null,
        public ?string $search = null,
    ) {}
}

class PositionsQuery
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $search = null,
    ) {}
}

class MembersQuery
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $search = null,
        public ?int $homeGroupId = null,
    ) {}
}

class IntergroupMeetingsQuery
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 100,
        public ?string $dateFrom = null, // Y-m-d
        public ?string $dateTo = null,   // Y-m-d
    ) {}
}

class UpdateMemberRequest
{
    public function __construct(
        public ?string $anonymousName = null,
        public ?string $personalEmail = null,
        public ?string $mobileNumber = null,
        public ?bool $showAnonymousName = null,
        public ?bool $showMemberProfile = null,
        public ?string $anonymousProfile = null,
        public ?int $homeGroupId = null,
        public ?bool $isGsr = null,
        public ?int $intergroupPositionId = null,
        public ?string $intergroupPositionRotation = null, // Y-m-d or empty string
    ) {}
}

class CreateMemberRequest
{
    public function __construct(
        public string $anonymousName,
        public ?string $personalEmail = null,
        public ?string $mobileNumber = null,
        public ?int $homeGroupId = null,
        public ?bool $isGsr = null,
        public ?int $intergroupPositionId = null,
    ) {}
}

class RegisterGroupRequest
{
    public function __construct(
        public int $groupId,
        public string $gsrName,
        public int $memberId = 0,
        public bool $gsrProxy = false,
        public string $gsrProxyName = '',
    ) {}
}

class RegisterOfficerRequest
{
    public function __construct(
        public int $officerId,
        public string $positionName,
        public string $officerName,
    ) {}
}

// ---------------------------------------------------------------------------
// Exception
// ---------------------------------------------------------------------------

class IntegrityClientException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}

// ---------------------------------------------------------------------------
// Main client
// ---------------------------------------------------------------------------

class IntegrityClient
{
    private const API_PATH = '/wp-json/integrity/v1';
    private const DEFAULT_TIMEOUT = 30;

    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private ?\CurlHandle $curlHandle = null;

    /**
     * @param string $baseUrl  WordPress site root URL, e.g. "https://example.com"
     * @param string $apiKey   Integrity API key starting with "int_"
     * @param int    $timeout  HTTP request timeout in seconds
     */
    public function __construct(string $baseUrl, string $apiKey, int $timeout = self::DEFAULT_TIMEOUT)
    {
        if (empty($baseUrl)) {
            throw new \InvalidArgumentException('baseUrl must not be empty');
        }
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('apiKey must not be empty');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Release the cURL handle if open. */
    public function close(): void
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    // -----------------------------------------------------------------------
    // Health
    // -----------------------------------------------------------------------

    /**
     * Check API health. Does not require authentication.
     *
     * @return ApiResponse<HealthStatus>
     */
    public function checkHealth(): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', '/health');

        $health = new HealthStatus(
            status: $body['status'] ?? 'unknown',
            timestamp: $body['timestamp'] ?? '',
            version: $body['version'] ?? '',
            unityAvailable: (bool) ($body['unity_available'] ?? false),
        );

        return new ApiResponse(
            success: $status < 400,
            data: $health,
            errorCode: null,
            errorMessage: null,
            httpStatus: $status,
            rateLimit: null,
        );
    }

    // -----------------------------------------------------------------------
    // Groups
    // -----------------------------------------------------------------------

    /**
     * List groups with optional filtering and pagination.
     *
     * @return ApiListResponse<Group>
     */
    public function getGroups(GroupsQuery $query = new GroupsQuery()): ApiListResponse
    {
        $params = [
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];

        if ($query->search !== null) {
            $params['search'] = $query->search;
        }
        if ($query->districtId !== null) {
            $params['district_id'] = $query->districtId;
        }
        if ($query->expandMeetings) {
            $params['expand'] = 'meetings';
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', '/groups', $params);

        return $this->buildListResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateGroup($item),
        );
    }

    /**
     * Get a single group by ID.
     *
     * @return ApiResponse<Group>
     */
    public function getGroup(int $id, bool $expandMeetings = false): ApiResponse
    {
        $params = $expandMeetings ? ['expand' => 'meetings'] : [];
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', "/groups/{$id}", $params);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateGroup($item),
        );
    }

    // -----------------------------------------------------------------------
    // Meetings
    // -----------------------------------------------------------------------

    /**
     * List meetings with optional filtering and pagination.
     *
     * @return ApiListResponse<Meeting>
     */
    public function getMeetings(MeetingsQuery $query = new MeetingsQuery()): ApiListResponse
    {
        $params = [
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];

        if ($query->day !== null) {
            $params['day'] = $query->day;
        }
        if ($query->online !== null) {
            $params['online'] = $query->online ? 'true' : 'false';
        }
        if ($query->groupId !== null) {
            $params['group_id'] = $query->groupId;
        }
        if ($query->search !== null) {
            $params['search'] = $query->search;
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', '/meetings', $params);

        return $this->buildListResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateMeeting($item),
        );
    }

    /**
     * Get a single meeting by ID.
     *
     * @return ApiResponse<Meeting>
     */
    public function getMeeting(int $id): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', "/meetings/{$id}");

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateMeeting($item),
        );
    }

    // -----------------------------------------------------------------------
    // Positions
    // -----------------------------------------------------------------------

    /**
     * List positions with optional filtering and pagination.
     *
     * @return ApiListResponse<Position>
     */
    public function getPositions(PositionsQuery $query = new PositionsQuery()): ApiListResponse
    {
        $params = [
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];

        if ($query->search !== null) {
            $params['search'] = $query->search;
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', '/positions', $params);

        return $this->buildListResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydratePosition($item),
        );
    }

    /**
     * Get a single position by ID.
     *
     * @return ApiResponse<Position>
     */
    public function getPosition(int $id): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', "/positions/{$id}");

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydratePosition($item),
        );
    }

    // -----------------------------------------------------------------------
    // Members
    // -----------------------------------------------------------------------

    /**
     * List members with optional filtering and pagination.
     *
     * @return ApiListResponse<Member>
     */
    public function getMembers(MembersQuery $query = new MembersQuery()): ApiListResponse
    {
        $params = [
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];

        if ($query->search !== null) {
            $params['search'] = $query->search;
        }
        if ($query->homeGroupId !== null) {
            $params['home_group_id'] = $query->homeGroupId;
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', '/members', $params);

        return $this->buildListResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateMember($item),
        );
    }

    /**
     * Get a single member by ID.
     *
     * @return ApiResponse<Member>
     */
    public function getMember(int $id): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', "/members/{$id}");

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateMember($item),
        );
    }

    /**
     * Partially update a member (only supplied fields are changed).
     *
     * @return ApiResponse<Member>
     */
    public function updateMember(int $id, UpdateMemberRequest $updateRequest): ApiResponse
    {
        $payload = [];

        if ($updateRequest->anonymousName !== null) {
            $payload['anonymous_name'] = $updateRequest->anonymousName;
        }
        if ($updateRequest->personalEmail !== null) {
            $payload['personal_email'] = $updateRequest->personalEmail;
        }
        if ($updateRequest->mobileNumber !== null) {
            $payload['mobile_number'] = $updateRequest->mobileNumber;
        }
        if ($updateRequest->showAnonymousName !== null) {
            $payload['show_anonymous_name'] = $updateRequest->showAnonymousName;
        }
        if ($updateRequest->showMemberProfile !== null) {
            $payload['show_member_profile'] = $updateRequest->showMemberProfile;
        }
        if ($updateRequest->anonymousProfile !== null) {
            $payload['anonymous_profile'] = $updateRequest->anonymousProfile;
        }
        if ($updateRequest->homeGroupId !== null) {
            $payload['home_group_id'] = $updateRequest->homeGroupId;
        }
        if ($updateRequest->isGsr !== null) {
            $payload['is_gsr'] = $updateRequest->isGsr;
        }
        if ($updateRequest->intergroupPositionId !== null) {
            $payload['intergroup_position_id'] = $updateRequest->intergroupPositionId;
        }
        if ($updateRequest->intergroupPositionRotation !== null) {
            $payload['intergroup_position_rotation'] = $updateRequest->intergroupPositionRotation;
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('POST', "/members/{$id}/update", body: $payload);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateMember($item),
        );
    }

    /**
     * Create a new member.
     *
     * @return ApiResponse<Member>
     */
    public function createMember(CreateMemberRequest $createRequest): ApiResponse
    {
        $payload = ['anonymous_name' => $createRequest->anonymousName];

        if ($createRequest->personalEmail !== null) {
            $payload['personal_email'] = $createRequest->personalEmail;
        }
        if ($createRequest->mobileNumber !== null) {
            $payload['mobile_number'] = $createRequest->mobileNumber;
        }
        if ($createRequest->homeGroupId !== null) {
            $payload['home_group_id'] = $createRequest->homeGroupId;
        }
        if ($createRequest->isGsr !== null) {
            $payload['is_gsr'] = $createRequest->isGsr;
        }
        if ($createRequest->intergroupPositionId !== null) {
            $payload['intergroup_position_id'] = $createRequest->intergroupPositionId;
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('POST', '/members/create', body: $payload);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateMember($item),
        );
    }

    // -----------------------------------------------------------------------
    // Intergroup Meetings
    // -----------------------------------------------------------------------

    /**
     * List intergroup meetings with optional date filtering and pagination.
     *
     * @return ApiListResponse<IntergroupMeeting>
     */
    public function getIntergroupMeetings(
        IntergroupMeetingsQuery $query = new IntergroupMeetingsQuery(),
    ): ApiListResponse {
        $params = [
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];

        if ($query->dateFrom !== null) {
            $params['date_from'] = $query->dateFrom;
        }
        if ($query->dateTo !== null) {
            $params['date_to'] = $query->dateTo;
        }

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', '/intergroup-meetings', $params);

        return $this->buildListResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateIntergroupMeeting($item),
        );
    }

    /**
     * Get a single intergroup meeting by ID.
     *
     * @return ApiResponse<IntergroupMeeting>
     */
    public function getIntergroupMeeting(int $id): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('GET', "/intergroup-meetings/{$id}");

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $this->hydrateIntergroupMeeting($item),
        );
    }

    /**
     * Register a group as an attendee of an intergroup meeting.
     *
     * @return ApiResponse<array<string,mixed>>
     */
    public function registerGroup(int $meetingId, RegisterGroupRequest $req): ApiResponse
    {
        $payload = [
            'group_id' => $req->groupId,
            'gsr_name' => $req->gsrName,
            'member_id' => $req->memberId,
            'gsr_proxy' => $req->gsrProxy,
            'gsr_proxy_name' => $req->gsrProxyName,
        ];

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('POST', "/intergroup-meetings/{$meetingId}/register-group", body: $payload);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $item,
        );
    }

    /**
     * Unregister a group from an intergroup meeting.
     *
     * @return ApiResponse<array<string,mixed>>
     */
    public function unregisterGroup(int $meetingId, int $groupId): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('POST', "/intergroup-meetings/{$meetingId}/unregister-group", body: [
                'group_id' => $groupId,
            ]);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $item,
        );
    }

    /**
     * Register an officer as an attendee of an intergroup meeting.
     *
     * @return ApiResponse<array<string,mixed>>
     */
    public function registerOfficer(int $meetingId, RegisterOfficerRequest $req): ApiResponse
    {
        $payload = [
            'officer_id' => $req->officerId,
            'position_name' => $req->positionName,
            'officer_name' => $req->officerName,
        ];

        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('POST', "/intergroup-meetings/{$meetingId}/register-officer", body: $payload);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $item,
        );
    }

    /**
     * Unregister an officer from an intergroup meeting.
     *
     * @return ApiResponse<array<string,mixed>>
     */
    public function unregisterOfficer(int $meetingId, int $officerId): ApiResponse
    {
        ['body' => $body, 'status' => $status, 'headers' => $headers] =
            $this->request('POST', "/intergroup-meetings/{$meetingId}/unregister-officer", body: [
                'officer_id' => $officerId,
            ]);

        return $this->buildSingleResponse(
            $body,
            $status,
            $headers,
            fn (array $item) => $item,
        );
    }

    // -----------------------------------------------------------------------
    // HTTP layer
    // -----------------------------------------------------------------------

    /**
     * Execute an HTTP request and return the decoded body, status, and headers.
     *
     * @param  string               $method  GET | POST
     * @param  string               $path    API path without base, e.g. "/groups"
     * @param  array<string,mixed>  $query   Query-string parameters (GET)
     * @param  array<string,mixed>  $body    JSON body parameters (POST)
     * @return array{body: array<string,mixed>, status: int, headers: array<string,string>}
     * @throws IntegrityClientException
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
    ): array {
        $url = $this->baseUrl . self::API_PATH . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        // Lazy-initialise a persistent cURL handle for connection reuse
        if ($this->curlHandle === null) {
            $ch = curl_init();
            if ($ch === false) {
                throw new IntegrityClientException('Failed to initialise cURL');
            }
            $this->curlHandle = $ch;
        }

        $ch = $this->curlHandle;

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: IntegrityPHPClient/1.0',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            throw new IntegrityClientException(
                'cURL error: ' . curl_error($ch),
                curl_errno($ch),
            );
        }

        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode((string) $raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new IntegrityClientException(
                'Failed to decode JSON response: ' . $e->getMessage(),
                $httpStatus,
                previous: $e,
            );
        }

        return ['body' => $decoded, 'status' => $httpStatus, 'headers' => $responseHeaders];
    }

    // -----------------------------------------------------------------------
    // Response builders
    // -----------------------------------------------------------------------

    /**
     * @template T
     * @param  array<string,mixed>      $body
     * @param  array<string,string>     $headers
     * @param  callable(array): T       $hydrator
     * @return ApiResponse<T>
     */
    private function buildSingleResponse(
        array $body,
        int $status,
        array $headers,
        callable $hydrator,
    ): ApiResponse {
        $rateLimit = $this->extractRateLimit($headers);

        if (!($body['success'] ?? false)) {
            return new ApiResponse(
                success: false,
                data: null,
                errorCode: $body['error']['code'] ?? null,
                errorMessage: $body['error']['message'] ?? null,
                httpStatus: $status,
                rateLimit: $rateLimit,
            );
        }

        return new ApiResponse(
            success: true,
            data: $hydrator($body['data'] ?? []),
            errorCode: null,
            errorMessage: null,
            httpStatus: $status,
            rateLimit: $rateLimit,
        );
    }

    /**
     * @template T
     * @param  array<string,mixed>      $body
     * @param  array<string,string>     $headers
     * @param  callable(array): T       $hydrator
     * @return ApiListResponse<T>
     */
    private function buildListResponse(
        array $body,
        int $status,
        array $headers,
        callable $hydrator,
    ): ApiListResponse {
        $rateLimit = $this->extractRateLimit($headers);

        if (!($body['success'] ?? false)) {
            return new ApiListResponse(
                success: false,
                data: [],
                meta: null,
                errorCode: $body['error']['code'] ?? null,
                errorMessage: $body['error']['message'] ?? null,
                httpStatus: $status,
                rateLimit: $rateLimit,
            );
        }

        $items = array_map($hydrator, $body['data'] ?? []);

        $metaRaw = $body['meta'] ?? null;
        $meta = $metaRaw !== null ? new PaginationMeta(
            total: (int) ($metaRaw['total'] ?? 0),
            page: (int) ($metaRaw['page'] ?? 1),
            perPage: (int) ($metaRaw['per_page'] ?? count($items)),
            totalPages: (int) ($metaRaw['total_pages'] ?? 1),
        ) : null;

        return new ApiListResponse(
            success: true,
            data: $items,
            meta: $meta,
            errorCode: null,
            errorMessage: null,
            httpStatus: $status,
            rateLimit: $rateLimit,
        );
    }

    /**
     * @param array<string,string> $headers
     */
    private function extractRateLimit(array $headers): ?RateLimit
    {
        if (!isset($headers['x-ratelimit-limit'])) {
            return null;
        }

        return new RateLimit(
            limit: (int) ($headers['x-ratelimit-limit'] ?? 0),
            remaining: (int) ($headers['x-ratelimit-remaining'] ?? 0),
            reset: (int) ($headers['x-ratelimit-reset'] ?? 0),
        );
    }

    // -----------------------------------------------------------------------
    // Hydrators — convert raw API arrays to typed model objects
    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function hydrateGroup(array $data): Group
    {
        $contacts = array_map([$this, 'hydrateContact'], $data['contacts'] ?? []);

        $co = $data['contribution_options'] ?? [];
        $contributionOptions = new ContributionOptions(
            venmo: $co['venmo'] ?? null,
            paypal: $co['paypal'] ?? null,
            square: $co['square'] ?? null,
            hasOptions: (bool) ($co['has_options'] ?? false),
        );

        // The API returns either `meeting_ids` (int[]) or `meetings` (full objects)
        // depending on whether `expand=meetings` was requested.
        $rawMeetings = $data['meetings'] ?? $data['meeting_ids'] ?? [];
        $meetingIds = array_map(
            fn ($m) => is_array($m) ? $this->hydrateMeeting($m) : (int) $m,
            $rawMeetings,
        );

        return new Group(
            id: (int) ($data['id'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            phone: (string) ($data['phone'] ?? ''),
            website: (string) ($data['website'] ?? ''),
            link: (string) ($data['link'] ?? ''),
            notes: (string) ($data['notes'] ?? ''),
            groupEmail: (string) ($data['group_email'] ?? ''),
            districtId: isset($data['district_id']) ? (int) $data['district_id'] : null,
            lastContact: (string) ($data['last_contact'] ?? ''),
            meetingIds: $meetingIds,
            contacts: $contacts,
            contributionOptions: $contributionOptions,
            updated: (string) ($data['updated'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateMeeting(array $data): Meeting
    {
        $contacts = array_map([$this, 'hydrateContact'], $data['contacts'] ?? []);
        $location = isset($data['location']) && is_array($data['location'])
            ? $this->hydrateLocation($data['location'])
            : null;

        return new Meeting(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            slug: (string) ($data['slug'] ?? ''),
            location: $location,
            url: (string) ($data['url'] ?? ''),
            day: isset($data['day']) ? (int) $data['day'] : null,
            dayOfWeek: (string) ($data['day_of_week'] ?? ''),
            time: (string) ($data['time'] ?? ''),
            endTime: (string) ($data['end_time'] ?? ''),
            types: (array) ($data['types'] ?? []),
            state: (string) ($data['state'] ?? ''),
            isOnline: (bool) ($data['is_online'] ?? false),
            onlineLink: (string) ($data['online_link'] ?? ''),
            onlineNotes: (string) ($data['online_notes'] ?? ''),
            contacts: $contacts,
            meta: (array) ($data['meta'] ?? []),
            updated: (string) ($data['updated'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateLocation(array $data): Location
    {
        return new Location(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            address: (string) ($data['address'] ?? ''),
            city: (string) ($data['city'] ?? ''),
            state: (string) ($data['state'] ?? ''),
            postalCode: (string) ($data['postal_code'] ?? ''),
            country: (string) ($data['country'] ?? ''),
            region: (string) ($data['region'] ?? ''),
            notes: (string) ($data['notes'] ?? ''),
            link: (string) ($data['link'] ?? ''),
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            timezone: (string) ($data['timezone'] ?? ''),
            formattedAddress: (string) ($data['formatted_address'] ?? ''),
            updated: (string) ($data['updated'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateContact(array $data): Contact
    {
        return new Contact(
            name: (string) ($data['name'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            phone: (string) ($data['phone'] ?? ''),
            updated: (string) ($data['updated'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydratePosition(array $data): Position
    {
        return new Position(
            id: (int) ($data['id'] ?? 0),
            longName: (string) ($data['long_name'] ?? ''),
            shortDescription: (string) ($data['short_description'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            minimumSobriety: isset($data['minimum_sobriety']) ? (int) $data['minimum_sobriety'] : null,
            termYears: isset($data['term_years']) ? (int) $data['term_years'] : null,
            link: (string) ($data['link'] ?? ''),
            updated: (string) ($data['updated'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateMember(array $data): Member
    {
        return new Member(
            id: (int) ($data['id'] ?? 0),
            anonymousName: (string) ($data['anonymous_name'] ?? ''),
            personalEmail: (string) ($data['personal_email'] ?? ''),
            mobileNumber: (string) ($data['mobile_number'] ?? ''),
            showAnonymousName: (bool) ($data['show_anonymous_name'] ?? false),
            showMemberProfile: (bool) ($data['show_member_profile'] ?? false),
            anonymousProfile: (string) ($data['anonymous_profile'] ?? ''),
            homeGroupId: isset($data['home_group_id']) ? (int) $data['home_group_id'] : null,
            homeGroupName: (string) ($data['home_group_name'] ?? ''),
            isGsr: (bool) ($data['is_gsr'] ?? false),
            meetingPo: (string) ($data['meeting_po'] ?? ''),
            intergroupPositionId: isset($data['intergroup_position_id'])
                ? (int) $data['intergroup_position_id']
                : null,
            intergroupPositionName: (string) ($data['intergroup_position_name'] ?? ''),
            intergroupPositionRotation: (string) ($data['intergroup_position_rotation'] ?? ''),
            link: (string) ($data['link'] ?? ''),
            updated: (string) ($data['updated'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateIntergroupMeeting(array $data): IntergroupMeeting
    {
        $groupAttendees = array_map(
            fn (array $a) => new GroupAttendee(id: (int) $a['id'], name: (string) ($a['name'] ?? '')),
            $data['group_attendees'] ?? [],
        );

        $officersAttending = array_map(
            fn (array $a) => new OfficerAttendee(id: (int) $a['id'], name: (string) ($a['name'] ?? '')),
            $data['officers_attending'] ?? [],
        );

        return new IntergroupMeeting(
            id: (int) ($data['id'] ?? 0),
            title: (string) ($data['title'] ?? ''),
            date: (string) ($data['date'] ?? ''),
            groupAttendeeIds: array_map('intval', $data['group_attendee_ids'] ?? []),
            groupAttendees: $groupAttendees,
            officersAttendingIds: array_map('intval', $data['officers_attending_ids'] ?? []),
            officersAttending: $officersAttending,
            updated: (string) ($data['updated'] ?? ''),
        );
    }
}
