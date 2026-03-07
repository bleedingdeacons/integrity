using System.Text.Json.Serialization;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Request model for creating a new member via POST /members/create.
    /// AnonymousName is required; all other properties are optional.
    /// </summary>
    public class CreateMemberRequest
    {
        /// <summary>
        /// The member's anonymous/display name (required).
        /// </summary>
        public required string AnonymousName { get; init; }

        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? PersonalEmail { get; init; }

        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? MobileNumber { get; init; }

        /// <summary>
        /// The home group ID — set when creating a GSR member for a group.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public int? HomeGroupId { get; init; }

        /// <summary>
        /// Whether the member is a Group Service Representative.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public bool? IsGsr { get; init; }

        /// <summary>
        /// The intergroup position ID — set when creating a position holder.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public int? IntergroupPositionId { get; init; }

        /// <summary>
        /// The rotation date for the intergroup position (e.g. "2025-09-01").
        /// Required when <see cref="IntergroupPositionId"/> is set.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? IntergroupPositionRotation { get; init; }
    }
}