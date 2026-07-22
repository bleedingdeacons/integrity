<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\PositionController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use Unity\Core\Interfaces\Container;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Tests for PositionController's REST handlers.
 *
 * @covers \Integrity\Api\Controllers\PositionController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PositionControllerTest extends TestCase
{
    /** @var PositionRepository&\Mockery\MockInterface */
    private $repo;

    private PositionController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(PositionRepository::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(PositionRepository::class)->andReturn($this->repo);

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log');

        $this->controller = new PositionController($auditLogger);
    }

    private function request(array $params = []): object
    {
        return $this->createMockRequest(array_merge([
            'per_page' => 100,
            'page' => 1,
            'search' => '',
            '_integrity_start_time' => microtime(true),
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['positions:read']],
        ], $params));
    }

    /** @return Position&\Mockery\MockInterface */
    private function position(int $id = 1, string $name = 'Chair')
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

    /**
     * @test
     */
    public function get_positions_returns_a_paginated_list(): void
    {
        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->position(1), $this->position(2, 'Treasurer')]);
        $this->repo->shouldReceive('count')->once()->andReturn(2);

        $response = $this->controller->getPositions($this->request());

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertCount(2, $data['data']);
        $this->assertSame('Chair', $data['data'][0]['long_name']);
        $this->assertSame(24, $data['data'][0]['minimum_sobriety']);
        $this->assertSame('2024-06-01T10:00:00.000Z', $data['data'][0]['updated']);
        $this->assertSame(2, $data['meta']['total']);
    }

    /**
     * @test
     */
    public function get_positions_returns_500_on_failure(): void
    {
        $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

        $this->assertSame(500, $this->controller->getPositions($this->request())->get_status());
    }

    /**
     * @test
     */
    public function get_position_returns_a_single_position(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(5)->andReturn($this->position(5, 'Sec'));

        $response = $this->controller->getPosition($this->request(['id' => 5]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Sec', $response->get_data()['data']['long_name']);
    }

    /**
     * @test
     */
    public function get_position_returns_404_when_missing(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $this->assertSame(404, $this->controller->getPosition($this->request(['id' => 9]))->get_status());
    }

    /**
     * @test
     */
    public function batch_get_positions_maps_by_id_and_short_circuits(): void
    {
        $this->assertSame([], $this->controller->batchGetPositions($this->repo, []));

        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->position(3), $this->position(4)]);
        $this->assertSame([3, 4], array_keys($this->controller->batchGetPositions($this->repo, [3, 4])));
    }
}
