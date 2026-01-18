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
        Console.WriteLine($"  - {member.AnonymousName} ({member.Email})");
    }
}
else
{
    Console.WriteLine($"Error: {members.Error?.Code} - {members.Error?.Message}");
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
            Console.WriteLine($"  - {member.AnonymousName}");
            Console.WriteLine($"    Home Group: {member.HomeGroup.Title}");
            Console.WriteLine($"    Group Email: {member.HomeGroup.Email}");
            Console.WriteLine($"    Group Meetings: {member.HomeGroup.MeetingIds.Count}");
        }
        else
        {
            Console.WriteLine($"  - {member.AnonymousName} (Home Group ID: {member.HomeGroupId}, Name: {member.HomeGroupName})");
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

Console.WriteLine("Done!");