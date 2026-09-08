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
use Unity\Members\PreferredContact;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;

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
    private $policyRepo;
    private MemberController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberRepo = Mockery::mock(MemberRepository::class);
        $this->groupRepo = Mockery::mock(GroupRepository::class);
        $this->positionRepo = Mockery::mock(PositionRepository::class);
        $this->revisor = Mockery::mock(MemberRevisor::class);
        $this->factory = Mockery::mock(MemberFactory::class);
        $this->policyRepo = Mockery::mock(PrivacyPolicyRepository::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(MemberRepository::class)->andReturn($this->memberRepo)->byDefault();
        $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->groupRepo)->byDefault();
        $container->shouldReceive('get')->with(PositionRepository::class)->andReturn($this->positionRepo)->byDefault();
        $container->shouldReceive('get')->with(MeetingRepository::class)->andReturn(Mockery::mock(MeetingRepository::class))->byDefault();
        $container->shouldReceive('get')->with(MemberRevisor::class)->andReturn($this->revisor)->byDefault();
        $container->shouldReceive('get')->with(MemberFactory::class)->andReturn($this->factory)->byDefault();
        $container->shouldReceive('get')->with(PrivacyPolicyRepository::class)->andReturn($this->policyRepo)->byDefault();

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
            'getMobileNumber' => '07700 900000', 'getLandlineNumber' => '0117 496 0000',
            'getPreferredContact' => PreferredContact::Mobile,
            'showAnonymousName' => true, 'showMemberProfile' => false,
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

    // ─── landline and preferred contact ──────────────────────────────

    /**
     * Capture the arguments the controller hands to MemberRevisor::revise().
     *
     * @param mixed $captured Filled in by reference with the positional args
     *                        after $base.
     */
    private function captureRevisedArgs(&$captured): void
    {
        $this->revisor->shouldReceive('revise')->andReturnUsing(
            function (Member $base, ...$args) use (&$captured): Member {
                $captured = $args;
                return $base;
            }
        );
    }

    /** A key without members:clear, so masked values are what it saw. */
    private function maskedKeyData(): array
    {
        return ['api_key_id' => 1, 'permissions' => ['members:write']];
    }

    /**
     * The landline goes through the same round-trip guard as the mobile: a
     * client that read a masked value and posted the whole record back must
     * not overwrite the real number with the mask.
     *
     * @test
     */
    public function update_ignores_a_landline_submitted_in_its_masked_form(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        $this->captureRevisedArgs($captured);

        $r = $this->controller->updateMember($this->request([
            'landline_number' => '******0000',
            '_integrity_key_data' => $this->maskedKeyData(),
        ]));

        $this->assertSame(200, $r->get_status());
        // The stored value, not the mask, is what was handed to revise().
        $this->assertContains('0117 496 0000', $captured);
        $this->assertNotContains('******0000', $captured);
    }

    /**
     * A key holding members:clear both reads and writes in the clear, so the
     * guard above does not apply to it — the same exception the personal
     * email has always had.
     *
     * @test
     */
    public function a_clear_key_may_write_a_landline_that_looks_masked(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        $this->captureRevisedArgs($captured);

        $this->controller->updateMember($this->request(['landline_number' => '******0000']));

        $this->assertContains('******0000', $captured);
    }

    /** @test */
    public function update_accepts_a_real_landline(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        $this->captureRevisedArgs($captured);

        $r = $this->controller->updateMember($this->request(['landline_number' => '0117 496 1111']));

        $this->assertSame(200, $r->get_status());
        $this->assertContains('0117 496 1111', $captured);
    }

    /**
     * Not named in the request means "leave it alone", so revise() is handed
     * null and Unity carries the stored preference over.
     *
     * @test
     */
    public function update_leaves_the_preferred_contact_alone_when_unnamed(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        $this->captureRevisedArgs($captured);

        $this->controller->updateMember($this->request(['anonymous_name' => 'New Name']));

        $this->assertNotContains(PreferredContact::Mobile, $captured);
        $this->assertNotContains(PreferredContact::Landline, $captured);
    }

    /** @test */
    public function update_passes_a_named_preferred_contact_through(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        $this->captureRevisedArgs($captured);

        $this->controller->updateMember($this->request(['preferred_contact' => 'Landline']));

        $this->assertContains(PreferredContact::Landline, $captured);
    }

    /**
     * The schema rejects anything that is not one of the two case values, so
     * a client sending 'landline' is told so rather than silently given
     * Mobile.
     *
     * @test
     * @dataProvider preferredContactValues
     */
    public function the_preferred_contact_schema_accepts_only_the_two_case_values(
        mixed $value,
        bool $expected
    ): void {
        foreach (['getUpdateMemberArgs', 'getCreateMemberArgs'] as $method) {
            $validate = $this->controller->{$method}()['preferred_contact']['validate_callback'];

            $this->assertSame($expected, (bool) $validate($value), $method . ' / ' . var_export($value, true));
        }
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function preferredContactValues(): array
    {
        return [
            'Mobile'        => ['Mobile', true],
            'Landline'      => ['Landline', true],
            'wrong case'    => ['landline', false],
            'renamed'       => ['Home Phone', false],
            'empty'         => ['', false],
            'not a string'  => [42, false],
        ];
    }

    /** @test */
    public function the_landline_schema_matches_the_mobile_schema(): void
    {
        foreach (['getUpdateMemberArgs', 'getCreateMemberArgs'] as $method) {
            $validate = $this->controller->{$method}()['landline_number']['validate_callback'];

            $this->assertTrue($validate('0117 496 0000'), $method);
            $this->assertTrue($validate(''), $method);
            $this->assertFalse($validate(str_repeat('9', 51)), $method);
            $this->assertFalse($validate(12345), $method);
        }
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

    // ─── getMembers (filters + exception) ─────────────────────────────

    /** @test */
    public function get_members_applies_search_and_home_group_filters(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andReturn([$this->member()]);
        $this->memberRepo->shouldReceive('count')->andReturn(1);
        $this->groupRepo->shouldReceive('batchGetGroups')->never();

        $r = $this->controller->getMembers($this->request([
            'per_page' => 25, 'page' => 1, 'search' => 'Anon', 'home_group_id' => 3,
        ]));

        $this->assertSame(200, $r->get_status());
    }

    /** @test */
    public function get_members_returns_500_on_exception(): void
    {
        $this->memberRepo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->getMembers($this->request(['per_page' => 25, 'page' => 1]));
        $this->assertSame(500, $r->get_status());
    }

    // ─── recordCompliance ─────────────────────────────────────────────

    private function complianceRequest(array $params = []): object
    {
        return $this->request(array_merge([
            'id' => 1, 'accepted' => true, 'accepted_at' => '', 'version' => '1.0',
            'method' => 'api', 'policy_id' => null,
        ], $params));
    }

    /** @test */
    public function record_compliance_returns_404_when_member_missing(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(null);
        $r = $this->controller->recordCompliance($this->complianceRequest());
        $this->assertSame(404, $r->get_status());
    }

    /** @test */
    public function record_compliance_accepts_with_an_empty_statement_when_no_policy(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->revisor->shouldReceive('revise')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->recordCompliance($this->complianceRequest(['accepted_at' => '2026-01-01T00:00:00Z']));
        $this->assertSame(200, $r->get_status());
    }

    /** @test */
    public function record_compliance_resolves_the_statement_from_a_valid_policy(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $policy = Mockery::mock(PrivacyPolicy::class);
        $policy->shouldReceive('getPolicy')->andReturn('The policy body');
        $this->policyRepo->shouldReceive('findById')->with(50)->andReturn($policy);
        $this->revisor->shouldReceive('revise')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->recordCompliance($this->complianceRequest(['policy_id' => 50]));
        $this->assertSame(200, $r->get_status());
    }

    /** @test */
    public function record_compliance_returns_422_for_an_unknown_policy(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->policyRepo->shouldReceive('findById')->with(50)->andReturn(null);

        $r = $this->controller->recordCompliance($this->complianceRequest(['policy_id' => 50]));
        $this->assertSame(422, $r->get_status());
    }

    /** @test */
    public function record_compliance_records_a_revocation(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->revisor->shouldReceive('revise')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->recordCompliance($this->complianceRequest(['accepted' => false]));
        $this->assertSame(200, $r->get_status());
    }

    /** @test */
    public function record_compliance_returns_500_when_save_fails(): void
    {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($this->member());
        $this->revisor->shouldReceive('revise')->andReturn($this->member());
        $this->memberRepo->shouldReceive('save')->andReturn(false);

        $r = $this->controller->recordCompliance($this->complianceRequest());
        $this->assertSame(500, $r->get_status());
    }

    /** @test */
    public function record_compliance_returns_500_on_exception(): void
    {
        $this->memberRepo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->recordCompliance($this->complianceRequest());
        $this->assertSame(500, $r->get_status());
    }
}
