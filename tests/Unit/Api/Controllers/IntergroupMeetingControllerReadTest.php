<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use Unity\Core\Interfaces\Container;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Tests for IntergroupMeetingController's read handlers and transform.
 *
 * @covers \Integrity\Api\Controllers\IntergroupMeetingController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IntergroupMeetingControllerReadTest extends TestCase
{
    /** @var IntergroupMeetingRepository&\Mockery\MockInterface */
    private $repo;
    /** @var MemberRepository&\Mockery\MockInterface */
    private $memberRepo;
    /** @var IntergroupMeetingOfficerAttendanceRepository&\Mockery\MockInterface */
    private $officerRepo;

    private IntergroupMeetingController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(IntergroupMeetingRepository::class);
        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->officerRepo = Mockery::mock(IntergroupMeetingOfficerAttendanceRepository::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(IntergroupMeetingRepository::class)->andReturn($this->repo);
        $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo);
        $container->shouldReceive('get')->with(IntergroupMeetingOfficerAttendanceRepository::class)->andReturn($this->officerRepo);

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log');

        $this->controller = new IntergroupMeetingController($auditLogger);
    }

    private function request(array $params = []): object
    {
        return $this->createMockRequest(array_merge([
            'per_page' => 100,
            'page' => 1,
            'date_from' => null,
            'date_to' => null,
            '_integrity_start_time' => microtime(true),
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['intergroup_meetings:read']],
        ], $params));
    }

    /** @return IntergroupMeeting&\Mockery\MockInterface */
    private function meeting(int $id = 1, array $groups = [], array $officers = [])
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

    /**
     * @test
     */
    public function get_meetings_returns_a_paginated_list_with_no_attendees(): void
    {
        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->meeting(1)]);
        $this->repo->shouldReceive('count')->once()->andReturn(1);
        $this->officerRepo->shouldReceive('findByIntergroupMeeting')->with(1)->andReturn([]);

        $response = $this->controller->getIntergroupMeetings($this->request());

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'][0];
        $this->assertSame('July Intergroup', $data['title']);
        $this->assertSame('2026-07-01', $data['date']);
        $this->assertSame([], $data['group_attendees']);
        $this->assertSame(1, $response->get_data()['meta']['total']);
    }

    /**
     * @test
     */
    public function get_meetings_resolves_group_names_and_officer_records(): void
    {
        \WP_Mock::userFunction('get_the_title')->with(10)->andReturn('Group Ten');

        $officerRecord = Mockery::mock(\Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance::class);
        $officerRecord->shouldReceive('getOfficerId')->andReturn(7);
        $officerRecord->shouldReceive('getOfficerName')->andReturn('Carol C.');
        $officerRecord->shouldReceive('getPositionName')->andReturn('Chair');

        $meeting = $this->meeting(1, [10], [7]);
        $this->repo->shouldReceive('findAll')->once()->andReturn([$meeting]);
        $this->repo->shouldReceive('count')->once()->andReturn(1);
        $this->memberRepo->shouldReceive('findAll')->andReturn([]);
        $this->officerRepo->shouldReceive('findByIntergroupMeeting')->with(1)->andReturn([$officerRecord]);

        $response = $this->controller->getIntergroupMeetings($this->request());
        $data = $response->get_data()['data'][0];

        $this->assertSame([['id' => 10, 'name' => 'Group Ten']], $data['group_attendees']);
        $this->assertSame(7, $data['officers_attending'][0]['officer_id']);
        $this->assertSame('Chair', $data['officers_attending'][0]['position_name']);
    }

    /**
     * @test
     */
    public function get_meetings_applies_date_filters(): void
    {
        $this->repo->shouldReceive('findAll')->once()->andReturn([]);
        $this->repo->shouldReceive('count')->once()->andReturn(0);

        $response = $this->controller->getIntergroupMeetings($this->request([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $response->get_data()['meta']['total']);
    }

    /**
     * @test
     */
    public function get_meetings_returns_500_on_failure(): void
    {
        $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

        $this->assertSame(500, $this->controller->getIntergroupMeetings($this->request())->get_status());
    }

    /**
     * @test
     */
    public function get_meeting_returns_a_single_meeting(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(5)->andReturn($this->meeting(5));
        $this->officerRepo->shouldReceive('findByIntergroupMeeting')->with(5)->andReturn([]);

        $response = $this->controller->getIntergroupMeeting($this->request(['id' => 5]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(5, $response->get_data()['data']['id']);
    }

    /**
     * @test
     */
    public function get_meeting_returns_404_when_missing(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $this->assertSame(404, $this->controller->getIntergroupMeeting($this->request(['id' => 9]))->get_status());
    }
}
