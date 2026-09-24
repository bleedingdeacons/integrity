<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Auth\AuditLogger;
use Mockery;
use Mockery\MockInterface;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;
use Unity\Members\PreferredContact;
use Unity\Plugin as UnityPlugin;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;

/*
 * Tests for MemberController's write handlers (create / update) beyond the
 * happy path covered by MemberControllerTest: the validation, not-found,
 * save-failure and exception branches.
 *
 * The controller reaches its repositories through Unity\Plugin::getContainer(),
 * so each test installs a Plugin around a container double with Unity's own
 * Plugin::setInstance(), and clears it afterwards. This used to be an alias
 * mock of Unity\Plugin, which defines the class for the rest of the process
 * and so forced every test into a process of its own. The global $wpdb set
 * below is restored afterwards for the same reason: nothing isolates it now.
 */

covers(MemberController::class, ControllerTrait::class);

function memberWriteRequest(array $params = []): object
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

function memberWriteComplianceRequest(array $params = []): object
{
    return memberWriteRequest(array_merge([
        'id' => 1, 'accepted' => true, 'accepted_at' => '', 'version' => '1.0',
        'method' => 'api', 'policy_id' => null,
    ], $params));
}

/** @return Member&MockInterface */
function memberWriteMemberDouble()
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

/**
 * Capture the arguments the controller hands to MemberRevisor::revise().
 *
 * @param mixed $captured Filled in by reference with the positional args
 *                        after $base.
 */
function memberWriteCaptureRevisedArgs(MockInterface $revisor, &$captured): void
{
    $revisor->shouldReceive('revise')->andReturnUsing(
        function (Member $base, ...$args) use (&$captured): Member {
            $captured = $args;
            return $base;
        }
    );
}

/** A key without members:clear, so masked values are what it saw. */
function memberWriteMaskedKeyData(): array
{
    return ['api_key_id' => 1, 'permissions' => ['members:write']];
}

beforeEach(function () {
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

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log')->byDefault();

    $this->controller = new MemberController(
        $auditLogger,
        new GroupController($auditLogger),
        new PositionController($auditLogger),
        new MeetingController($auditLogger)
    );

    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = (object) ['last_error' => ''];
});

afterEach(function () {
    UnityPlugin::setInstance(null);
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

// ─── updateMember ────────────────────────────────────────────────
describe('updateMember', function () {
    it('returns 404 when the member is missing', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(null);
        $r = $this->controller->updateMember(memberWriteRequest());
        expect($r->get_status())->toBe(404);
    });

    it('returns 422 for an invalid home group', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->groupRepo->shouldReceive('findById')->with(99)->andReturn(null);

        $r = $this->controller->updateMember(memberWriteRequest(['home_group_id' => 99]));
        expect($r->get_status())->toBe(422);
    });

    it('returns 422 for an invalid intergroup position', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->positionRepo->shouldReceive('findAll')->andReturn([]);

        $r = $this->controller->updateMember(memberWriteRequest(['intergroup_position_id' => 77]));
        expect($r->get_status())->toBe(422);
    });

    it('returns 500 when the save fails', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->revisor->shouldReceive('revise')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(false);

        $r = $this->controller->updateMember(memberWriteRequest(['anonymous_name' => 'New Name']));
        expect($r->get_status())->toBe(500);
    });

    it('returns 200 on the happy path', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->revisor->shouldReceive('revise')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->updateMember(memberWriteRequest(['anonymous_name' => 'New Name', 'personal_email' => 'new@example.com']));
        expect($r->get_status())->toBe(200);
    });

    it('returns 500 on an exception', function () {
        $this->memberRepo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->updateMember(memberWriteRequest());
        expect($r->get_status())->toBe(500);
    });
});

// ─── landline and preferred contact ──────────────────────────────
describe('landline and preferred contact', function () {
    /**
     * The landline goes through the same round-trip guard as the mobile: a
     * client that read a masked value and posted the whole record back must
     * not overwrite the real number with the mask.
     */
    it('ignores a landline submitted in its masked form', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        memberWriteCaptureRevisedArgs($this->revisor, $captured);

        $r = $this->controller->updateMember(memberWriteRequest([
            'landline_number' => '******0000',
            '_integrity_key_data' => memberWriteMaskedKeyData(),
        ]));

        expect($r->get_status())->toBe(200)
            // The stored value, not the mask, is what was handed to revise().
            ->and($captured)->toContain('0117 496 0000')
            ->and($captured)->not->toContain('******0000');
    });

    /**
     * A key holding members:clear both reads and writes in the clear, so the
     * guard above does not apply to it — the same exception the personal
     * email has always had.
     */
    it('lets a clear key write a landline that looks masked', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        memberWriteCaptureRevisedArgs($this->revisor, $captured);

        $this->controller->updateMember(memberWriteRequest(['landline_number' => '******0000']));

        expect($captured)->toContain('******0000');
    });

    it('accepts a real landline', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        memberWriteCaptureRevisedArgs($this->revisor, $captured);

        $r = $this->controller->updateMember(memberWriteRequest(['landline_number' => '0117 496 1111']));

        expect($r->get_status())->toBe(200)
            ->and($captured)->toContain('0117 496 1111');
    });

    /**
     * Not named in the request means "leave it alone", so revise() is handed
     * null and Unity carries the stored preference over.
     */
    it('leaves the preferred contact alone when unnamed', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        memberWriteCaptureRevisedArgs($this->revisor, $captured);

        $this->controller->updateMember(memberWriteRequest(['anonymous_name' => 'New Name']));

        expect($captured)
            ->not->toContain(PreferredContact::Mobile)
            ->not->toContain(PreferredContact::Landline);
    });

    it('passes a named preferred contact through', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $captured = null;
        memberWriteCaptureRevisedArgs($this->revisor, $captured);

        $this->controller->updateMember(memberWriteRequest(['preferred_contact' => 'Landline']));

        expect($captured)->toContain(PreferredContact::Landline);
    });

    /**
     * The schema rejects anything that is not one of the two case values, so
     * a client sending 'landline' is told so rather than silently given
     * Mobile.
     */
    it('accepts only the two case values in the preferred contact schema', function (mixed $value, bool $expected) {
        foreach (['getUpdateMemberArgs', 'getCreateMemberArgs'] as $method) {
            $validate = $this->controller->{$method}()['preferred_contact']['validate_callback'];

            expect((bool) $validate($value))->toBe($expected, $method . ' / ' . var_export($value, true));
        }
    })->with([
        'Mobile'        => ['Mobile', true],
        'Landline'      => ['Landline', true],
        'wrong case'    => ['landline', false],
        'renamed'       => ['Home Phone', false],
        'empty'         => ['', false],
        'not a string'  => [42, false],
    ]);

    it('gives the landline the same schema as the mobile', function () {
        foreach (['getUpdateMemberArgs', 'getCreateMemberArgs'] as $method) {
            $validate = $this->controller->{$method}()['landline_number']['validate_callback'];

            expect($validate('0117 496 0000'))->toBeTrue($method)
                ->and($validate(''))->toBeTrue($method)
                ->and($validate(str_repeat('9', 51)))->toBeFalse($method)
                ->and($validate(12345))->toBeFalse($method);
        }
    });
});

// ─── createMember ────────────────────────────────────────────────
describe('createMember', function () {
    it('returns 422 for an invalid intergroup position', function () {
        $this->positionRepo->shouldReceive('findAll')->andReturn([]);
        $r = $this->controller->createMember(memberWriteRequest(['anonymous_name' => 'Newbie', 'intergroup_position_id' => 77]));
        expect($r->get_status())->toBe(422);
    });

    it('returns 500 when the repository create fails', function () {
        $this->memberRepo->shouldReceive('create')->andReturn(0);
        $r = $this->controller->createMember(memberWriteRequest(['anonymous_name' => 'Newbie']));
        expect($r->get_status())->toBe(500);
    });

    it('returns 500 and cleans up when the save fails', function () {
        $this->memberRepo->shouldReceive('create')->andReturn(123);
        $this->factory->shouldReceive('createNew')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(false);

        $r = $this->controller->createMember(memberWriteRequest(['anonymous_name' => 'Newbie']));
        expect($r->get_status())->toBe(500);
    });

    it('returns 201 on the happy path', function () {
        $this->memberRepo->shouldReceive('create')->andReturn(123);
        $this->factory->shouldReceive('createNew')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);
        $this->memberRepo->shouldReceive('findById')->with(123)->andReturn(memberWriteMemberDouble());

        $r = $this->controller->createMember(memberWriteRequest(['anonymous_name' => 'Newbie']));
        expect($r->get_status())->toBeIn([200, 201]);
    });

    it('returns 500 on an exception', function () {
        $this->memberRepo->shouldReceive('create')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->createMember(memberWriteRequest(['anonymous_name' => 'Newbie']));
        expect($r->get_status())->toBe(500);
    });
});

// ─── getMembers (filters + exception) ─────────────────────────────
describe('getMembers', function () {
    it('applies search and home group filters', function () {
        $this->memberRepo->shouldReceive('findAll')->andReturn([memberWriteMemberDouble()]);
        $this->memberRepo->shouldReceive('count')->andReturn(1);
        $this->groupRepo->shouldReceive('batchGetGroups')->never();

        $r = $this->controller->getMembers(memberWriteRequest([
            'per_page' => 25, 'page' => 1, 'search' => 'Anon', 'home_group_id' => 3,
        ]));

        expect($r->get_status())->toBe(200);
    });

    it('returns 500 on an exception', function () {
        $this->memberRepo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->getMembers(memberWriteRequest(['per_page' => 25, 'page' => 1]));
        expect($r->get_status())->toBe(500);
    });
});

// ─── recordCompliance ─────────────────────────────────────────────
describe('recordCompliance', function () {
    it('returns 404 when the member is missing', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(null);
        $r = $this->controller->recordCompliance(memberWriteComplianceRequest());
        expect($r->get_status())->toBe(404);
    });

    it('accepts with an empty statement when there is no policy', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->revisor->shouldReceive('revise')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->recordCompliance(memberWriteComplianceRequest(['accepted_at' => '2026-01-01T00:00:00Z']));
        expect($r->get_status())->toBe(200);
    });

    it('resolves the statement from a valid policy', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $policy = Mockery::mock(PrivacyPolicy::class);
        $policy->shouldReceive('getPolicy')->andReturn('The policy body');
        $this->policyRepo->shouldReceive('findById')->with(50)->andReturn($policy);
        $this->revisor->shouldReceive('revise')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->recordCompliance(memberWriteComplianceRequest(['policy_id' => 50]));
        expect($r->get_status())->toBe(200);
    });

    it('returns 422 for an unknown policy', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->policyRepo->shouldReceive('findById')->with(50)->andReturn(null);

        $r = $this->controller->recordCompliance(memberWriteComplianceRequest(['policy_id' => 50]));
        expect($r->get_status())->toBe(422);
    });

    it('records a revocation', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->revisor->shouldReceive('revise')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(true);

        $r = $this->controller->recordCompliance(memberWriteComplianceRequest(['accepted' => false]));
        expect($r->get_status())->toBe(200);
    });

    it('returns 500 when the save fails', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberWriteMemberDouble());
        $this->revisor->shouldReceive('revise')->andReturn(memberWriteMemberDouble());
        $this->memberRepo->shouldReceive('save')->andReturn(false);

        $r = $this->controller->recordCompliance(memberWriteComplianceRequest());
        expect($r->get_status())->toBe(500);
    });

    it('returns 500 on an exception', function () {
        $this->memberRepo->shouldReceive('findById')->andThrow(new \RuntimeException('boom'));
        $r = $this->controller->recordCompliance(memberWriteComplianceRequest());
        expect($r->get_status())->toBe(500);
    });
});
