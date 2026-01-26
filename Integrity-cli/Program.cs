// See https://aka.ms/new-console-template for more information
using Integrity.Client;

Console.WriteLine("Integrity CLI");
using var client = new IntegrityClient(
    "http://unity-dev.local/",
    "int_4b933f9dcfef5c90b59be21c8c603c6ae94f1817367d51297c54a075e416a51a"
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

// Intergroup Meetings with Date Filter - Past 30 days
Console.WriteLine("=== INTERGROUP MEETINGS (LAST 30 DAYS) ===");
var recentIntergroupMeetings = await client.GetIntergroupMeetingsAsync(
    dateFrom: DateOnly.FromDateTime(DateTime.Today.AddDays(-30)),
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

Console.WriteLine("Done!");