<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\MeetingController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use Unity\Core\Interfaces\Container;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Tests for MeetingController's REST handlers.
 *
 * Unity is not autoloaded in this suite, so its interfaces are Mockery
 * string doubles and Unity\Plugin's static container accessor is an alias
 * mock. Each test runs in its own process so the alias does not leak.
 *
 * @covers \Integrity\Api\Controllers\MeetingController
 * @covers \Integrity\Api\Controllers\ControllerTrait
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MeetingControllerTest extends TestCase
{
    /** @var MeetingRepository&\Mockery\MockInterface */
    private $repo;

    private MeetingController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = Mockery::mock(MeetingRepository::class);

        $container = Mockery::mock(Container::class);
        $container->shouldReceive('get')->with(MeetingRepository::class)->andReturn($this->repo);

        $plugin = Mockery::mock('alias:Unity\Plugin');
        $plugin->shouldReceive('getContainer')->andReturn($container);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log');

        $this->controller = new MeetingController($auditLogger);
    }

    private function request(array $params = []): object
    {
        return $this->createMockRequest(array_merge([
            'per_page' => 100,
            'page' => 1,
            'day' => null,
            'online' => null,
            'group_id' => null,
            'search' => '',
            '_integrity_start_time' => microtime(true),
            '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['meetings:read']],
        ], $params));
    }

    /** @return Meeting&\Mockery\MockInterface */
    private function meeting(int $id = 1, string $name = 'Morning')
    {
        $m = Mockery::mock(Meeting::class);
        $m->shouldReceive('getId')->andReturn($id);
        $m->shouldReceive('getName')->andReturn($name);
        $m->shouldReceive('getSlug')->andReturn('morning');
        $m->shouldReceive('getLocation')->andReturn(null);
        $m->shouldReceive('getUrl')->andReturn('');
        $m->shouldReceive('getDay')->andReturn(1);
        $m->shouldReceive('getDayOfWeek')->andReturn('Monday');
        $m->shouldReceive('getTime')->andReturn('07:00');
        $m->shouldReceive('getEndTime')->andReturn('08:00');
        $m->shouldReceive('getTypes')->andReturn(['O']);
        $m->shouldReceive('getState')->andReturn('active');
        $m->shouldReceive('isOnline')->andReturn(false);
        $m->shouldReceive('getOnlineLink')->andReturn('');
        $m->shouldReceive('getOnlineNotes')->andReturn('');
        $m->shouldReceive('getContacts')->andReturn([]);
        $m->shouldReceive('getMeta')->andReturn([]);
        $m->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');
        return $m;
    }

    /**
     * @test
     */
    public function get_meetings_returns_a_paginated_transformed_list(): void
    {
        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->meeting(1), $this->meeting(2, 'Evening')]);
        $this->repo->shouldReceive('count')->once()->andReturn(2);

        $response = $this->controller->getMeetings($this->request());

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
        $this->assertSame(1, $data['data'][0]['id']);
        $this->assertSame('Morning', $data['data'][0]['name']);
        $this->assertSame('2024-06-01T10:00:00.000Z', $data['data'][0]['updated']);
        $this->assertSame(2, $data['meta']['total']);
        $this->assertSame(1, $data['meta']['page']);
    }

    /**
     * @test
     */
    public function get_meetings_filters_by_day(): void
    {
        $this->repo->shouldReceive('findByDay')->once()->with(3, Mockery::type('array'))->andReturn([$this->meeting()]);
        $this->repo->shouldReceive('count')->once()->andReturn(1);

        $response = $this->controller->getMeetings($this->request(['day' => 3]));

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $response->get_data()['data']);
    }

    /**
     * @test
     */
    public function get_meetings_filters_online_meetings(): void
    {
        // findOnline is called twice: once for the page, once for the count.
        $this->repo->shouldReceive('findOnline')->twice()->andReturn([$this->meeting()]);

        $response = $this->controller->getMeetings($this->request(['online' => 'true']));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $response->get_data()['meta']['total']);
    }

    /**
     * @test
     */
    public function get_meetings_filters_in_person_meetings(): void
    {
        $this->repo->shouldReceive('findInPerson')->twice()->andReturn([$this->meeting(), $this->meeting(2)]);

        $response = $this->controller->getMeetings($this->request(['online' => 'false']));

        $this->assertSame(2, $response->get_data()['meta']['total']);
    }

    /**
     * @test
     */
    public function get_meetings_returns_500_on_repository_failure(): void
    {
        $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

        $response = $this->controller->getMeetings($this->request());

        $this->assertSame(500, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
        $this->assertSame('internal_error', $response->get_data()['error']['code']);
    }

    /**
     * @test
     */
    public function get_meeting_returns_a_single_meeting(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(5)->andReturn($this->meeting(5, 'Noon'));

        $response = $this->controller->getMeeting($this->request(['id' => 5]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(5, $response->get_data()['data']['id']);
        $this->assertSame('Noon', $response->get_data()['data']['name']);
    }

    /**
     * @test
     */
    public function get_meeting_returns_404_when_missing(): void
    {
        $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

        $response = $this->controller->getMeeting($this->request(['id' => 9]));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('not_found', $response->get_data()['error']['code']);
    }

    /**
     * @test
     */
    public function batch_get_meetings_maps_by_id_and_short_circuits_on_empty(): void
    {
        $this->assertSame([], $this->controller->batchGetMeetings($this->repo, []));

        $this->repo->shouldReceive('findAll')->once()->andReturn([$this->meeting(3), $this->meeting(4)]);
        $map = $this->controller->batchGetMeetings($this->repo, [3, 4]);

        $this->assertSame([3, 4], array_keys($map));
    }

    /**
     * @test
     */
    public function transform_includes_a_location_when_present(): void
    {
        $location = Mockery::mock(\Unity\Locations\Interfaces\Location::class);
        $location->shouldReceive('getId')->andReturn(11);
        $location->shouldReceive('getName')->andReturn('Hall');
        $location->shouldReceive('getAddress')->andReturn('1 St');
        $location->shouldReceive('getCity')->andReturn('London');
        $location->shouldReceive('getState')->andReturn('');
        $location->shouldReceive('getPostalCode')->andReturn('SW1');
        $location->shouldReceive('getCountry')->andReturn('UK');
        $location->shouldReceive('getRegion')->andReturn('');
        $location->shouldReceive('getNotes')->andReturn('');
        $location->shouldReceive('getLink')->andReturn('');
        $location->shouldReceive('getLatitude')->andReturn(51.5);
        $location->shouldReceive('getLongitude')->andReturn(-0.1);
        $location->shouldReceive('getTimezone')->andReturn('Europe/London');
        $location->shouldReceive('getFormattedAddress')->andReturn('1 St, London');
        $location->shouldReceive('getUpdated')->andReturn('');

        $meeting = $this->meeting();
        // Override getLocation to return the location.
        $meeting = Mockery::mock(Meeting::class);
        foreach ([
            'getId' => 1, 'getName' => 'M', 'getSlug' => 'm', 'getUrl' => '', 'getDay' => 1,
            'getDayOfWeek' => 'Mon', 'getTime' => '', 'getEndTime' => '', 'getTypes' => [],
            'getState' => '', 'isOnline' => false, 'getOnlineLink' => '', 'getOnlineNotes' => '',
            'getContacts' => [], 'getMeta' => [], 'getUpdated' => '',
        ] as $method => $value) {
            $meeting->shouldReceive($method)->andReturn($value);
        }
        $meeting->shouldReceive('getLocation')->andReturn($location);

        $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);

        $response = $this->controller->getMeeting($this->request(['id' => 1]));
        $data = $response->get_data()['data'];

        $this->assertSame(11, $data['location']['id']);
        $this->assertSame('1 St, London', $data['location']['formatted_address']);
    }
}
