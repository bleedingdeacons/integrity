<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Api\Controllers\PositionController;
use Integrity\Auth\AuditLogger;
use Mockery;
use Mockery\MockInterface;
use Unity\Core\Interfaces\Container;
use Unity\Plugin as UnityPlugin;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Tests for PositionController's REST handlers.
 *
 * The controller reaches its repository through Unity\Plugin::getContainer(),
 * so each test installs a Plugin around a container double with Unity's own
 * Plugin::setInstance(), and clears it afterwards. This used to be an alias
 * mock of Unity\Plugin, which defines the class for the rest of the process
 * and so forced every test into a process of its own.
 */

covers(PositionController::class, ControllerTrait::class);

/** @return Position&MockInterface */
function positionDouble(int $id = 1, string $name = 'Chair')
{
    $p = Mockery::mock(Position::class);
    $p->shouldReceive('getId')->andReturn($id);
    $p->shouldReceive('getLongName')->andReturn($name);
    $p->shouldReceive('getShortDescription')->andReturn('Chairs');
    $p->shouldReceive('getSummary')->andReturn('Runs intergroup');
    $p->shouldReceive('getEmail')->andReturn('chair@example.com');
    $p->shouldReceive('getMinimumSobriety')->andReturn(24);
    $p->shouldReceive('getTermYears')->andReturn(3);
    $p->shouldReceive('getLink')->andReturn('/position/1');
    $p->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');
    return $p;
}

beforeEach(function () {
    $this->repo = Mockery::mock(PositionRepository::class);

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('get')->with(PositionRepository::class)->andReturn($this->repo);

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log');

    $this->controller = new PositionController($auditLogger);

    $this->makeRequest = fn (array $params = []): object => $this->createMockRequest(array_merge([
        'per_page' => 100,
        'page' => 1,
        'search' => '',
        '_integrity_start_time' => microtime(true),
        '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['positions:read']],
    ], $params));
});

afterEach(function () {
    UnityPlugin::setInstance(null);
});

it('returns a paginated list of positions', function () {
    $this->repo->shouldReceive('findAll')->once()->andReturn([positionDouble(1), positionDouble(2, 'Treasurer')]);
    $this->repo->shouldReceive('count')->once()->andReturn(2);

    $response = $this->controller->getPositions(($this->makeRequest)());

    expect($response->get_status())->toBe(200);
    $data = $response->get_data();
    expect($data['data'])->toHaveCount(2)
        ->and($data['data'][0]['long_name'])->toBe('Chair')
        ->and($data['data'][0]['minimum_sobriety'])->toBe(24)
        ->and($data['data'][0]['updated'])->toBe('2024-06-01T10:00:00.000Z')
        ->and($data['meta']['total'])->toBe(2);
});

it('returns 500 when listing positions fails', function () {
    $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

    expect($this->controller->getPositions(($this->makeRequest)())->get_status())->toBe(500);
});

it('returns a single position', function () {
    $this->repo->shouldReceive('findById')->once()->with(5)->andReturn(positionDouble(5, 'Sec'));

    $response = $this->controller->getPosition(($this->makeRequest)(['id' => 5]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['data']['long_name'])->toBe('Sec');
});

it('returns 404 when the position is missing', function () {
    $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

    expect($this->controller->getPosition(($this->makeRequest)(['id' => 9]))->get_status())->toBe(404);
});

it('batch-gets positions mapped by id and short-circuits on empty', function () {
    expect($this->controller->batchGetPositions($this->repo, []))->toBe([]);

    $this->repo->shouldReceive('findAll')->once()->andReturn([positionDouble(3), positionDouble(4)]);
    expect(array_keys($this->controller->batchGetPositions($this->repo, [3, 4])))->toBe([3, 4]);
});
