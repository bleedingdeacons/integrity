namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Represents the result of registering or unregistering an officer from an intergroup meeting.
    /// </summary>
    public class IntergroupMeetingOfficerRegistration
    {
        public int IntergroupMeetingId { get; init; }
        public string MeetingLabel { get; init; } = string.Empty;
        public int OfficerId { get; init; }
        public string OfficerName { get; init; } = string.Empty;
        public string PositionName { get; init; } = string.Empty;
        public bool Registered { get; init; }
    }
}