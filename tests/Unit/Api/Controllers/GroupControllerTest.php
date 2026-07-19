<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\GroupController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use ReflectionClass;

/**
 * Tests for GroupController's group transformation.
 *
 * transformGroup() was a RestController method when these tests were first
 * written and moved here with the controller split; the tests moved with it.
 * It is private, so it is exercised through reflection — the public surface
 * that reaches it needs a full WP_REST_Request round trip, which would test
 * the routing rather than the transformation.
 */
class GroupControllerTest extends TestCase
{
    private GroupController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new GroupController(Mockery::mock(AuditLogger::class));
    }

    /**
     * Invoke the private transformGroup().
     *
     * @param object              $group  Group double
     * @param array<int, string>  $expand Relations to inline
     * @return array<string, mixed>
     */
    private function transformGroup(object $group, array $expand = []): array
    {
        $method = (new ReflectionClass(GroupController::class))->getMethod('transformGroup');

        return $method->invoke($this->controller, $group, $expand);
    }

    /**
     * @test
     */
    public function transformGroup_returns_expected_fields(): void
    {
        $group = Mockery::mock('Unity\Groups\Interfaces\Group');
        $group->shouldReceive('getId')->andReturn(1);
        $group->shouldReceive('getTitle')->andReturn('Test Group');
        $group->shouldReceive('getEmail')->andReturn('test@example.com');
        $group->shouldReceive('getPhone')->andReturn('555-1234');
        $group->shouldReceive('getWebsite')->andReturn('https://example.com');
        $group->shouldReceive('getLink')->andReturn('/group/1');
        $group->shouldReceive('getGroupNotes')->andReturn('Notes');
        $group->shouldReceive('getDistrictId')->andReturn(42);
        $group->shouldReceive('getLastContact')->andReturn('2024-01-01');
        $group->shouldReceive('getMeetings')->andReturn([]);
        $group->shouldReceive('getContacts')->andReturn([]);
        $group->shouldReceive('getVenmo')->andReturn('@TestGroup');
        $group->shouldReceive('getPaypal')->andReturn('');
        $group->shouldReceive('getSquare')->andReturn('');
        $group->shouldReceive('hasContributionOptions')->andReturn(true);
        $group->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');

        $result = $this->transformGroup($group);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test Group', $result['title']);
        $this->assertArrayHasKey('contribution_options', $result);
        $this->assertTrue($result['contribution_options']['has_options']);
        $this->assertArrayHasKey('meeting_ids', $result);
        $this->assertArrayHasKey('updated', $result);
    }

    /**
     * @test
     */
    public function transformGroup_expands_meetings_when_requested(): void
    {
        $meeting = Mockery::mock('Unity\Meetings\Interfaces\Meeting');
        $meeting->shouldReceive('getId')->andReturn(100);
        $meeting->shouldReceive('getName')->andReturn('Morning');
        $meeting->shouldReceive('getSlug')->andReturn('morning');
        $meeting->shouldReceive('getLocation')->andReturn(null);
        $meeting->shouldReceive('getUrl')->andReturn('');
        $meeting->shouldReceive('getDay')->andReturn(1);
        $meeting->shouldReceive('getDayOfWeek')->andReturn('Monday');
        $meeting->shouldReceive('getTime')->andReturn('07:00');
        $meeting->shouldReceive('getEndTime')->andReturn('08:00');
        $meeting->shouldReceive('getTypes')->andReturn([]);
        $meeting->shouldReceive('getState')->andReturn('active');
        $meeting->shouldReceive('isOnline')->andReturn(false);
        $meeting->shouldReceive('getOnlineLink')->andReturn('');
        $meeting->shouldReceive('getOnlineNotes')->andReturn('');
        $meeting->shouldReceive('getContacts')->andReturn([]);
        $meeting->shouldReceive('getMeta')->andReturn([]);
        $meeting->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');

        $group = Mockery::mock('Unity\Groups\Interfaces\Group');
        $group->shouldReceive('getId')->andReturn(1);
        $group->shouldReceive('getTitle')->andReturn('Test');
        $group->shouldReceive('getEmail')->andReturn('');
        $group->shouldReceive('getPhone')->andReturn('');
        $group->shouldReceive('getWebsite')->andReturn('');
        $group->shouldReceive('getLink')->andReturn('');
        $group->shouldReceive('getGroupNotes')->andReturn('');
        $group->shouldReceive('getDistrictId')->andReturn(null);
        $group->shouldReceive('getLastContact')->andReturn(null);
        $group->shouldReceive('getMeetings')->andReturn([$meeting]);
        $group->shouldReceive('getContacts')->andReturn([]);
        $group->shouldReceive('getVenmo')->andReturn('');
        $group->shouldReceive('getPaypal')->andReturn('');
        $group->shouldReceive('getSquare')->andReturn('');
        $group->shouldReceive('hasContributionOptions')->andReturn(false);
        $group->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');

        $result = $this->transformGroup($group, ['meetings']);

        $this->assertArrayHasKey('meetings', $result);
        $this->assertArrayNotHasKey('meeting_ids', $result);
        $this->assertIsArray($result['meetings']);
        $this->assertEquals(100, $result['meetings'][0]['id']);
    }
}
