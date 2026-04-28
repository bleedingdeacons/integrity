using System.Text.Json.Serialization;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Request body for <c>POST /members/{id}/compliance</c>.
    ///
    /// <para>
    /// <see cref="Accepted"/> is required — it expresses the action being
    /// recorded (<c>true</c> for an acceptance, <c>false</c> for a
    /// revocation). All other fields are optional and have sensible
    /// server-side defaults:
    /// </para>
    ///
    /// <list type="bullet">
    ///   <item>
    ///     <description>
    ///       <see cref="AcceptedAt"/> — defaults to "now" (UTC) when null.
    ///       Accepts any DateTime-parseable string; the server normalises
    ///       it to UTC <c>Y-m-d H:i:s</c> before storage. Modelled as a
    ///       string rather than <c>DateTime?</c> so callers can pass
    ///       pre-formatted ISO 8601 values without timezone-conversion
    ///       surprises — see comments at the property declaration.
    ///     </description>
    ///   </item>
    ///   <item>
    ///     <description>
    ///       <see cref="Version"/> — only persisted on acceptances.
    ///     </description>
    ///   </item>
    ///   <item>
    ///     <description>
    ///       <see cref="Method"/> — defaults to <c>"api"</c> on
    ///       acceptances when null. Ignored on revocations.
    ///     </description>
    ///   </item>
    ///   <item>
    ///     <description>
    ///       <see cref="Statement"/> — only persisted on acceptances.
    ///     </description>
    ///   </item>
    /// </list>
    ///
    /// <para>
    /// On a revocation (<see cref="Accepted"/> = <c>false</c>), the server
    /// clears version, method, and statement regardless of what is sent —
    /// they belonged to the now-revoked acceptance and no longer apply.
    /// </para>
    /// </summary>
    public class RecordComplianceRequest
    {
        /// <summary>
        /// Required. <c>true</c> records an acceptance; <c>false</c>
        /// records a revocation.
        /// </summary>
        public required bool Accepted { get; init; }

        /// <summary>
        /// Optional ISO 8601 timestamp at which the action was taken.
        ///
        /// <para>
        /// Modelled as <c>string?</c> rather than <c>DateTime?</c> on
        /// purpose: a <c>DateTime</c> with unspecified <c>Kind</c> would
        /// serialise without a timezone offset and could be misinterpreted
        /// by the server. Callers that have a <c>DateTime</c> or
        /// <c>DateTimeOffset</c> can format it themselves with
        /// <c>.ToUniversalTime().ToString("o")</c> for safe round-tripping.
        /// </para>
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? AcceptedAt { get; init; }

        /// <summary>
        /// Optional privacy policy version that the member accepted.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? Version { get; init; }

        /// <summary>
        /// Optional indicator of how acceptance was captured (e.g.
        /// <c>"web-form"</c>, <c>"import"</c>). Defaults to <c>"api"</c>
        /// on the server when omitted on an acceptance.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? Method { get; init; }

        /// <summary>
        /// Optional verbatim statement the member accepted.
        /// </summary>
        [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
        public string? Statement { get; init; }
    }
}
