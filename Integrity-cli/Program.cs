// See https://aka.ms/new-console-template for more information
using TheBleedingDeacons.Unity.Client;
using TheBleedingDeacons.Unity.Models;

Console.WriteLine("Integrity CLI");
using var client = new UnityRestSharp(
    "http://unity-dev.local/",
    "int_64f2033a119dddd8f57395945e0369b4ead79b1a81143f3a31071b7cb39b27ef"
);

// Health Check
var status = await client.CheckHealthAsync();
Console.WriteLine($"Health Check - Unity Available: {status?.UnityAvailable}");
Console.WriteLine();

// Groups
Console.WriteLine("=== GROUPS ===");
var groups = await client.GetGroupsAsync(expandMeetings: true);
Console.WriteLine($"Status Code: {groups.StatusCode}");
if (groups.Success && groups.Data != null)
{
    Console.WriteLine($"Found {groups.Data.Count} groups");
    foreach (var group in groups.Data)
    {
        Console.WriteLine($"  - {group.Title} (Meetings: {group.Meetings.Count})");
        if (group.Contacts.Count > 0)
        {
            foreach (var contact in group.Contacts)
            {
                Console.WriteLine($"    Contact: {contact.Name} | {contact.Email} | {contact.Phone}");
            }
        }
    }
}
else
{
    Console.WriteLine($"Error: {groups.Error?.Code} - {groups.Error?.Message}");
}
Console.WriteLine();

// Online Meetings
Console.WriteLine("=== ONLINE MEETINGS ===");
var onlineMeetings = await client.GetMeetingsAsync(dayOfWeek: null, online: true);
Console.WriteLine($"Status Code: {onlineMeetings.StatusCode}");
if (onlineMeetings.Success && onlineMeetings.Data != null)
{
    Console.WriteLine($"Found {onlineMeetings.Data.Count} online meetings");
    foreach (var meeting in onlineMeetings.Data)
    {
        Console.WriteLine($"  - {meeting.Name}");
        if (meeting.Contacts.Count > 0)
        {
            foreach (var contact in meeting.Contacts)
            {
                Console.WriteLine($"    Contact: {contact.Name} | {contact.Email} | {contact.Phone}");
            }
        }
    }
}
else
{
    Console.WriteLine($"Error: {onlineMeetings.Error?.Code} - {onlineMeetings.Error?.Message}");
}
Console.WriteLine();

// Sunday Meetings (with debug info)
Console.WriteLine("=== SUNDAY MEETINGS ===");
var sundayMeetings = await client.GetMeetingsAsync(dayOfWeek: DayOfWeek.Sunday);
Console.WriteLine($"Status Code: {sundayMeetings.StatusCode}");
if (sundayMeetings.Success && sundayMeetings.Data != null)
{
    Console.WriteLine($"Found {sundayMeetings.Data.Count} meetings on Sunday");
    foreach (var meeting in sundayMeetings.Data.Take(10))
    {
        Console.WriteLine($"  - {meeting.Name} at {meeting.Time} (Day: {meeting.Day}, DayOfWeek: {meeting.DayOfWeek})");
        if (meeting.Contacts.Count > 0)
        {
            foreach (var contact in meeting.Contacts)
            {
                Console.WriteLine($"    Contact: {contact.Name} | {contact.Email} | {contact.Phone}");
            }
        }
    }
}
else
{
    Console.WriteLine($"Error: {sundayMeetings.Error?.Code} - {sundayMeetings.Error?.Message}");
}
Console.WriteLine();

// Monday Meetings
Console.WriteLine("=== MONDAY MEETINGS ===");
var mondayMeetings = await client.GetMeetingsAsync(dayOfWeek: DayOfWeek.Monday);
Console.WriteLine($"Status Code: {mondayMeetings.StatusCode}");
if (mondayMeetings.Success && mondayMeetings.Data != null)
{
    Console.WriteLine($"Found {mondayMeetings.Data.Count} meetings on Monday");
    foreach (var meeting in mondayMeetings.Data.Take(5))
    {
        Console.WriteLine($"  - {meeting.Name} at {meeting.Time}");
        if (meeting.Contacts.Count > 0)
        {
            foreach (var contact in meeting.Contacts)
            {
                Console.WriteLine($"    Contact: {contact.Name} | {contact.Email} | {contact.Phone}");
            }
        }
    }
}
else
{
    Console.WriteLine($"Error: {mondayMeetings.Error?.Code} - {mondayMeetings.Error?.Message}");
}
Console.WriteLine();

// All Meetings
Console.WriteLine("=== ALL MEETINGS ===");
var allMeetings = await client.GetMeetingsAsync();
Console.WriteLine($"Status Code: {allMeetings.StatusCode}");
if (allMeetings.Success && allMeetings.Data != null)
{
    Console.WriteLine($"Found {allMeetings.Data.Count} meetings");
    foreach (var meeting in allMeetings.Data)
    {
        Console.WriteLine($"  - {meeting.Name}");
        if (meeting.Contacts.Count > 0)
        {
            foreach (var contact in meeting.Contacts)
            {
                Console.WriteLine($"    Contact: {contact.Name} | {contact.Email} | {contact.Phone}");
            }
        }
    }
}
else
{
    Console.WriteLine($"Error: {allMeetings.Error?.Code} - {allMeetings.Error?.Message}");
}
Console.WriteLine();

// Members
Console.WriteLine("=== MEMBERS ===");
var members = await client.GetMembersAsync();
Console.WriteLine($"Status Code: {members.StatusCode}");
if (members.Success && members.Data != null)
{
    Console.WriteLine($"Found {members.Data.Count} members");
    foreach (var member in members.Data)
    {
        Console.WriteLine($"  - {member.AnonymousName} ({member.Email}) - GSR: {member.IsGsr}");
    }
}
else
{
    Console.WriteLine($"Error: {members.Error?.Code} - {members.Error?.Message}");
}
Console.WriteLine();

// GSR Members Only
Console.WriteLine("=== GSR MEMBERS ===");
var gsrMembers = await client.GetMembersAsync();
Console.WriteLine($"Status Code: {gsrMembers.StatusCode}");
if (gsrMembers.Success && gsrMembers.Data != null)
{
    var gsrs = gsrMembers.Data.Where(m => m.IsGsr).ToList();
    Console.WriteLine($"Found {gsrs.Count} GSR members out of {gsrMembers.Data.Count} total");
    foreach (var member in gsrs)
    {
        Console.WriteLine($"  - {member.AnonymousName} ({member.Email})");
    }
}
else
{
    Console.WriteLine($"Error: {gsrMembers.Error?.Code} - {gsrMembers.Error?.Message}");
}
Console.WriteLine();

// Members with Expanded Home Group
Console.WriteLine("=== MEMBERS WITH EXPANDED HOME GROUP ===");
var membersExpanded = await client.GetMembersAsync(expandHomeGroup: true);
Console.WriteLine($"Status Code: {membersExpanded.StatusCode}");
if (membersExpanded.Success && membersExpanded.Data != null)
{
    Console.WriteLine($"Found {membersExpanded.Data.Count} members");
    foreach (var member in membersExpanded.Data.Take(5))
    {
        if (member.HasExpandedHomeGroup && member.HomeGroup != null)
        {
            Console.WriteLine($"  - {member.AnonymousName} (GSR: {member.IsGsr})");
            Console.WriteLine($"    Home Group: {member.HomeGroup.Title}");
            Console.WriteLine($"    Group Email: {member.HomeGroup.Email}");
            Console.WriteLine($"    Group Meetings: {member.HomeGroup.MeetingIds.Count}");
        }
        else
        {
            Console.WriteLine($"  - {member.AnonymousName} (Home Group ID: {member.HomeGroupId}, Name: {member.HomeGroupName}, GSR: {member.IsGsr})");
        }
    }
}
else
{
    Console.WriteLine($"Error: {membersExpanded.Error?.Code} - {membersExpanded.Error?.Message}");
}
Console.WriteLine();

// Update Member
Console.WriteLine("=== UPDATE MEMBER ===");
if (members.Success && members.Data?.Count > 0)
{
    var targetMember = members.Data.First();
    var originalName = targetMember.AnonymousName;
    var originalGsr = targetMember.IsGsr;

    Console.WriteLine($"Target: {targetMember.AnonymousName} (ID: {targetMember.Id}, GSR: {targetMember.IsGsr})");

    // Update a single field
    Console.WriteLine();
    Console.WriteLine("  Updating anonymous name...");
    var updateResult = await client.UpdateMemberAsync(targetMember.Id, new UpdateMemberRequest
    {
        AnonymousName = $"{originalName} (updated)"
    });
    Console.WriteLine($"  Status Code: {updateResult.StatusCode}");

    if (updateResult.Success && updateResult.Data != null)
    {
        Console.WriteLine($"  Updated Name: {updateResult.Data.AnonymousName}");
        Console.WriteLine($"  GSR unchanged: {updateResult.Data.IsGsr} (was {originalGsr})");
    }
    else
    {
        Console.WriteLine($"  Error: {updateResult.Error?.Code} - {updateResult.Error?.Message}");
    }

    // Verify by re-fetching
    Console.WriteLine();
    Console.WriteLine("  Verifying update...");
    var verifyMember = await client.GetMemberAsync(targetMember.Id);
    if (verifyMember.Success && verifyMember.Data != null)
    {
        Console.WriteLine($"  Fetched Name: {verifyMember.Data.AnonymousName}");
    }

    // Update multiple fields at once
    Console.WriteLine();
    Console.WriteLine("  Updating multiple fields...");
    var multiUpdate = await client.UpdateMemberAsync(targetMember.Id, new UpdateMemberRequest
    {
        AnonymousName = originalName,
        IsGsr = !originalGsr,
        ShowAnonymousName = true
    });
    Console.WriteLine($"  Status Code: {multiUpdate.StatusCode}");

    if (multiUpdate.Success && multiUpdate.Data != null)
    {
        Console.WriteLine($"  Name restored: {multiUpdate.Data.AnonymousName}");
        Console.WriteLine($"  GSR toggled: {multiUpdate.Data.IsGsr} (was {originalGsr})");
        Console.WriteLine($"  Show Anonymous Name: {multiUpdate.Data.ShowAnonymousName}");
    }
    else
    {
        Console.WriteLine($"  Error: {multiUpdate.Error?.Code} - {multiUpdate.Error?.Message}");
    }

    // Restore original GSR value
    Console.WriteLine();
    Console.WriteLine("  Restoring original GSR value...");
    var restoreResult = await client.UpdateMemberAsync(targetMember.Id, new UpdateMemberRequest
    {
        IsGsr = originalGsr
    });
    Console.WriteLine($"  Status Code: {restoreResult.StatusCode}");
    if (restoreResult.Success && restoreResult.Data != null)
    {
        Console.WriteLine($"  GSR restored: {restoreResult.Data.IsGsr}");
    }
    else
    {
        Console.WriteLine($"  Error: {restoreResult.Error?.Code} - {restoreResult.Error?.Message}");
    }

    // Test update with invalid home group (should return 422)
    Console.WriteLine();
    Console.WriteLine("  Testing update with invalid home group...");
    var invalidUpdate = await client.UpdateMemberAsync(targetMember.Id, new UpdateMemberRequest
    {
        HomeGroupId = 999999
    });
    Console.WriteLine($"  Status Code: {invalidUpdate.StatusCode} (expected 422)");
    Console.WriteLine($"  Error: {invalidUpdate.Error?.Code} - {invalidUpdate.Error?.Message}");

    // Test update with non-existent member (should return 404)
    Console.WriteLine();
    Console.WriteLine("  Testing update of non-existent member...");
    var notFoundUpdate = await client.UpdateMemberAsync(999999, new UpdateMemberRequest
    {
        AnonymousName = "Ghost"
    });
    Console.WriteLine($"  Status Code: {notFoundUpdate.StatusCode} (expected 404)");
    Console.WriteLine($"  Error: {notFoundUpdate.Error?.Code} - {notFoundUpdate.Error?.Message}");
}
else
{
    Console.WriteLine("Skipped: requires members data");
}
Console.WriteLine();

// Positions
Console.WriteLine("=== POSITIONS ===");
var positions = await client.GetPositionsAsync();
Console.WriteLine($"Status Code: {positions.StatusCode}");
if (positions.Success && positions.Data != null)
{
    Console.WriteLine($"Found {positions.Data.Count} positions");
    foreach (var position in positions.Data)
    {
        Console.WriteLine($"  - {position.LongName}");
    }
}
else
{
    Console.WriteLine($"Error: {positions.Error?.Code} - {positions.Error?.Message}");
}
Console.WriteLine();

// Intergroup Meetings
Console.WriteLine("=== INTERGROUP MEETINGS ===");
var intergroupMeetings = await client.GetIntergroupMeetingsAsync();
Console.WriteLine($"Status Code: {intergroupMeetings.StatusCode}");
if (intergroupMeetings.Success && intergroupMeetings.Data != null)
{
    Console.WriteLine($"Found {intergroupMeetings.Data.Count} intergroup meetings");
    foreach (var meeting in intergroupMeetings.Data)
    {
        Console.WriteLine($"  - ID: {meeting.Id}, Date: {meeting.Date}, GroupAttendees: {meeting.GroupAttendees.Count}, OfficersAttending: {meeting.OfficersAttending.Count}");
        if (meeting.GroupAttendees.Count > 0)
        {
            Console.WriteLine($"    Group Attendees:");
            foreach (var attendee in meeting.GroupAttendees.Take(3))
            {
                Console.WriteLine($"      - {attendee.Name}");
            }
            if (meeting.GroupAttendees.Count > 3)
            {
                Console.WriteLine($"      ... and {meeting.GroupAttendees.Count - 3} more");
            }
        }
        if (meeting.OfficersAttending.Count > 0)
        {
            Console.WriteLine($"    Officers Attending:");
            foreach (var officer in meeting.OfficersAttending.Take(3))
            {
                Console.WriteLine($"      - {officer.Name}");
            }
            if (meeting.OfficersAttending.Count > 3)
            {
                Console.WriteLine($"      ... and {meeting.OfficersAttending.Count - 3} more");
            }
        }
    }
}
else
{
    Console.WriteLine($"Error: {intergroupMeetings.Error?.Code} - {intergroupMeetings.Error?.Message}");
}
Console.WriteLine();

// Intergroup Meetings with Date Filter - Past 90 days
Console.WriteLine("=== INTERGROUP MEETINGS (LAST 90 DAYS) ===");
var recentIntergroupMeetings = await client.GetIntergroupMeetingsAsync(
    dateFrom: DateOnly.FromDateTime(DateTime.Today.AddDays(-90)),
    dateTo: DateOnly.FromDateTime(DateTime.Today)
);
Console.WriteLine($"Status Code: {recentIntergroupMeetings.StatusCode}");
if (recentIntergroupMeetings.Success && recentIntergroupMeetings.Data != null)
{
    Console.WriteLine($"Found {recentIntergroupMeetings.Data.Count} intergroup meetings in the last 30 days");
    foreach (var meeting in recentIntergroupMeetings.Data)
    {
        Console.WriteLine($"  - {meeting.Date}: {meeting.GroupAttendees.Count} group attendees, {meeting.OfficersAttending.Count} officers");
    }
}
else
{
    Console.WriteLine($"Error: {recentIntergroupMeetings.Error?.Code} - {recentIntergroupMeetings.Error?.Message}");
}
Console.WriteLine();

// Intergroup Meetings - All future meetings (dateTo: null means no upper bound)
Console.WriteLine("=== UPCOMING INTERGROUP MEETINGS ===");
var upcomingIntergroupMeetings = await client.GetIntergroupMeetingsAsync(
    dateFrom: DateOnly.FromDateTime(DateTime.Today),
    dateTo: null  // No upper date limit - gets all future meetings
);
Console.WriteLine($"Status Code: {upcomingIntergroupMeetings.StatusCode}");
if (upcomingIntergroupMeetings.Success && upcomingIntergroupMeetings.Data != null)
{
    Console.WriteLine($"Found {upcomingIntergroupMeetings.Data.Count} upcoming intergroup meetings");
    foreach (var meeting in upcomingIntergroupMeetings.Data)
    {
        Console.WriteLine($"  - {meeting.Date}: {meeting.GroupAttendees.Count} group attendees, {meeting.OfficersAttending.Count} officers");
    }
}
else
{
    Console.WriteLine($"Error: {upcomingIntergroupMeetings.Error?.Code} - {upcomingIntergroupMeetings.Error?.Message}");
}
Console.WriteLine();

// Register Attendee for Intergroup Meeting
Console.WriteLine("=== REGISTER ATTENDEE FOR INTERGROUP MEETING ===");
if (intergroupMeetings.Success && intergroupMeetings.Data?.Count > 0
    && members.Success && members.Data?.Count > 0)
{
    var targetMeeting = intergroupMeetings.Data.First();
    var targetMember = members.Data.First();

    Console.WriteLine($"Registering member '{targetMember.AnonymousName}' (ID: {targetMember.Id}) for intergroup meeting ID: {targetMeeting.Id} ({targetMeeting.Date})");

    var registerResult = await client.RegisterAttendeeAsync(
        targetMeeting.Id,
        targetMember.Id,
        meetingGroup: "Saturday Morning Group",
        gsrName: targetMember.AnonymousName,
        gsrProxy: false
    );
    Console.WriteLine($"Status Code: {registerResult.StatusCode}");

    if (registerResult.Success && registerResult.Data != null)
    {
        Console.WriteLine($"  Registered: {registerResult.Data.Registered}");
        Console.WriteLine($"  Member: {registerResult.Data.MemberName} (ID: {registerResult.Data.MemberId})");
        Console.WriteLine($"  Meeting ID: {registerResult.Data.IntergroupMeetingId}");
        Console.WriteLine($"  Meeting/Group: {registerResult.Data.MeetingGroup}");
        Console.WriteLine($"  GSR Name: {registerResult.Data.GsrName}");
        Console.WriteLine($"  GSR Proxy: {registerResult.Data.GsrProxy}");
        if (registerResult.Data.GsrProxy)
            Console.WriteLine($"  Proxy Name: {registerResult.Data.GsrProxyName}");
    }
    else
    {
        Console.WriteLine($"  Result: {registerResult.Error?.Code} - {registerResult.Error?.Message}");
    }

    // Verify by re-fetching the meeting
    Console.WriteLine();
    Console.WriteLine("  Verifying registration...");
    var verifyMeeting = await client.GetIntergroupMeetingAsync(targetMeeting.Id);
    if (verifyMeeting.Success && verifyMeeting.Data != null)
    {
        var isAttending = verifyMeeting.Data.GroupAttendeeIds.Contains(targetMember.Id);
        Console.WriteLine($"  Member {targetMember.Id} in attendees: {isAttending}");
        Console.WriteLine($"  Total group attendees: {verifyMeeting.Data.GroupAttendees.Count}");
    }

    // Test duplicate registration (should return 409)
    Console.WriteLine();
    Console.WriteLine("  Testing duplicate registration...");
    var duplicateResult = await client.RegisterAttendeeAsync(
        targetMeeting.Id,
        targetMember.Id,
        meetingGroup: "Saturday Morning Group",
        gsrName: targetMember.AnonymousName
    );
    Console.WriteLine($"  Status Code: {duplicateResult.StatusCode} (expected 409)");
    Console.WriteLine($"  Error: {duplicateResult.Error?.Code} - {duplicateResult.Error?.Message}");

    // Test registration with a proxy
    Console.WriteLine();
    Console.WriteLine("=== REGISTER ATTENDEE WITH PROXY ===");
    if (members.Data.Count > 1)
    {
        var proxyMember = members.Data[1];
        Console.WriteLine($"Registering member '{proxyMember.AnonymousName}' (ID: {proxyMember.Id}) with proxy for meeting ID: {targetMeeting.Id}");

        var proxyResult = await client.RegisterAttendeeAsync(
            targetMeeting.Id,
            proxyMember.Id,
            meetingGroup: "Tuesday Night Big Book",
            gsrName: proxyMember.AnonymousName,
            gsrProxy: true,
            gsrProxyName: "Jane S."
        );
        Console.WriteLine($"Status Code: {proxyResult.StatusCode}");

        if (proxyResult.Success && proxyResult.Data != null)
        {
            Console.WriteLine($"  Registered: {proxyResult.Data.Registered}");
            Console.WriteLine($"  GSR Name: {proxyResult.Data.GsrName}");
            Console.WriteLine($"  GSR Proxy: {proxyResult.Data.GsrProxy}");
            Console.WriteLine($"  Proxy Name: {proxyResult.Data.GsrProxyName}");
        }
        else
        {
            Console.WriteLine($"  Result: {proxyResult.Error?.Code} - {proxyResult.Error?.Message}");
        }

        // Unregister the proxy member
        Console.WriteLine();
        Console.WriteLine($"  Unregistering proxy member '{proxyMember.AnonymousName}'...");
        var unregisterProxy = await client.UnregisterAttendeeAsync(targetMeeting.Id, proxyMember.Id);
        Console.WriteLine($"  Status Code: {unregisterProxy.StatusCode}");
        if (unregisterProxy.Success && unregisterProxy.Data != null)
        {
            Console.WriteLine($"  Registered: {unregisterProxy.Data.Registered}");
        }
        else
        {
            Console.WriteLine($"  Result: {unregisterProxy.Error?.Code} - {unregisterProxy.Error?.Message}");
        }
    }

    // Unregister the attendee
    Console.WriteLine();
    Console.WriteLine("=== UNREGISTER ATTENDEE FROM INTERGROUP MEETING ===");
    Console.WriteLine($"Unregistering member '{targetMember.AnonymousName}' (ID: {targetMember.Id}) from intergroup meeting ID: {targetMeeting.Id}");

    var unregisterResult = await client.UnregisterAttendeeAsync(targetMeeting.Id, targetMember.Id);
    Console.WriteLine($"Status Code: {unregisterResult.StatusCode}");

    if (unregisterResult.Success && unregisterResult.Data != null)
    {
        Console.WriteLine($"  Registered: {unregisterResult.Data.Registered}");
        Console.WriteLine($"  Member ID: {unregisterResult.Data.MemberId}");
        Console.WriteLine($"  Meeting ID: {unregisterResult.Data.IntergroupMeetingId}");
    }
    else
    {
        Console.WriteLine($"  Result: {unregisterResult.Error?.Code} - {unregisterResult.Error?.Message}");
    }

    // Test unregister when not registered (should return 404)
    Console.WriteLine();
    Console.WriteLine("  Testing unregister when not registered...");
    var notRegisteredResult = await client.UnregisterAttendeeAsync(targetMeeting.Id, targetMember.Id);
    Console.WriteLine($"  Status Code: {notRegisteredResult.StatusCode} (expected 404)");
    Console.WriteLine($"  Error: {notRegisteredResult.Error?.Code} - {notRegisteredResult.Error?.Message}");
}
else
{
    Console.WriteLine("Skipped: requires intergroup meetings and members data");
}
Console.WriteLine();

Console.WriteLine("Done!");