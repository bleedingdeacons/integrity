using System;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace TheBleedingDeacons.Unity.Models
{
    /// <summary>
    /// Converts JSON string values to <see cref="DateTime?"/>, treating empty
    /// strings as <c>null</c> instead of throwing a deserialization error.
    /// 
    /// The Unity API returns <c>""</c> for the <c>updated</c> field when a
    /// post has no modification timestamp. System.Text.Json cannot convert an
    /// empty string to <see cref="DateTime?"/> by default, so this converter
    /// bridges the gap.
    /// </summary>
    public sealed class EmptyStringToNullDateTimeConverter : JsonConverter<DateTime?>
    {
        public override DateTime? Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
        {
            if (reader.TokenType == JsonTokenType.Null)
            {
                return null;
            }

            if (reader.TokenType == JsonTokenType.String)
            {
                var value = reader.GetString();

                if (string.IsNullOrWhiteSpace(value))
                {
                    return null;
                }

                if (DateTime.TryParse(value, System.Globalization.CultureInfo.InvariantCulture,
                        System.Globalization.DateTimeStyles.RoundtripKind, out var result))
                {
                    return result;
                }

                return null;
            }

            throw new JsonException($"Unexpected token type {reader.TokenType} for DateTime?.");
        }

        public override void Write(Utf8JsonWriter writer, DateTime? value, JsonSerializerOptions options)
        {
            if (value.HasValue)
            {
                writer.WriteStringValue(value.Value.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'"));
            }
            else
            {
                writer.WriteNullValue();
            }
        }
    }
}