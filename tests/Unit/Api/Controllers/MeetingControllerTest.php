<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Auth\AuditLogger;
use Mockery;
use Mockery\MockInterface;
use Unity\Core\Interfaces\Container;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Plugin as UnityPlugin;

/*
 * Tests for MeetingController's REST handlers.
 *
 * Unity's interfaces are loaded from the sibling checkout (see
 * tests/bootstrap.php), so the doubles here are checked against the real
 * signatures. The controller reaches its repository through
 * Unity\Plugin::getContainer(), so each test installs a Plugin around a
 * container double with Unity's own Plugin::setInstance(), and clears it
 * afterwards.
 *
 * This used to be an alias mock of Unity\Plugin, from before Unity was on this
 * suite's classpath. An alias defines the class for the rest of the process,
 * so every test had to run in a process of its own — which Pest cannot do, and
 * which setInstance() makes unnecessary.
 */

covers(MeetingController::class, ControllerTrait::class);

/** @return Meeting&MockInterface */
function meetingControllerMeetingDouble(int $id = 1, string $name = 'Morning')
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

beforeEach(function () {
    $this->repo = Mockery::mock(MeetingRepository::class);

    $container = Mockery::mock(Container::class);
    $container->shouldReceive('get')->with(MeetingRepository::class)->andReturn($this->repo);

    UnityPlugin::setInstance(UnityPlugin::create($container));

    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('log');

    $this->controller = new MeetingController($auditLogger);

    $this->makeRequest = fn (array $params = []): object => $this->createMockRequest(array_merge([
        'per_page' => 100,
        'page' => 1,
        'day' => null,
        'online' => null,
        'group_id' => null,
        'search' => '',
        '_integrity_start_time' => microtime(true),
        '_integrity_key_data' => ['api_key_id' => 1, 'permissions' => ['meetings:read']],
    ], $params));
});

afterEach(function () {
    UnityPlugin::setInstance(null);
});

it('returns a paginated, transformed list of meetings', function () {
    $this->repo->shouldReceive('findAll')->once()->andReturn([
        meetingControllerMeetingDouble(1),
        meetingControllerMeetingDouble(2, 'Evening'),
    ]);
    $this->repo->shouldReceive('count')->once()->andReturn(2);

    $response = $this->controller->getMeetings(($this->makeRequest)());

    expect($response->get_status())->toBe(200);
    $data = $response->get_data();
    expect($data['success'])->toBeTrue()
        ->and($data['data'])->toHaveCount(2)
        ->and($data['data'][0]['id'])->toBe(1)
        ->and($data['data'][0]['name'])->toBe('Morning')
        ->and($data['data'][0]['updated'])->toBe('2024-06-01T10:00:00.000Z')
        ->and($data['meta']['total'])->toBe(2)
        ->and($data['meta']['page'])->toBe(1);
});

it('filters meetings by day', function () {
    $this->repo->shouldReceive('findByDay')->once()->with(3, Mockery::type('array'))->andReturn([meetingControllerMeetingDouble()]);
    $this->repo->shouldReceive('count')->once()->andReturn(1);

    $response = $this->controller->getMeetings(($this->makeRequest)(['day' => 3]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['data'])->toHaveCount(1);
});

it('filters online meetings', function () {
    // findOnline is called twice: once for the page, once for the count.
    $this->repo->shouldReceive('findOnline')->twice()->andReturn([meetingControllerMeetingDouble()]);

    $response = $this->controller->getMeetings(($this->makeRequest)(['online' => 'true']));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['meta']['total'])->toBe(1);
});

it('filters in-person meetings', function () {
    $this->repo->shouldReceive('findInPerson')->twice()->andReturn([
        meetingControllerMeetingDouble(),
        meetingControllerMeetingDouble(2),
    ]);

    $response = $this->controller->getMeetings(($this->makeRequest)(['online' => 'false']));

    expect($response->get_data()['meta']['total'])->toBe(2);
});

it('returns 500 when the meeting repository fails', function () {
    $this->repo->shouldReceive('findAll')->andThrow(new \RuntimeException('boom'));

    $response = $this->controller->getMeetings(($this->makeRequest)());

    expect($response->get_status())->toBe(500)
        ->and($response->get_data()['success'])->toBeFalse()
        ->and($response->get_data()['error']['code'])->toBe('internal_error');
});

it('returns a single meeting', function () {
    $this->repo->shouldReceive('findById')->once()->with(5)->andReturn(meetingControllerMeetingDouble(5, 'Noon'));

    $response = $this->controller->getMeeting(($this->makeRequest)(['id' => 5]));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['data']['id'])->toBe(5)
        ->and($response->get_data()['data']['name'])->toBe('Noon');
});

it('returns 404 when the meeting is missing', function () {
    $this->repo->shouldReceive('findById')->once()->with(9)->andReturn(null);

    $response = $this->controller->getMeeting(($this->makeRequest)(['id' => 9]));

    expect($response->get_status())->toBe(404)
        ->and($response->get_data()['error']['code'])->toBe('not_found');
});

it('batch-gets meetings mapped by id and short-circuits on empty', function () {
    expect($this->controller->batchGetMeetings($this->repo, []))->toBe([]);

    $this->repo->shouldReceive('findAll')->once()->andReturn([
        meetingControllerMeetingDouble(3),
        meetingControllerMeetingDouble(4),
    ]);
    $map = $this->controller->batchGetMeetings($this->repo, [3, 4]);

    expect(array_keys($map))->toBe([3, 4]);
});

it('includes a location in the transform when present', function () {
    $location = Mockery::mock(Location::class);
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

    $meeting = meetingControllerMeetingDouble();
    // Override getLocation to return the location.
    $meeting = Mockery::mock(Meeting::class);
    foreach (
        [
        'getId' => 1, 'getName' => 'M', 'getSlug' => 'm', 'getUrl' => '', 'getDay' => 1,
        'getDayOfWeek' => 'Mon', 'getTime' => '', 'getEndTime' => '', 'getTypes' => [],
        'getState' => '', 'isOnline' => false, 'getOnlineLink' => '', 'getOnlineNotes' => '',
        'getContacts' => [], 'getMeta' => [], 'getUpdated' => '',
        ] as $method => $value
    ) {
        $meeting->shouldReceive($method)->andReturn($value);
    }
    $meeting->shouldReceive('getLocation')->andReturn($location);

    $this->repo->shouldReceive('findById')->with(1)->andReturn($meeting);

    $response = $this->controller->getMeeting(($this->makeRequest)(['id' => 1]));
    $data = $response->get_data()['data'];

    expect($data['location']['id'])->toBe(11)
        ->and($data['location']['formatted_address'])->toBe('1 St, London');
});
