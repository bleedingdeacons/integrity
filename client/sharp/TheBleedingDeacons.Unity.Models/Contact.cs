using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Text.Json.Serialization;
using System.Threading.Tasks;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Contact information.
    /// </summary>
    public class Contact
    {
        public string Name { get; init; } = string.Empty;
        public string Email { get; init; } = string.Empty;
        public string Phone { get; init; } = string.Empty;

        [JsonConverter(typeof(EmptyStringToNullDateTimeConverter))]
        public DateTime? Updated { get; init; }
    }

}