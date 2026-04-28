using System;
using System.Text.Json.Serialization;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// GDPR compliance state recorded for a member.
    ///
    /// Returned as the <c>gdpr_compliance</c> sub-object on member responses
    /// from the Integrity API once the compliance endpoint is available.
    /// Older server versions omit the field entirely — consumers should
    /// treat <see cref="Member.GdprCompliance"/> as nullable.
    ///
    /// When <see cref="Accepted"/> is <c>false</c>, the server clears
    /// <see cref="Version"/>, <see cref="Method"/>, and
    /// <see cref="Statement"/> because they belonged to the now-revoked
    /// acceptance and no longer apply. <see cref="AcceptedAt"/> still
    /// carries a meaningful value on a revocation — it marks the moment
    /// consent was withdrawn.
    /// </summary>
    public class GdprCompliance
    {
        /// <summary>
        /// Whether the member has currently recorded acceptance of the
        /// privacy policy.
        /// </summary>
        public bool Accepted { get; init; }

        /// <summary>
        /// Timestamp at which the current state was recorded. Maps to
        /// the API's ISO 8601 string; an empty string from the server
        /// (no state ever recorded) is decoded to <c>null</c>.
        /// </summary>
        [JsonConverter(typeof(EmptyStringToNullDateTimeConverter))]
        public DateTime? AcceptedAt { get; init; }

        /// <summary>
        /// The privacy policy version that was accepted. Empty after a
        /// revocation.
        /// </summary>
        public string Version { get; init; } = string.Empty;

        /// <summary>
        /// How the acceptance was captured (e.g. <c>"web-form"</c>,
        /// <c>"api"</c>, <c>"import"</c>). Empty after a revocation.
        /// </summary>
        public string Method { get; init; } = string.Empty;

        /// <summary>
        /// The exact statement the member accepted. Empty after a
        /// revocation.
        /// </summary>
        public string Statement { get; init; } = string.Empty;
    }
}
