<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Api\Controllers\GroupController;
use Integrity\Auth\AuditLogger;
use Mockery;
use Mockery\MockInterface;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Plugin as UnityPlugin;

/*
 * Tests for GroupController's REST handlers (transformGroup is covered
 * separately in GroupControllerTest).
 *
 * The controller reaches its repository through Unity\Plugin::getContainer(),
 * so each test installs a Plugin around a container double with Unity's own
 * Plugin::setInstance(), and clears it afterwards. This used to be an alias
 * mock of Unity\Plugin, which defines the class for the rest of the process
 * and so forced every test into a process of its own.
 */

covers(GroupController::class, ControllerTrait::class);

/** @return Group&MockInterface */
function handlersGroupDouble(int $id = 1, string $title = 'Tuesday Group')
{
    $g = Mockery::mock(Group::class);
    $g->shouldReceive('getId')->andReturn($id);
    $g->shouldReceive('isValid')->andReturn(true);
    $g->shouldReceive('getTitle')->andReturn($title);
    $g->shouldReceive('getEmail')->andReturn('group@example.com');
    $g->shouldReceive('getPhone')->andReturn('555');
    $g->shouldReceive('getWebsite')->andReturn('https://example.com');
    $g->shouldReceive('getLink')->andReturn('/group/1');
    $g->shouldReceive('getGroupNotes')->andReturn('Notes');
    $g->shouldReceive('getDistrictId')->andReturn(42);
    $g->shouldReceive('getLastContact')->andReturn('2024-01-01');
    $g->shouldReceive('getMeetings')->andReturn([]);
    $g->shouldReceive('getContacts')->andReturn([]);
    $g->shouldReceive('getVenmo')->andReturn('@g');
    $g->shouldReceive('getPaypal')->andReturn('');
    $g->shouldReceive('getSquare')->andReturn('');
    $g->shouldReceive('hasContributionOptions')->andReturn(true);
    $g->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');
    return $g;
}

beforeEach(function () {
    $this->repo = Mockery::mock(GroupRepository::class);

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->repo);

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log');

    $this->controller = new GroupController($auditLogger);

    $this->makeRequest = fn (array $params = []): object => $this->createMockRequest(array_merge([
        'per_page' => 100,
        'page' => 1,
        'search' => '',
        'district_id' => null,
        'expand' => '',
        '_integrity_start_time' => microtime(true),
        '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['groups:read']],
    ], $params));
});

afterEach(function () {
    UnityPlugin::setInstance(null);
});

it('returns a paginated list of groups', function () {
    $this->repo->shouldReceive('findAll')->once()->andReturn([handlersGroupDouble(1), handlersGroupDouble(2, 'Thursday')]);
    $this->repo->shouldReceive('count')->once()->andReturn(2);

    $response = $this->controller->getGroups(($this->makeRequest)());

    expect($response->get_status())->toBe(200);
    $data = $response->get_data();
    expect($data['data'])->toHaveCount(2)
        ->and($data['data'][0]['title'])->toBe('Tuesday Group')
        ->and($data['data'][0])->toHaveKey('meeting_ids')
        ->and($data['meta']['total'])->toBe(2);
});

it('filters groups by district', function () {
    $this->repo->shouldReceive('findAll')->once()->andReturn([handlersGroupDouble()]);
    $this->repo->shouldReceive('count')->once()->andReturn(1);

    $response = $this->controller->getGroups(($this->makeRequest)(['district_id' => 42]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['meta']['total'])->toBe(1);
});

it('returns 500 when listing groups fails', function () {
    $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

    expect($this->controller->getGroups(($this->makeRequest)())->get_status())->toBe(500);
});

it('returns a single group', function () {
    $this->repo->shouldReceive('findById')->once()->with(5)->andReturn(handlersGroupDouble(5, 'Friday'));

    $response = $this->controller->getGroup(($this->makeRequest)(['id' => 5]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['data']['title'])->toBe('Friday');
});

it('returns 404 when the group is missing', function () {
    $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

    expect($this->controller->getGroup(($this->makeRequest)(['id' => 9]))->get_status())->toBe(404);
});

it('batch-gets groups mapped by id and short-circuits on empty', function () {
    expect($this->controller->batchGetGroups($this->repo, []))->toBe([]);

    $this->repo->shouldReceive('findAll')->once()->andReturn([handlersGroupDouble(3), handlersGroupDouble(4)]);
    expect(array_keys($this->controller->batchGetGroups($this->repo, [3, 4])))->toBe([3, 4]);
});
