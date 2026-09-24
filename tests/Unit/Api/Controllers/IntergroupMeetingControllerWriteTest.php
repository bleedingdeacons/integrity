<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Auth\AuditLogger;
use Mockery;
use Mockery\MockInterface;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Plugin as UnityPlugin;
use Unity\Positions\Interfaces\PositionViewFactory;

/*
 * Tests for IntergroupMeetingController's write handlers: registering and
 * unregistering group attendees and officers. Each endpoint is driven through
 * its happy path plus the not-found / conflict / save-failure / exception
 * branches, with the Unity repositories and factories supplied by a mocked
 * container.
 *
 * The controller reaches that container through Unity\Plugin::getContainer(),
 * so each test installs a Plugin around it with Unity's own
 * Plugin::setInstance(), and clears it afterwards. This used to be an alias
 * mock of Unity\Plugin, which defines the class for the rest of the process
 * and so forced every test into a process of its own. The global $wpdb set
 * below is restored afterwards for the same reason: nothing isolates it now.
 */

covers(IntergroupMeetingController::class, ControllerTrait::class);

const INTERGROUP_WRITE_GROUP_FACTORY = 'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingGroupAttendanceFactory';
const INTERGROUP_WRITE_OFFICER_FACTORY = 'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingOfficerAttendanceFactory';

/** @return IntergroupMeeting&MockInterface */
function intergroupWriteMeetingDouble()
{
    $m = Mockery::mock(IntergroupMeeting::class);
    $m->shouldReceive('getId')->andReturn(1)->byDefault();
    $m->shouldReceive('getTitle')->andReturn('July Intergroup')->byDefault();
    $m->shouldReceive('getDate')->andReturn('2026-07-01')->byDefault();
    $m->shouldReceive('addGroupAttendee')->byDefault();
    $m->shouldReceive('removeGroupAttendee')->byDefault();
    $m->shouldReceive('addOfficerAttendee')->byDefault();
    $m->shouldReceive('hasGroupAttendee')->andReturn(true)->byDefault();
    $m->shouldReceive('hasOfficerAttendee')->andReturn(true)->byDefault();
    return $m;
}

/** @return Member&MockInterface */
function intergroupWriteOfficerDouble(int $positionId = 5)
{
    $m = Mockery::mock(Member::class);
    $m->shouldReceive('getIntergroupPosition')->andReturn($positionId)->byDefault();
    return $m;
}

beforeEach(function () {
    $this->repo = Mockery::mock(IntergroupMeetingRepository::class);
    $this->groupRepo = Mockery::mock(GroupRepository::class);
    $this->memberRepo = Mockery::mock(MemberRepository::class);
    $this->groupAttendanceRepo = Mockery::mock(IntergroupMeetingGroupAttendanceRepository::class);
    $this->officerAttendanceRepo = Mockery::mock(IntergroupMeetingOfficerAttendanceRepository::class);
    $this->groupAttendanceFactory = Mockery::mock(INTERGROUP_WRITE_GROUP_FACTORY);
    $this->officerAttendanceFactory = Mockery::mock(INTERGROUP_WRITE_OFFICER_FACTORY);
    $this->groupViewFactory = Mockery::mock(GroupViewFactory::class);
    $this->positionViewFactory = Mockery::mock(PositionViewFactory::class);

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('get')->with(IntergroupMeetingRepository::class)->andReturn($this->repo)->byDefault();
    $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->groupRepo)->byDefault();
    $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo)->byDefault();
    $container->shouldReceive('get')->with(IntergroupMeetingGroupAttendanceRepository::class)->andReturn($this->groupAttendanceRepo)->byDefault();
    $container->shouldReceive('get')->with(IntergroupMeetingOfficerAttendanceRepository::class)->andReturn($this->officerAttendanceRepo)->byDefault();
    $container->shouldReceive('get')->with(INTERGROUP_WRITE_GROUP_FACTORY)->andReturn($this->groupAttendanceFactory)->byDefault();
    $container->shouldReceive('get')->with(INTERGROUP_WRITE_OFFICER_FACTORY)->andReturn($this->officerAttendanceFactory)->byDefault();
    $container->shouldReceive('get')->with(GroupViewFactory::class)->andReturn($this->groupViewFactory)->byDefault();
    $container->shouldReceive('get')->with(PositionViewFactory::class)->andReturn($this->positionViewFactory)->byDefault();

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log')->byDefault();

    $this->controller = new IntergroupMeetingController($auditLogger);

    // buildMeetingLabel and the duplicate-detection branch read a global wpdb.
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = (object) ['last_error' => ''];

    $this->makeRequest = fn (array $params = []): object => $this->createMockRequest(array_merge([
        'id' => 1,
        'group_id' => 10,
        'member_id' => 20,
        'officer_id' => 30,
        'gsr_name' => 'Alex',
        'gsr_proxy' => false,
        'gsr_proxy_name' => '',
        'position_name' => 'Chair',
        'officer_name' => 'Sam',
        '_integrity_start_time' => microtime(true),
        '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['intergroup_meetings:write']],
    ], $params));
});

afterEach(function () {
    UnityPlugin::setInstance(null);
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

// ─── register attendee ───────────────────────────────────────────
describe('register attendee', function () {
    it('returns 201 on the happy path', function () {
        $meeting = intergroupWriteMeetingDouble();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->with(1, 10)->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock(IntergroupMeetingGroupAttendance::class));
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(true);
        $this->repo->shouldReceive('save')->with($meeting)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());

        expect($response->get_status())->toBe(201);
    });

    it('returns 404 when the meeting is missing', function () {
        $this->repo->shouldReceive('findById')->with(1)->andReturn(null);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 404 when the group is missing', function () {
        $this->repo->shouldReceive('findById')->with(1)->andReturn(intergroupWriteMeetingDouble());
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn(null);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 409 when already registered', function () {
        $this->repo->shouldReceive('findById')->with(1)->andReturn(intergroupWriteMeetingDouble());
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->with(1, 10)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(409);
    });

    it('returns 500 when the attendance save fails', function () {
        $this->repo->shouldReceive('findById')->with(1)->andReturn(intergroupWriteMeetingDouble());
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock(IntergroupMeetingGroupAttendance::class));
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });

    it('returns 409 on a duplicate-entry race', function () {
        $GLOBALS['wpdb']->last_error = 'Duplicate entry for key';
        $this->repo->shouldReceive('findById')->with(1)->andReturn(intergroupWriteMeetingDouble());
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock(IntergroupMeetingGroupAttendance::class));
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(409);
    });

    it('returns 500 when the meeting save fails', function () {
        $meeting = intergroupWriteMeetingDouble();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock(IntergroupMeetingGroupAttendance::class));
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(true);
        $this->repo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });

    it('returns 500 on an unexpected exception', function () {
        $this->repo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));

        $response = $this->controller->registerIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });
});

// ─── unregister attendee ─────────────────────────────────────────
describe('unregister attendee', function () {
    it('returns 200 on the happy path', function () {
        $meeting = intergroupWriteMeetingDouble();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $this->repo->shouldReceive('save')->with($meeting)->andReturn(true);
        $this->groupAttendanceRepo->shouldReceive('deleteByIntergroupMeetingAndGroup')->with(1, 10);

        $response = $this->controller->unregisterIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(200);
    });

    it('returns 404 when the meeting is missing', function () {
        $this->repo->shouldReceive('findById')->andReturn(null);
        $response = $this->controller->unregisterIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 404 when not registered', function () {
        $meeting = intergroupWriteMeetingDouble();
        $meeting->shouldReceive('hasGroupAttendee')->with(10)->andReturn(false);
        $this->repo->shouldReceive('findById')->andReturn($meeting);

        $response = $this->controller->unregisterIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 500 when the save fails', function () {
        $meeting = intergroupWriteMeetingDouble();
        $this->repo->shouldReceive('findById')->andReturn($meeting);
        $this->repo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->unregisterIntergroupMeetingAttendee(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });
});

// ─── register officer ────────────────────────────────────────────
describe('register officer', function () {
    it('returns 201 on the happy path', function () {
        $meeting = intergroupWriteMeetingDouble();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn(intergroupWriteOfficerDouble(5));
        $this->positionViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->officerAttendanceRepo->shouldReceive('existsForMeetingAndOfficer')->with(1, 5)->andReturn(false);
        $this->officerAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock(IntergroupMeetingOfficerAttendance::class));
        $this->officerAttendanceRepo->shouldReceive('save')->andReturn(true);
        $this->repo->shouldReceive('save')->with($meeting)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(201);
    });

    it('returns 404 when the meeting is missing', function () {
        $this->repo->shouldReceive('findById')->andReturn(null);
        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 404 when the officer is missing', function () {
        $this->repo->shouldReceive('findById')->andReturn(intergroupWriteMeetingDouble());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn(null);

        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 422 without an intergroup position', function () {
        $this->repo->shouldReceive('findById')->andReturn(intergroupWriteMeetingDouble());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn(intergroupWriteOfficerDouble(0));

        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(422);
    });

    it('returns 409 when already registered', function () {
        $this->repo->shouldReceive('findById')->andReturn(intergroupWriteMeetingDouble());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn(intergroupWriteOfficerDouble(5));
        $this->positionViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->officerAttendanceRepo->shouldReceive('existsForMeetingAndOfficer')->with(1, 5)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(409);
    });

    it('returns 500 when the attendance save fails', function () {
        $this->repo->shouldReceive('findById')->andReturn(intergroupWriteMeetingDouble());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn(intergroupWriteOfficerDouble(5));
        $this->positionViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->officerAttendanceRepo->shouldReceive('existsForMeetingAndOfficer')->andReturn(false);
        $this->officerAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock(IntergroupMeetingOfficerAttendance::class));
        $this->officerAttendanceRepo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });

    it('returns 500 on an exception', function () {
        $this->repo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $response = $this->controller->registerIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });
});

// ─── unregister officer ──────────────────────────────────────────
describe('unregister officer', function () {
    it('returns 404 when the meeting is missing', function () {
        $this->repo->shouldReceive('findById')->andReturn(null);
        $response = $this->controller->unregisterIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(404);
    });

    it('returns 500 on an exception', function () {
        $this->repo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $response = $this->controller->unregisterIntergroupMeetingOfficer(($this->makeRequest)());
        expect($response->get_status())->toBe(500);
    });
});
