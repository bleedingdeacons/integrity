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
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;
use Unity\Members\PreferredContact;
use Unity\Plugin as UnityPlugin;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;

/*
 * Tests for MemberController's REST handlers.
 *
 * The controller reaches its repositories through Unity\Plugin::getContainer(),
 * so each test installs a Plugin around a container double with Unity's own
 * Plugin::setInstance(), and clears it afterwards. This used to be an alias
 * mock of Unity\Plugin, which defines the class for the rest of the process
 * and so forced every test into a process of its own.
 */

covers(MemberController::class, ControllerTrait::class);

function memberReadRequest(array $params = []): object
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

/** @return Member&MockInterface */
function memberReadMemberDouble(array $o = [])
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
    foreach (array_merge($d, $o) as $method => $value) {
        $m->shouldReceive($method)->andReturn($value);
    }
    return $m;
}

function memberReadGroupDouble(int $id, string $title): object
{
    $g = Mockery::mock(Group::class);
    $g->shouldReceive('getId')->andReturn($id);
    $g->shouldReceive('getTitle')->andReturn($title);
    $g->shouldReceive('isValid')->andReturn(true);
    return $g;
}

function memberReadPositionDouble(int $id, string $name): object
{
    $p = Mockery::mock(Position::class);
    $p->shouldReceive('getId')->andReturn($id);
    $p->shouldReceive('getLongName')->andReturn($name);
    return $p;
}

beforeEach(function () {
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

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log');

    $this->controller = new MemberController(
        $auditLogger,
        new GroupController($auditLogger),
        new PositionController($auditLogger),
        new MeetingController($auditLogger)
    );
});

afterEach(function () {
    UnityPlugin::setInstance(null);
});

// ─── getMembers ─────────────────────────────────────────────────
describe('getMembers', function () {
    it('masks contact details without the clear permission', function () {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([memberReadMemberDouble()]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getMembers(memberReadRequest());

        expect($response->get_status())->toBe(200);
        $data = $response->get_data()['data'][0];
        expect($data['anonymous_name'])->toBe('Anon')
            // Masked, not the raw address.
            ->and($data['personal_email'])->not->toBe('jane@example.com')
            ->and($response->get_data()['meta']['total'])->toBe(1);
    });

    it('resolves related group and position names', function () {
        $member = memberReadMemberDouble(['getHomeGroup' => 5, 'getIntergroupPosition' => 7]);
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([$member]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $this->groupRepo->shouldReceive('findAll')->once()->andReturn([memberReadGroupDouble(5, 'Tuesday Group')]);
        $this->positionRepo->shouldReceive('findAll')->once()->andReturn([memberReadPositionDouble(7, 'Chair')]);

        $response = $this->controller->getMembers(memberReadRequest());
        $data = $response->get_data()['data'][0];

        expect($data['home_group_id'])->toBe(5)
            ->and($data['home_group_name'])->toBe('Tuesday Group')
            ->and($data['intergroup_position_id'])->toBe(7)
            ->and($data['intergroup_position_name'])->toBe('Chair');
    });

    it('returns clear contact details with permission', function () {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([memberReadMemberDouble()]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getMembers(memberReadRequest([
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['members:clear']],
        ]));

        expect($response->get_data()['data'][0]['personal_email'])->toBe('jane@example.com');
    });

    /**
     * A landline is personal data, so it is masked on the way out exactly as
     * the mobile is — and unmasked by the same permission.
     */
    it('masks the landline without the clear permission', function () {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([memberReadMemberDouble()]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $row = $this->controller->getMembers(memberReadRequest())->get_data()['data'][0];

        expect($row['landline_number'])
            ->not->toBe('0117 496 0000')
            ->toContain('*');
    });

    it('returns the landline in the clear with permission', function () {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([memberReadMemberDouble()]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getMembers(memberReadRequest([
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['members:clear']],
        ]));

        expect($response->get_data()['data'][0]['landline_number'])->toBe('0117 496 0000');
    });

    /**
     * The preferred contact names one of two options rather than a number, so
     * it is never masked: a client that cannot read it cannot tell which of
     * the two numbers to ring.
     */
    it('returns the preferred contact in the clear without permission', function () {
        $this->memberRepo->shouldReceive('findAll')->once()->andReturn([
            memberReadMemberDouble(['getPreferredContact' => PreferredContact::Landline]),
        ]);
        $this->memberRepo->shouldReceive('count')->once()->andReturn(1);

        $row = $this->controller->getMembers(memberReadRequest())->get_data()['data'][0];

        expect($row['preferred_contact'])->toBe('Landline');
    });

    it('returns 500 on failure', function () {
        $this->memberRepo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

        $response = $this->controller->getMembers(memberReadRequest());

        expect($response->get_status())->toBe(500);
    });
});

// ─── getMember ──────────────────────────────────────────────────
describe('getMember', function () {
    it('returns a single member', function () {
        $this->memberRepo->shouldReceive('findById')->once()->with(1)->andReturn(memberReadMemberDouble());

        $response = $this->controller->getMember(memberReadRequest(['id' => 1]));

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['data']['id'])->toBe(1);
    });

    it('returns 404 when missing', function () {
        $this->memberRepo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->getMember(memberReadRequest(['id' => 9]));

        expect($response->get_status())->toBe(404);
    });
});

// ─── createMember ───────────────────────────────────────────────
describe('createMember', function () {
    it('inserts and returns 201', function () {
        $this->memberRepo->shouldReceive('create')->once()->with('New Person')->andReturn(42);
        $this->factory->shouldReceive('createNew')->once()->andReturn(memberReadMemberDouble(['getId' => 42]));
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);
        $this->memberRepo->shouldReceive('findById')->once()->with(42)->andReturn(memberReadMemberDouble(['getId' => 42]));

        $response = $this->controller->createMember(memberReadRequest(['anonymous_name' => 'New Person']));

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['success'])->toBeTrue()
            ->and($response->get_data()['data']['id'])->toBe(42);
    });

    it('rejects an unknown home group', function () {
        $this->groupRepo->shouldReceive('findById')->once()->with(99)->andReturn(null);

        $response = $this->controller->createMember(memberReadRequest([
            'anonymous_name' => 'New Person',
            'home_group_id' => 99,
        ]));

        expect($response->get_status())->toBe(422)
            ->and($response->get_data()['error']['code'])->toBe('invalid_home_group');
    });
});

// ─── updateMember ───────────────────────────────────────────────
describe('updateMember', function () {
    it('saves and returns the updated member', function () {
        $existing = memberReadMemberDouble();
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn($existing, memberReadMemberDouble());
        $this->revisor->shouldReceive('revise')->once()->andReturn(memberReadMemberDouble());
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $response = $this->controller->updateMember(memberReadRequest([
            'id' => 1,
            'anonymous_name' => 'Renamed',
        ]));

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['success'])->toBeTrue();
    });

    it('returns 404 for a missing member', function () {
        $this->memberRepo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->updateMember(memberReadRequest(['id' => 9]));

        expect($response->get_status())->toBe(404);
    });
});

// ─── recordCompliance ───────────────────────────────────────────
describe('recordCompliance', function () {
    it('records an acceptance', function () {
        $this->memberRepo->shouldReceive('findById')->with(1)->andReturn(memberReadMemberDouble(), memberReadMemberDouble(['isGdprAccepted' => true]));
        $this->revisor->shouldReceive('revise')->once()->andReturn(memberReadMemberDouble(['isGdprAccepted' => true]));
        $this->memberRepo->shouldReceive('save')->once()->andReturn(true);

        $response = $this->controller->recordCompliance(memberReadRequest([
            'id' => 1,
            'accepted' => true,
            'version' => '2.1',
        ]));

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['data']['gdpr_compliance']['accepted'])->toBeTrue();
    });

    it('returns 404 for a missing member', function () {
        $this->memberRepo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->recordCompliance(memberReadRequest(['id' => 9, 'accepted' => true]));

        expect($response->get_status())->toBe(404);
    });
});
