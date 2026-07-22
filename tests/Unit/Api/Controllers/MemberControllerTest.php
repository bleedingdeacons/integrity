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
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;

/**
 * Tests for MemberController's REST handlers.
 *
 * @covers \Integrity\Api\Controllers\MemberController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberControllerTest extends TestCase
{
    /** @var MemberRepository&\Mockery\MockInterface */
    private $memberRepo;
    /** @var GroupRepository&\Mockery\MockInterface */
    private $groupRepo;
    /** @var PositionRepository&\Mockery\MockInterface */
    private $positionRepo;
    /** @var MeetingRepository&\Mockery\MockInterface */
    private $meetingRepo;
    /** @var MemberRevisor&\Mockery\MockInterface */
    private $revisor;
    /** @var MemberFactory&\Mockery\MockInterface */
    private $factory;
    /** @var PrivacyPolicyRepository&\Mockery\MockInterface */
    private $policyRepo;

    private MemberController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->groupRepo = Mockery::mock(GroupRepository::class);
        $this->positionRepo = Mockery::mock(PositionRepository::class);
        $this->meetingRepo = Mockery::mock(MeetingRepository::class);
        $this->revisor = Mockery::mock(MemberRevisor::class);
        $this->factory = Mockery::mock(MemberFactory::class);
        $this->policyRepo = Mockery::mock(PrivacyPolicyRepository::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo);
        $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->groupRepo);
        $container->shouldReceive('get')->with(PositionRepository::class)->andReturn($this->positionRepo);
        $container->shouldReceive('get')->with(MeetingRepository::class)->andReturn($this->meetingRepo);
        $container->shouldReceive('get')->with(MemberRevisor::class)->andReturn($this->revisor);
        $container->shouldReceive('get')->with(MemberFactory::class)->andReturn($this->factory);
        $container->shouldReceive('get')->with(PrivacyPolicyRepository::class)->andReturn($this->policyRepo);

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log');

        $this->controller = new MemberController(
            $auditLogger,
            new GroupController($auditLogger),
            new PositionController($auditLogger),
            new MeetingController($auditLogger)
        );
    }

    private function request(array $params = []): object
    {
        $params = array_merge([
            'per_page' => 100,
            'page' => 1,
            '_integrity_start_time' => microtime(true),
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['members:read']],
        ], $params);

        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_param')->andReturnUsing(fn ($k) => $params[$k] ?? null);
        $request->shouldReceive('has_param')->andReturnUsing(fn ($k) => array_key_exists($k, $params));
        $request->shouldReceive('get_route')->andReturn('/integrity/v1/members');
        $request->shouldReceive('get_method')->andReturn('GET');
        return $request;
    }

    /** @return Member&\Mockery\MockInterface */
    private function member(array $o = [])
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
        foreach (array_merge($d, $o) as $method => $value) {
            $m->shouldReceive($method)->andReturn($value);
        }
        return $m;
    }

    private function group(int $id, string $title): object
    {
        $g = Mockery::mock(\Unity\Groups\Interfaces\Group::class);
        $g->shouldReceive('getId')->andReturn($id);
        $g->shouldReceive('getTitle')->andReturn($title);
        $g->shouldReceive('isValid')->andReturn(true);
        return $g;
    }

    private function position(int $id, string $name): object
    {
        $p = Mockery::mock(\Unity\Positions\Interfaces\Position::class);
        $p->shouldReceive('getId')->andReturn($id);
        $p->shouldReceive('getLongName')->andReturn($name);
        return $p;
    }

    // ─── getMembers ─────────────────────────────────────────────────

    /**
     * @test
     */
    public function get_members_masks_contact_details_without_clear_permission(): void
    {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([$this->member()]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getMembers($this->request());

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data()['data'][0];
        $this->assertSame('Anon', $data['anonymous_name']);
        // Masked, not the raw address.
        $this->assertNotSame('jane@example.com', $data['personal_email']);
        $this->assertSame(1, $response->get_data()['meta']['total']);
    }

    /**
     * @test
     */
    public function get_members_resolves_related_group_and_position_names(): void
    {
        $member = $this->member(['getHomeGroup' => 5, 'getIntergroupPosition' => 7]);
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([$member]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $this->groupRepo->shouldReceive('findAll')->once()->andReturn([$this->group(5, 'Tuesday Group')]);
        $this->positionRepo->shouldReceive('findAll')->once()->andReturn([$this->position(7, 'Chair')]);

        $response = $this->controller->getMembers($this->request());
        $data = $response->get_data()['data'][0];

        $this->assertSame(5, $data['home_group_id']);
        $this->assertSame('Tuesday Group', $data['home_group_name']);
        $this->assertSame(7, $data['intergroup_position_id']);
        $this->assertSame('Chair', $data['intergroup_position_name']);
    }

    /**
     * @test
     */
    public function get_members_returns_clear_contact_details_with_permission(): void
    {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([$this->member()]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getMembers($this->request([
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['members:clear']],
        ]));

        $this->assertSame('jane@example.com', $response->get_data()['data'][0]['personal_email']);
    }

    /**
     * @test
     */
    public function get_members_returns_500_on_failure(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

        $response = $this->controller->getMembers($this->request());

        $this->assertSame(500, $response->get_status());
    }

    // ─── getMember ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function get_member_returns_a_single_member(): void
    {
        $this->memberRepo->shouldReceive('findById')->once()->with(1)->andReturn($this->member());

        $response = $this->controller->getMember($this->request(['id' => 1]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $response->get_data()['data']['id']);
    }

    /**
     * @test
     */
    public function get_member_returns_404_when_missing(): void
    {
        $this->memberRepo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->getMember($this->request(['id' => 9]));

        $this->assertSame(404, $response->get_status());
    }

    // ─── createMember ───────────────────────────────────────────────

    /**
     * @test
     */
    public function create_member_inserts_and_returns_201(): void
    {
        $this->memberRepo->shouldReceive('create')->once()->with('New Person')->andReturn(42);
        $this->factory->shouldReceive('createNew')->once()->andReturn($this->member(['getId' => 42]));
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);
        $this->memberRepo->shouldReceive('findById')->once()->with(42)->andReturn($this->member(['getId' => 42]));

        $response = $this->controller->createMember($this->request(['anonymous_name' => 'New Person']));

        $this->assertSame(201, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
        $this->assertSame(42, $response->get_data()['data']['id']);
    }

    /**
     * @test
     */
    public function create_member_rejects_an_unknown_home_group(): void
    {
        $this->groupRepo->shouldReceive('findById')->once()->with(99)->andReturn(null);

        $response = $this->controller->createMember($this->request([
            'anonymous_name' => 'New Person',
            'home_group_id' => 99,
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame('invalid_home_group', $response->get_data()['error']['code']);
    }

    // ─── updateMember ───────────────────────────────────────────────

    /**
     * @test
     */
    public function update_member_saves_and_returns_the_updated_member(): void
    {
        $existing = $this->member();
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($existing, $this->member());
        $this->revisor->shouldReceive('revise')->once()->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $response = $this->controller->updateMember($this->request([
            'id' => 1,
            'anonymous_name' => 'Renamed',
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }

    /**
     * @test
     */
    public function update_member_returns_404_for_a_missing_member(): void
    {
        $this->memberRepo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->updateMember($this->request(['id' => 9]));

        $this->assertSame(404, $response->get_status());
    }

    // ─── recordCompliance ───────────────────────────────────────────

    /**
     * @test
     */
    public function record_compliance_records_an_acceptance(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member(), $this->member(['isGdprAccepted' => true]));
        $this->revisor->shouldReceive('revise')->once()->andReturn($this->member(['isGdprAccepted' => true]));
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $response = $this->controller->recordCompliance($this->request([
            'id' => 1,
            'accepted' => true,
            'version' => '2.1',
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['data']['gdpr_compliance']['accepted']);
    }

    /**
     * @test
     */
    public function record_compliance_returns_404_for_a_missing_member(): void
    {
        $this->memberRepo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->recordCompliance($this->request(['id' => 9, 'accepted' => true]));

        $this->assertSame(404, $response->get_status());
    }
}
