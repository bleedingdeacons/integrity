using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Text.Json.Serialization;
using System.Threading.Tasks;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Represents a location in the Unity system.
    /// </summary>
    public class Location
    {
        public int Id { get; init; }
        public string Name { get; init; } = string.Empty;
        public string Address { get; init; } = string.Empty;
        public string City { get; init; } = string.Empty;
        public string State { get; init; } = string.Empty;
        public string PostalCode { get; init; } = string.Empty;
        public string Country { get; init; } = string.Empty;
        public string Region { get; init; } = string.Empty;
        public string Notes { get; init; } = string.Empty;
        public string Link { get; init; } = string.Empty;
        public double? Latitude { get; init; }
        public double? Longitude { get; init; }
        public string Timezone { get; init; } = string.Empty;
        public string FormattedAddress { get; init; } = string.Empty;

        [JsonConverter(typeof(EmptyStringToNullDateTimeConverter))]
        public DateTime? Updated { get; init; }
    }

}