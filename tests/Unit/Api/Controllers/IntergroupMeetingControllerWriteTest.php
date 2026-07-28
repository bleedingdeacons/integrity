<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\PositionViewFactory;

/**
 * Tests for IntergroupMeetingController's write handlers: registering and
 * unregistering group attendees and officers. Each endpoint is driven through
 * its happy path plus the not-found / conflict / save-failure / exception
 * branches, with the Unity repositories and factories supplied by a mocked
 * container.
 *
 * @covers \Integrity\Api\Controllers\IntergroupMeetingController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IntergroupMeetingControllerWriteTest extends TestCase
{
    private $repo;
    private $groupRepo;
    private $memberRepo;
    private $groupAttendanceRepo;
    private $officerAttendanceRepo;
    private $groupAttendanceFactory;
    private $officerAttendanceFactory;
    private $groupViewFactory;
    private $positionViewFactory;

    private IntergroupMeetingController $controller;

    private const GROUP_FACTORY = 'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingGroupAttendanceFactory';
    private const OFFICER_FACTORY = 'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingOfficerAttendanceFactory';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(IntergroupMeetingRepository::class);
        $this->groupRepo = Mockery::mock(GroupRepository::class);
        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->groupAttendanceRepo = Mockery::mock(IntergroupMeetingGroupAttendanceRepository::class);
        $this->officerAttendanceRepo = Mockery::mock(IntergroupMeetingOfficerAttendanceRepository::class);
        $this->groupAttendanceFactory = Mockery::mock(self::GROUP_FACTORY);
        $this->officerAttendanceFactory = Mockery::mock(self::OFFICER_FACTORY);
        $this->groupViewFactory = Mockery::mock(GroupViewFactory::class);
        $this->positionViewFactory = Mockery::mock(PositionViewFactory::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(IntergroupMeetingRepository::class)->andReturn($this->repo)->byDefault();
        $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->groupRepo)->byDefault();
        $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo)->byDefault();
        $container->shouldReceive('get')->with(IntergroupMeetingGroupAttendanceRepository::class)->andReturn($this->groupAttendanceRepo)->byDefault();
        $container->shouldReceive('get')->with(IntergroupMeetingOfficerAttendanceRepository::class)->andReturn($this->officerAttendanceRepo)->byDefault();
        $container->shouldReceive('get')->with(self::GROUP_FACTORY)->andReturn($this->groupAttendanceFactory)->byDefault();
        $container->shouldReceive('get')->with(self::OFFICER_FACTORY)->andReturn($this->officerAttendanceFactory)->byDefault();
        $container->shouldReceive('get')->with(GroupViewFactory::class)->andReturn($this->groupViewFactory)->byDefault();
        $container->shouldReceive('get')->with(PositionViewFactory::class)->andReturn($this->positionViewFactory)->byDefault();

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log')->byDefault();

        $this->controller = new IntergroupMeetingController($auditLogger);

        // buildMeetingLabel and the duplicate-detection branch read a global wpdb.
        $GLOBALS['wpdb'] = (object) ['last_error' => ''];
    }

    private function request(array $params = []): object
    {
        return $this->createMockRequest(array_merge([
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
    }

    /** @return IntergroupMeeting&\Mockery\MockInterface */
    private function meeting()
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

    // ─── register attendee ───────────────────────────────────────────

    /** @test */
    public function register_attendee_happy_path_returns_201(): void
    {
        $meeting = $this->meeting();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->with(1, 10)->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock());
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(true);
        $this->repo->shouldReceive('save')->with($meeting)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());

        $this->assertSame(201, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_404_when_meeting_missing(): void
    {
        $this->repo->shouldReceive('findById')->with(1)->andReturn(null);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_404_when_group_missing(): void
    {
        $this->repo->shouldReceive('findById')->with(1)->andReturn($this->meeting());
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn(null);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_409_when_already_registered(): void
    {
        $this->repo->shouldReceive('findById')->with(1)->andReturn($this->meeting());
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->with(1, 10)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(409, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_500_when_attendance_save_fails(): void
    {
        $this->repo->shouldReceive('findById')->with(1)->andReturn($this->meeting());
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock());
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(500, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_409_on_duplicate_entry_race(): void
    {
        $GLOBALS['wpdb']->last_error = 'Duplicate entry for key';
        $this->repo->shouldReceive('findById')->with(1)->andReturn($this->meeting());
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock());
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(409, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_500_when_meeting_save_fails(): void
    {
        $meeting = $this->meeting();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getTitle')->andReturn('Tuesday Group');
        $this->groupRepo->shouldReceive('findById')->with(10)->andReturn($group);
        $this->groupViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->groupAttendanceRepo->shouldReceive('existsForMeetingAndGroup')->andReturn(false);
        $this->groupAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock());
        $this->groupAttendanceRepo->shouldReceive('save')->andReturn(true);
        $this->repo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(500, $response->get_status());
    }

    /** @test */
    public function register_attendee_returns_500_on_unexpected_exception(): void
    {
        $this->repo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));

        $response = $this->controller->registerIntergroupMeetingAttendee($this->request());
        $this->assertSame(500, $response->get_status());
    }

    // ─── unregister attendee ─────────────────────────────────────────

    /** @test */
    public function unregister_attendee_happy_path_returns_200(): void
    {
        $meeting = $this->meeting();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $this->repo->shouldReceive('save')->with($meeting)->andReturn(true);
        $this->groupAttendanceRepo->shouldReceive('deleteByIntergroupMeetingAndGroup')->with(1, 10);

        $response = $this->controller->unregisterIntergroupMeetingAttendee($this->request());
        $this->assertSame(200, $response->get_status());
    }

    /** @test */
    public function unregister_attendee_returns_404_when_meeting_missing(): void
    {
        $this->repo->shouldReceive('findById')->andReturn(null);
        $response = $this->controller->unregisterIntergroupMeetingAttendee($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function unregister_attendee_returns_404_when_not_registered(): void
    {
        $meeting = $this->meeting();
        $meeting->shouldReceive('hasGroupAttendee')->with(10)->andReturn(false);
        $this->repo->shouldReceive('findById')->andReturn($meeting);

        $response = $this->controller->unregisterIntergroupMeetingAttendee($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function unregister_attendee_returns_500_when_save_fails(): void
    {
        $meeting = $this->meeting();
        $this->repo->shouldReceive('findById')->andReturn($meeting);
        $this->repo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->unregisterIntergroupMeetingAttendee($this->request());
        $this->assertSame(500, $response->get_status());
    }

    // ─── register officer ────────────────────────────────────────────

    /** @return Member&\Mockery\MockInterface */
    private function officer(int $positionId = 5)
    {
        $m = Mockery::mock(Member::class);
        $m->shouldReceive('getIntergroupPosition')->andReturn($positionId)->byDefault();
        return $m;
    }

    /** @test */
    public function register_officer_happy_path_returns_201(): void
    {
        $meeting = $this->meeting();
        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn($this->officer(5));
        $this->positionViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->officerAttendanceRepo->shouldReceive('existsForMeetingAndOfficer')->with(1, 5)->andReturn(false);
        $this->officerAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock());
        $this->officerAttendanceRepo->shouldReceive('save')->andReturn(true);
        $this->repo->shouldReceive('save')->with($meeting)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(201, $response->get_status());
    }

    /** @test */
    public function register_officer_returns_404_when_meeting_missing(): void
    {
        $this->repo->shouldReceive('findById')->andReturn(null);
        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function register_officer_returns_404_when_officer_missing(): void
    {
        $this->repo->shouldReceive('findById')->andReturn($this->meeting());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn(null);

        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function register_officer_returns_422_without_an_intergroup_position(): void
    {
        $this->repo->shouldReceive('findById')->andReturn($this->meeting());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn($this->officer(0));

        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(422, $response->get_status());
    }

    /** @test */
    public function register_officer_returns_409_when_already_registered(): void
    {
        $this->repo->shouldReceive('findById')->andReturn($this->meeting());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn($this->officer(5));
        $this->positionViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->officerAttendanceRepo->shouldReceive('existsForMeetingAndOfficer')->with(1, 5)->andReturn(true);

        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(409, $response->get_status());
    }

    /** @test */
    public function register_officer_returns_500_when_attendance_save_fails(): void
    {
        $this->repo->shouldReceive('findById')->andReturn($this->meeting());
        $this->memberRepo->shouldReceive('findById')->with(30)->andReturn($this->officer(5));
        $this->positionViewFactory->shouldReceive('createFrom')->andReturn(null);
        $this->officerAttendanceRepo->shouldReceive('existsForMeetingAndOfficer')->andReturn(false);
        $this->officerAttendanceFactory->shouldReceive('createNew')->andReturn(Mockery::mock());
        $this->officerAttendanceRepo->shouldReceive('save')->andReturn(false);

        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(500, $response->get_status());
    }

    /** @test */
    public function register_officer_returns_500_on_exception(): void
    {
        $this->repo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $response = $this->controller->registerIntergroupMeetingOfficer($this->request());
        $this->assertSame(500, $response->get_status());
    }

    // ─── unregister officer ──────────────────────────────────────────

    /** @test */
    public function unregister_officer_returns_404_when_meeting_missing(): void
    {
        $this->repo->shouldReceive('findById')->andReturn(null);
        $response = $this->controller->unregisterIntergroupMeetingOfficer($this->request());
        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function unregister_officer_returns_500_on_exception(): void
    {
        $this->repo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $response = $this->controller->unregisterIntergroupMeetingOfficer($this->request());
        $this->assertSame(500, $response->get_status());
    }
}
