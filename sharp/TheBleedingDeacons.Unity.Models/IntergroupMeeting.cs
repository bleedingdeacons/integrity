using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Text.Json.Serialization;
using System.Threading.Tasks;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Represents an intergroup meeting in the Unity system.
    /// </summary>
    public class IntergroupMeeting
    {
        public int Id { get; init; }
        public string Title { get; init; } = string.Empty;
        public string Date { get; init; } = string.Empty;

        /// <summary>
        /// Array of member IDs attending the meeting.
        /// </summary>
        public List<int> GroupAttendeeIds { get; init; } = [];

        /// <summary>
        /// Attendee details with ID and name.
        /// </summary>
        public List<IntergroupMeetingAttendee> GroupAttendees { get; init; } = [];

        /// <summary>
        /// Array of officer IDs attending the meeting.
        /// </summary>
        public List<int> OfficersAttendingIds { get; init; } = [];

        /// <summary>
        /// Officer details with ID and name.
        /// </summary>
        public List<IntergroupMeetingAttendee> OfficersAttending { get; init; } = [];

        /// <summary>
        /// Array of group post IDs attending the meeting (maps to ACF field: attending_groups).
        /// </summary>
        [JsonPropertyName("attending_groups")]
        public int[] AttendingGroups { get; init; } = [];

        /// <summary>
        /// Array of officer/member post IDs attending the meeting (maps to ACF field: attending_officers).
        /// </summary>
        [JsonPropertyName("attending_officers")]
        public int[] AttendingOfficers { get; init; } = [];

        /// <summary>
        /// Last updated datetime from WordPress post_modified.
        /// </summary>
        [JsonConverter(typeof(EmptyStringToNullDateTimeConverter))]
        public DateTime? Updated { get; init; }

        /// <summary>
        /// Gets the meeting date as a DateOnly value, if valid.
        /// </summary>
        [JsonIgnore]
        public DateOnly? DateValue => DateOnly.TryParse(Date, out var date) ? date : null;
    }

}