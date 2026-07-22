<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\GroupController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * Tests for GroupController's REST handlers (transformGroup is covered
 * separately in GroupControllerTest).
 *
 * @covers \Integrity\Api\Controllers\GroupController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GroupControllerHandlersTest extends TestCase
{
    /** @var GroupRepository&\Mockery\MockInterface */
    private $repo;

    private GroupController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(GroupRepository::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(GroupRepository::class)->andReturn($this->repo);

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log');

        $this->controller = new GroupController($auditLogger);
    }

    private function request(array $params = []): object
    {
        return $this->createMockRequest(array_merge([
            'per_page' => 100,
            'page' => 1,
            'search' => '',
            'district_id' => null,
            'expand' => '',
            '_integrity_start_time' => microtime(true),
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['groups:read']],
        ], $params));
    }

    /** @return Group&\Mockery\MockInterface */
    private function group(int $id = 1, string $title = 'Tuesday Group')
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

    /**
     * @test
     */
    public function get_groups_returns_a_paginated_list(): void
    {
        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->group(1), $this->group(2, 'Thursday')]);
        $this->repo->shouldReceive('count')->once()->andReturn(2);

        $response = $this->controller->getGroups($this->request());

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertCount(2, $data['data']);
        $this->assertSame('Tuesday Group', $data['data'][0]['title']);
        $this->assertArrayHasKey('meeting_ids', $data['data'][0]);
        $this->assertSame(2, $data['meta']['total']);
    }

    /**
     * @test
     */
    public function get_groups_filters_by_district(): void
    {
        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->group()]);
        $this->repo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getGroups($this->request(['district_id' => 42]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $response->get_data()['meta']['total']);
    }

    /**
     * @test
     */
    public function get_groups_returns_500_on_failure(): void
    {
        $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

        $this->assertSame(500, $this->controller->getGroups($this->request())->get_status());
    }

    /**
     * @test
     */
    public function get_group_returns_a_single_group(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(5)->andReturn($this->group(5, 'Friday'));

        $response = $this->controller->getGroup($this->request(['id' => 5]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Friday', $response->get_data()['data']['title']);
    }

    /**
     * @test
     */
    public function get_group_returns_404_when_missing(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $this->assertSame(404, $this->controller->getGroup($this->request(['id' => 9]))->get_status());
    }

    /**
     * @test
     */
    public function batch_get_groups_maps_by_id_and_short_circuits(): void
    {
        $this->assertSame([], $this->controller->batchGetGroups($this->repo, []));

        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->group(3), $this->group(4)]);
        $this->assertSame([3, 4], array_keys($this->controller->batchGetGroups($this->repo, [3, 4])));
    }
}
