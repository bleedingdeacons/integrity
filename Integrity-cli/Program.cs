// See https://aka.ms/new-console-template for more information
using Integrity.Client;

Console.WriteLine("Integrity CLI");
using var client = new IntegrityClient(
    "http://unity-dev.local/",
    "int_4b933f9dcfef5c90b59be21c8c603c6ae94f1817367d51297c54a075e416a51a"
);

var status = await client.CheckHealthAsync();

Console.WriteLine(status.UnityAvailable);

var groups = await client.GetGroupsAsync();

Console.WriteLine(groups.StatusCode);

foreach (var group in groups.Data)
{
    Console.WriteLine(group.Title);
}

var onlineMeetings = await client.GetMeetingsAsync(day: null, online: true);

Console.WriteLine(onlineMeetings.StatusCode);

foreach  (var meeting in onlineMeetings.Data)
{
    Console.WriteLine(meeting.Name);
}

var allMeetings = await client.GetMeetingsAsync();

foreach (var meeting in allMeetings.Data)
{
    Console.WriteLine(meeting.Name);
}


Console.WriteLine(allMeetings.StatusCode);

//foreach  (var meeting in meetings.Data)
//{
//    Console.WriteLine(meeting.Name);
//}