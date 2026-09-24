<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use BleedingDeacons\WpMocks\WpState;
use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Auth\AuditLogger;
use Mockery;
use Mockery\MockInterface;
use Unity\Core\Interfaces\Container;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Plugin as UnityPlugin;

/*
 * Tests for IntergroupMeetingController's read handlers and transform.
 *
 * The controller reaches its repositories through Unity\Plugin::getContainer(),
 * so each test installs a Plugin around a container double with Unity's own
 * Plugin::setInstance(), and clears it afterwards. This used to be an alias
 * mock of Unity\Plugin, which defines the class for the rest of the process
 * and so forced every test into a process of its own.
 */

covers(IntergroupMeetingController::class, ControllerTrait::class);

/** @return IntergroupMeeting&MockInterface */
function intergroupReadMeetingDouble(int $id = 1, array $groups = [], array $officers = [])
{
    $m = Mockery::mock(IntergroupMeeting::class);
    $m->shouldReceive('getId')->andReturn($id);
    $m->shouldReceive('getTitle')->andReturn('July Intergroup');
    $m->shouldReceive('getDate')->andReturn('2026-07-01');
    $m->shouldReceive('getGroupAttendees')->andReturn($groups);
    $m->shouldReceive('getOfficersAttending')->andReturn($officers);
    $m->shouldReceive('getUpdated')->andReturn('2026-07-01 20:00:00');
    return $m;
}

beforeEach(function () {
    $this->repo = Mockery::mock(IntergroupMeetingRepository::class);
    $this->memberRepo = Mockery::mock(MemberRepository::class);
    $this->officerRepo = Mockery::mock(IntergroupMeetingOfficerAttendanceRepository::class);

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('get')->with(IntergroupMeetingRepository::class)->andReturn($this->repo);
    $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo);
    $container->shouldReceive('get')->with(IntergroupMeetingOfficerAttendanceRepository::class)->andReturn($this->officerRepo);

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log');

    $this->controller = new IntergroupMeetingController($auditLogger);

    $this->makeRequest = fn (array $params = []): object => $this->createMockRequest(array_merge([
        'per_page' => 100,
        'page' => 1,
        'date_from' => null,
        'date_to' => null,
        '_integrity_start_time' => microtime(true),
        '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['intergroup_meetings:read']],
    ], $params));
});

afterEach(function () {
    UnityPlugin::setInstance(null);
});

it('returns a paginated list of meetings with no attendees', function () {
    $this->repo->shouldReceive('findAll')->once()->andReturn([intergroupReadMeetingDouble(1)]);
    $this->repo->shouldReceive('count')->once()->andReturn(1);
    $this->officerRepo->shouldReceive('findByIntergroupMeeting')->with(1)->andReturn([]);

    $response = $this->controller->getIntergroupMeetings(($this->makeRequest)());

    expect($response->get_status())->toBe(200);
    $data = $response->get_data()['data'][0];
    expect($data['title'])->toBe('July Intergroup')
        ->and($data['date'])->toBe('2026-07-01')
        ->and($data['group_attendees'])->toBe([])
        ->and($response->get_data()['meta']['total'])->toBe(1);
});

it('resolves group names and officer records', function () {
    WpState::addPost(10, ['post_title' => 'Group Ten']);

    $officerRecord = Mockery::mock(IntergroupMeetingOfficerAttendance::class);
    $officerRecord->shouldReceive('getOfficerId')->andReturn(7);
    $officerRecord->shouldReceive('getOfficerName')->andReturn('Carol C.');
    $officerRecord->shouldReceive('getPositionName')->andReturn('Chair');

    $meeting = intergroupReadMeetingDouble(1, [10], [7]);
    $this->repo->shouldReceive('findAll')->once()->andReturn([$meeting]);
    $this->repo->shouldReceive('count')->once()->andReturn(1);
    $this->memberRepo->shouldReceive('findAll')->andReturn([]);
    $this->officerRepo->shouldReceive('findByIntergroupMeeting')->with(1)->andReturn([$officerRecord]);

    $response = $this->controller->getIntergroupMeetings(($this->makeRequest)());
    $data = $response->get_data()['data'][0];

    expect($data['group_attendees'])->toBe([['id' => 10, 'name' => 'Group Ten']])
        ->and($data['officers_attending'][0]['officer_id'])->toBe(7)
        ->and($data['officers_attending'][0]['position_name'])->toBe('Chair');
});

it('applies date filters', function () {
    $this->repo->shouldReceive('findAll')->once()->andReturn([]);
    $this->repo->shouldReceive('count')->once()->andReturn(0);

    $response = $this->controller->getIntergroupMeetings(($this->makeRequest)([
        'date_from' => '2026-01-01',
        'date_to' => '2026-12-31',
    ]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['meta']['total'])->toBe(0);
});

it('returns 500 when listing meetings fails', function () {
    $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

    expect($this->controller->getIntergroupMeetings(($this->makeRequest)())->get_status())->toBe(500);
});

it('returns a single meeting', function () {
    $this->repo->shouldReceive('findById')->once()->with(5)->andReturn(intergroupReadMeetingDouble(5));
    $this->officerRepo->shouldReceive('findByIntergroupMeeting')->with(5)->andReturn([]);

    $response = $this->controller->getIntergroupMeeting(($this->makeRequest)(['id' => 5]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['data']['id'])->toBe(5);
});

it('returns 404 when the meeting is missing', function () {
    $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

    expect($this->controller->getIntergroupMeeting(($this->makeRequest)(['id' => 9]))->get_status())->toBe(404);
});
