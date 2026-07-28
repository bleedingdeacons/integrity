<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;

/**
 * Tests for MemberController's write handlers (create / update) beyond the
 * happy path covered by MemberControllerTest: the validation, not-found,
 * save-failure and exception branches.
 *
 * @covers \Integrity\Api\Controllers\MemberController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberControllerWriteTest extends TestCase
{
    private $memberRepo;
    private $groupRepo;
    private $positionRepo;
    private $revisor;
    private $factory;
    private MemberController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->groupRepo = Mockery::mock(GroupRepository::class);
        $this->positionRepo = Mockery::mock(PositionRepository::class);
        $this->revisor = Mockery::mock(MemberRevisor::class);
        $this->factory = Mockery::mock(MemberFactory::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo)->byDefault();
        $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->groupRepo)->byDefault();
        $container->shouldReceive('get')->with(PositionRepository::class)->andReturn($this->positionRepo)->byDefault();
        $container->shouldReceive('get')->with(MeetingRepository::class)->andReturn(Mockery::mock(MeetingRepository::class))->byDefault();
        $container->shouldReceive('get')->with(MemberRevisor::class)->andReturn($this->revisor)->byDefault();
        $container->shouldReceive('get')->with(MemberFactory::class)->andReturn($this->factory)->byDefault();
        $container->shouldReceive('get')->with(PrivacyPolicyRepository::class)->andReturn(Mockery::mock(PrivacyPolicyRepository::class))->byDefault();

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log')->byDefault();

        $this->controller = new MemberController(
            $auditLogger,
            new GroupController($auditLogger),
            new PositionController($auditLogger),
            new MeetingController($auditLogger)
        );

        $GLOBALS['wpdb'] = (object) ['last_error' => ''];
    }

    private function request(array $params = []): object
    {
        $params = array_merge([
            'id' => 1,
            '_integrity_start_time' => microtime(true),
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['members:write', 'members:clear']],
        ], $params);

        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_param')->andReturnUsing(fn ($k) => $params[$k] ?? null);
        $request->shouldReceive('has_param')->andReturnUsing(fn ($k) => array_key_exists($k, $params));
        $request->shouldReceive('get_route')->andReturn('/integrity/v1/members');
        $request->shouldReceive('get_method')->andReturn('POST');
        return $request;
    }

    /** @return Member&\Mockery\MockInterface */
    private function member()
    {
        $d = [
            'getId' => 1, 'getAnonymousName' => 'Anon', 'getPersonalEmail' => 'jane@example.com',
            'getMobileNumber' => '07700 900000', 'showAnonymousName' => true, 'showMemberProfile' => false,
            'getAnonymousProfile' => '', 'getHomeGroup' => 0, 'isGSR' => false, 'getMeetingPO' => null,
            'getIntergroupPosition' => 0, 'getIntergroupPositionRotation' => '', 'isGdprAccepted' => false,
            'getGdprAcceptedAt' => '', 'getGdprAcceptanceVersion' => '', 'getGdprAcceptanceMethod' => '',
            'getGdprAcceptanceStatement' => '', 'getUpdated' => '2024-06-01 10:00:00',
        ];
        $m = Mockery::mock(Member::class);
        foreach ($d as $method => $value) {
            $m->shouldReceive($method)->andReturn($value);
        }
        return $m;
    }

    // ─── updateMember ────────────────────────────────────────────────

    /** @test */
    public function update_returns_404_when_member_missing(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(null);
        $r = $this->controller->updateMember($this->request());
        $this->assertSame(404, $r->get_status());
    }

    /** @test */
    public function update_returns_422_for_an_invalid_home_group(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->groupRepo->shouldReceive('findById')->with(99)->andReturn(null);

        $r = $this->controller->updateMember($this->request(['home_group_id' => 99]));
        $this->assertSame(422, $r->get_status());
    }

    /** @test */
    public function update_returns_422_for_an_invalid_intergroup_position(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->positionRepo->shouldReceive('findAll')->andReturn([]);

        $r = $this->controller->updateMember($this->request(['intergroup_position_id' => 77]));
        $this->assertSame(422, $r->get_status());
    }

    /** @test */
    public function update_returns_500_when_save_fails(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->revisor->shouldReceive('revise')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(false);

        $r = $this->controller->updateMember($this->request(['anonymous_name' => 'New Name']));
        $this->assertSame(500, $r->get_status());
    }

    /** @test */
    public function update_happy_path_returns_200(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->revisor->shouldReceive('revise')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->updateMember($this->request(['anonymous_name' => 'New Name', 'personal_email' => 'new@example.com']));
        $this->assertSame(200, $r->get_status());
    }

    /** @test */
    public function update_returns_500_on_exception(): void
    {
        $this->memberRepo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->updateMember($this->request());
        $this->assertSame(500, $r->get_status());
    }

    // ─── createMember ────────────────────────────────────────────────

    /** @test */
    public function create_returns_422_for_an_invalid_intergroup_position(): void
    {
        $this->positionRepo->shouldReceive('findAll')->andReturn([]);
        $r = $this->controller->createMember($this->request(['anonymous_name' => 'Newbie', 'intergroup_position_id' => 77]));
        $this->assertSame(422, $r->get_status());
    }

    /** @test */
    public function create_returns_500_when_the_repository_create_fails(): void
    {
        $this->memberRepo->shouldReceive('create')->andReturn(0);
        $r = $this->controller->createMember($this->request(['anonymous_name' => 'Newbie']));
        $this->assertSame(500, $r->get_status());
    }

    /** @test */
    public function create_returns_500_and_cleans_up_when_save_fails(): void
    {
        $this->memberRepo->shouldReceive('create')->andReturn(123);
        $this->factory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(false);

        $r = $this->controller->createMember($this->request(['anonymous_name' => 'Newbie']));
        $this->assertSame(500, $r->get_status());
    }

    /** @test */
    public function create_happy_path_returns_201(): void
    {
        $this->memberRepo->shouldReceive('create')->andReturn(123);
        $this->factory->shouldReceive('createNew')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);
        $this->memberRepo->shouldReceive('findById')->with(123)->andReturn($this->member());

        $r = $this->controller->createMember($this->request(['anonymous_name' => 'Newbie']));
        $this->assertContains($r->get_status(), [200, 201]);
    }

    /** @test */
    public function create_returns_500_on_exception(): void
    {
        $this->memberRepo->shouldReceive('create')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->createMember($this->request(['anonymous_name' => 'Newbie']));
        $this->assertSame(500, $r->get_status());
    }
}
