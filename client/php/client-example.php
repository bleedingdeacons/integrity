<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrityClient.php';

// ---------------------------------------------------------------------------
// Initialise the client
// ---------------------------------------------------------------------------

$client = new IntegrityClient(
    baseUrl: 'https://your-wordpress-site.com',
    apiKey: 'int_your_api_key_here',
    timeout: 30,
);

// ---------------------------------------------------------------------------
// Health check — no auth required
// ---------------------------------------------------------------------------

$health = $client->checkHealth();
echo "Status : {$health->data->status}\n";
echo "Version: {$health->data->version}\n";
echo "Unity  : " . ($health->data->unityAvailable ? 'available' : 'unavailable') . "\n\n";

// ---------------------------------------------------------------------------
// Groups
// ---------------------------------------------------------------------------

// List all groups
$groups = $client->getGroups(new GroupsQuery(page: 1, perPage: 50));

if ($groups->success) {
    echo "Groups ({$groups->meta->total} total):\n";
    foreach ($groups->data as $group) {
        echo "  [{$group->id}] {$group->title}";
        echo " — " . count($group->meetingIds) . " meetings\n";
    }
}

// List groups filtered by district, with full meeting objects expanded
$districtGroups = $client->getGroups(new GroupsQuery(
    districtId: 3,
    expandMeetings: true,
));

// Get a single group
$groupResp = $client->getGroup(id: 42, expandMeetings: false);
if ($groupResp->success) {
    $group = $groupResp->data;
    echo "Group: {$group->title} ({$group->email})\n";
    echo "Venmo: " . ($group->contributionOptions->venmo ?? '—') . "\n";
}

// Rate-limit awareness
if ($groups->rateLimit !== null) {
    echo "Rate limit remaining: {$groups->rateLimit->remaining}/{$groups->rateLimit->limit}\n";
    echo "Resets at: " . $groups->rateLimit->resetDateTime()->format('H:i:s') . "\n";
}

// ---------------------------------------------------------------------------
// Meetings
// ---------------------------------------------------------------------------

// All Monday (day=1) online meetings
$meetings = $client->getMeetings(new MeetingsQuery(
    day: 1,
    online: true,
    perPage: 50,
));

if ($meetings->success) {
    echo "\nOnline Monday meetings:\n";
    foreach ($meetings->data as $meeting) {
        echo "  {$meeting->name} at {$meeting->time}";
        if ($meeting->location !== null) {
            echo " — {$meeting->location->city}";
        }
        echo "\n";
    }
}

// Get a single meeting
$meetingResp = $client->getMeeting(123);
if ($meetingResp->success) {
    $m = $meetingResp->data;
    echo "Meeting: {$m->name} ({$m->dayOfWeek} {$m->time})\n";
}

// ---------------------------------------------------------------------------
// Positions
// ---------------------------------------------------------------------------

$positions = $client->getPositions(new PositionsQuery(search: 'secretary'));

foreach ($positions->data as $pos) {
    echo "Position: {$pos->longName}";
    if ($pos->termYears !== null) {
        echo " ({$pos->termYears}-year term)";
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// Members
// ---------------------------------------------------------------------------

// List all GSRs in a specific home group
$members = $client->getMembers(new MembersQuery(homeGroupId: 42));

foreach ($members->data as $member) {
    echo "Member: {$member->anonymousName}";
    if ($member->isGsr) {
        echo " [GSR]";
    }
    if ($member->intergroupPositionName !== '') {
        echo " — {$member->intergroupPositionName}";
    }
    echo "\n";
}

// Create a new member
$newMemberResp = $client->createMember(new CreateMemberRequest(
    anonymousName: 'Alex A.',
    personalEmail: 'alex@example.com',
    mobileNumber: '+1-555-0100',
    homeGroupId: 42,
    isGsr: true,
));

if ($newMemberResp->success) {
    echo "Created member #{$newMemberResp->data->id}: {$newMemberResp->data->anonymousName}\n";
}

// Partially update an existing member (only supplied fields change)
$updatedResp = $client->updateMember(id: 101, updateRequest: new UpdateMemberRequest(
    anonymousName: 'Alex B.',
    isGsr: false,
    intergroupPositionId: 5,
    intergroupPositionRotation: '2025-01-01',
));

if ($updatedResp->success) {
    echo "Updated: {$updatedResp->data->anonymousName}\n";
} else {
    echo "Error ({$updatedResp->errorCode}): {$updatedResp->errorMessage}\n";
}

// ---------------------------------------------------------------------------
// Intergroup Meetings
// ---------------------------------------------------------------------------

// List upcoming intergroup meetings in a date range
$igMeetings = $client->getIntergroupMeetings(new IntergroupMeetingsQuery(
    dateFrom: '2025-01-01',
    dateTo: '2025-12-31',
));

foreach ($igMeetings->data as $igm) {
    echo "IG Meeting: {$igm->title} on {$igm->date}";
    echo " — " . count($igm->groupAttendees) . " groups attending\n";
}

// Get a single intergroup meeting
$igmResp = $client->getIntergroupMeeting(7);
if ($igmResp->success) {
    $igm = $igmResp->data;
    echo "Groups attending '{$igm->title}':\n";
    foreach ($igm->groupAttendees as $attendee) {
        echo "  [{$attendee->id}] {$attendee->name}\n";
    }
    echo "Officers attending:\n";
    foreach ($igm->officersAttending as $officer) {
        echo "  [{$officer->id}] {$officer->name}\n";
    }
}

// Register a group
$regResp = $client->registerGroup(meetingId: 7, req: new RegisterGroupRequest(
    groupId: 42,
    gsrName: 'Alex A.',
    memberId: 101,
    gsrProxy: false,
));
echo $regResp->success ? "Group registered!\n" : "Error: {$regResp->errorMessage}\n";

// Unregister a group
$unregResp = $client->unregisterGroup(meetingId: 7, groupId: 42);
echo $unregResp->success ? "Group unregistered!\n" : "Error: {$unregResp->errorMessage}\n";

// Register an officer
$officerResp = $client->registerOfficer(meetingId: 7, req: new RegisterOfficerRequest(
    officerId: 101,
    positionName: 'Chairperson',
    officerName: 'Alex A.',
));
echo $officerResp->success ? "Officer registered!\n" : "Error: {$officerResp->errorMessage}\n";

// Unregister an officer
$client->unregisterOfficer(meetingId: 7, officerId: 101);

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

try {
    $resp = $client->getMeeting(999999);

    if (!$resp->success) {
        // Structured error from the API
        echo "API error [{$resp->errorCode}]: {$resp->errorMessage}\n";

        match ($resp->errorCode) {
            'not_found'           => print("Resource does not exist.\n"),
            'invalid_api_key'     => print("Check your API key.\n"),
            'rate_limit_exceeded' => print("Slow down! Retry after: " .
                ($resp->rateLimit?->resetDateTime()->format('H:i:s') ?? '?') . "\n"),
            default               => print("Unhandled error.\n"),
        };
    }
} catch (IntegrityClientException $e) {
    // Network-level or JSON decode failures
    echo "Client exception: {$e->getMessage()} (HTTP {$e->httpStatus})\n";
}

// Always close when done (releases the cURL handle)
$client->close();
