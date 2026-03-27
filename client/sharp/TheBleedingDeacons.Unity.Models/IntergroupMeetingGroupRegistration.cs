using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Represents the result of registering or unregistering a member from an intergroup meeting.
    /// </summary>
    public class IntergroupMeetingGroupRegistration
    {
        public int IntergroupMeetingId { get; init; }
        public string MeetingLabel { get; init; } = string.Empty;
        public int MemberId { get; init; }
        public string MemberName { get; init; } = string.Empty;
        public int GroupId { get; init; }
        public string MeetingGroup { get; init; } = string.Empty;
        public string GsrName { get; init; } = string.Empty;
        public bool GsrProxy { get; init; }
        public string GsrProxyName { get; init; } = string.Empty;
        public bool Registered { get; init; }
    }
}