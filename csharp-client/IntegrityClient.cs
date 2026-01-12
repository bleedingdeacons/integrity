using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Integrity.Client;

/// <summary>
/// Client for the Integrity WordPress API.
/// Provides secure access to Groups and Meetings from the Unity plugin.
/// </summary>
public sealed class IntegrityClient : IDisposable
{
    private readonly HttpClient _httpClient;
    private readonly string _baseUrl;
    private readonly JsonSerializerOptions _jsonOptions;
    private bool _disposed;

    /// <summary>
    /// Creates a new Integrity API client.
    /// </summary>
    /// <param name="baseUrl">The WordPress site URL (e.g., "https://example.com")</param>
    /// <param name="apiKey">Your Integrity API key</param>
    /// <param name="httpClient">Optional HttpClient instance for dependency injection</param>
    public IntegrityClient(string baseUrl, string apiKey, HttpClient? httpClient = null)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(baseUrl);
        ArgumentException.ThrowIfNullOrWhiteSpace(apiKey);

        _baseUrl = baseUrl.TrimEnd('/');
        _httpClient = httpClient ?? new HttpClient();
        
        // Set authorization header
        _httpClient.DefaultRequestHeaders.Authorization = 
            new AuthenticationHeaderValue("Bearer", apiKey);
        
        // Set default headers
        _httpClient.DefaultRequestHeaders.Accept.Add(
            new MediaTypeWithQualityHeaderValue("application/json"));
        _httpClient.DefaultRequestHeaders.UserAgent.ParseAdd("IntegrityClient/1.0");
        
        // Configure JSON options
        _jsonOptions = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
            PropertyNameCaseInsensitive = true,
            DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
        };
    }

    #region Groups

    /// <summary>
    /// Gets all groups with optional filtering.
    /// </summary>
    public async Task<ApiResponse<List<Group>>> GetGroupsAsync(
        int page = 1,
        int perPage = 100,
        string? search = null,
        int? districtId = null,
        CancellationToken cancellationToken = default)
    {
        var queryParams = new List<string>
        {
            $"page={page}",
            $"per_page={perPage}"
        };

        if (!string.IsNullOrEmpty(search))
            queryParams.Add($"search={Uri.EscapeDataString(search)}");
        
        if (districtId.HasValue)
            queryParams.Add($"district_id={districtId.Value}");

        var url = $"{_baseUrl}/wp-json/integrity/v1/groups?{string.Join("&", queryParams)}";
        return await GetAsync<List<Group>>(url, cancellationToken);
    }

    /// <summary>
    /// Gets a single group by ID.
    /// </summary>
    public async Task<ApiResponse<Group>> GetGroupAsync(int id, CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/groups/{id}";
        return await GetAsync<Group>(url, cancellationToken);
    }

    #endregion

    #region Meetings

    /// <summary>
    /// Gets all meetings with optional filtering.
    /// </summary>
    public async Task<ApiResponse<List<Meeting>>> GetMeetingsAsync(
        int page = 1,
        int perPage = 100,
        int? day = null,
        bool? online = null,
        int? groupId = null,
        string? search = null,
        CancellationToken cancellationToken = default)
    {
        var queryParams = new List<string>
        {
            $"page={page}",
            $"per_page={perPage}"
        };

        if (day.HasValue)
            queryParams.Add($"day={day.Value}");
        
        if (online.HasValue)
            queryParams.Add($"online={online.Value.ToString().ToLowerInvariant()}");
        
        if (groupId.HasValue)
            queryParams.Add($"group_id={groupId.Value}");
        
        if (!string.IsNullOrEmpty(search))
            queryParams.Add($"search={Uri.EscapeDataString(search)}");

        var url = $"{_baseUrl}/wp-json/integrity/v1/meetings?{string.Join("&", queryParams)}";
        return await GetAsync<List<Meeting>>(url, cancellationToken);
    }

    /// <summary>
    /// Gets a single meeting by ID.
    /// </summary>
    public async Task<ApiResponse<Meeting>> GetMeetingAsync(int id, CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/meetings/{id}";
        return await GetAsync<Meeting>(url, cancellationToken);
    }

    #endregion

    #region Health

    /// <summary>
    /// Checks the API health status.
    /// </summary>
    public async Task<HealthResponse?> CheckHealthAsync(CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/health";
        
        try
        {
            return await _httpClient.GetFromJsonAsync<HealthResponse>(url, _jsonOptions, cancellationToken);
        }
        catch
        {
            return null;
        }
    }

    #endregion

    #region Private Methods

    private async Task<ApiResponse<T>> GetAsync<T>(string url, CancellationToken cancellationToken) where T : class
    {
        try
        {
            using var response = await _httpClient.GetAsync(url, cancellationToken);
            var content = await response.Content.ReadAsStringAsync(cancellationToken);

            // Parse rate limit headers
            var rateLimit = new RateLimitInfo
            {
                Limit = GetHeaderInt(response, "X-RateLimit-Limit"),
                Remaining = GetHeaderInt(response, "X-RateLimit-Remaining"),
                Reset = GetHeaderLong(response, "X-RateLimit-Reset")
            };

            if (!response.IsSuccessStatusCode)
            {
                var errorResponse = JsonSerializer.Deserialize<ApiErrorResponse>(content, _jsonOptions);
                return new ApiResponse<T>
                {
                    Success = false,
                    Error = errorResponse?.Error ?? new ApiError 
                    { 
                        Code = "unknown_error", 
                        Message = $"HTTP {(int)response.StatusCode}: {response.ReasonPhrase}" 
                    },
                    StatusCode = (int)response.StatusCode,
                    RateLimit = rateLimit
                };
            }

            var apiResponse = JsonSerializer.Deserialize<ApiDataResponse<T>>(content, _jsonOptions);
            
            return new ApiResponse<T>
            {
                Success = apiResponse?.Success ?? false,
                Data = apiResponse?.Data,
                Meta = apiResponse?.Meta,
                StatusCode = (int)response.StatusCode,
                RateLimit = rateLimit
            };
        }
        catch (HttpRequestException ex)
        {
            return new ApiResponse<T>
            {
                Success = false,
                Error = new ApiError { Code = "network_error", Message = ex.Message },
                StatusCode = 0
            };
        }
        catch (JsonException ex)
        {
            return new ApiResponse<T>
            {
                Success = false,
                Error = new ApiError { Code = "parse_error", Message = ex.Message },
                StatusCode = 0
            };
        }
    }

    private static int GetHeaderInt(HttpResponseMessage response, string headerName)
    {
        if (response.Headers.TryGetValues(headerName, out var values))
        {
            var value = values.FirstOrDefault();
            if (int.TryParse(value, out var result))
                return result;
        }
        return 0;
    }

    private static long GetHeaderLong(HttpResponseMessage response, string headerName)
    {
        if (response.Headers.TryGetValues(headerName, out var values))
        {
            var value = values.FirstOrDefault();
            if (long.TryParse(value, out var result))
                return result;
        }
        return 0;
    }

    #endregion

    #region IDisposable

    public void Dispose()
    {
        if (!_disposed)
        {
            _httpClient.Dispose();
            _disposed = true;
        }
    }

    #endregion
}

#region Response Models

/// <summary>
/// API response wrapper with success status, data, and metadata.
/// </summary>
public sealed class ApiResponse<T> where T : class
{
    public bool Success { get; init; }
    public T? Data { get; init; }
    public ApiError? Error { get; init; }
    public ResponseMeta? Meta { get; init; }
    public int StatusCode { get; init; }
    public RateLimitInfo? RateLimit { get; init; }
}

/// <summary>
/// Internal response structure for deserialization.
/// </summary>
internal sealed class ApiDataResponse<T> where T : class
{
    public bool Success { get; init; }
    public T? Data { get; init; }
    public ResponseMeta? Meta { get; init; }
}

/// <summary>
/// Internal error response structure for deserialization.
/// </summary>
internal sealed class ApiErrorResponse
{
    public bool Success { get; init; }
    public ApiError? Error { get; init; }
}

/// <summary>
/// API error details.
/// </summary>
public sealed class ApiError
{
    public required string Code { get; init; }
    public required string Message { get; init; }
}

/// <summary>
/// Response pagination metadata.
/// </summary>
public sealed class ResponseMeta
{
    public int Total { get; init; }
    public int Page { get; init; }
    public int PerPage { get; init; }
    public int TotalPages { get; init; }
}

/// <summary>
/// Rate limit information from response headers.
/// </summary>
public sealed class RateLimitInfo
{
    public int Limit { get; init; }
    public int Remaining { get; init; }
    public long Reset { get; init; }
    
    public DateTime ResetDateTime => DateTimeOffset.FromUnixTimeSeconds(Reset).LocalDateTime;
}

/// <summary>
/// Health check response.
/// </summary>
public sealed class HealthResponse
{
    public required string Status { get; init; }
    public required string Timestamp { get; init; }
    public required string Version { get; init; }
    public bool UnityAvailable { get; init; }
}

#endregion

#region Domain Models

/// <summary>
/// Represents a group in the Unity system.
/// </summary>
public sealed class Group
{
    public int Id { get; init; }
    public string Title { get; init; } = string.Empty;
    public string Email { get; init; } = string.Empty;
    public string Phone { get; init; } = string.Empty;
    public string Website { get; init; } = string.Empty;
    public string Link { get; init; } = string.Empty;
    public string Notes { get; init; } = string.Empty;
    public int? DistrictId { get; init; }
    public string? LastContact { get; init; }
    public List<int> MeetingIds { get; init; } = [];
    public List<Contact> Contacts { get; init; } = [];
    public ContributionOptions? ContributionOptions { get; init; }
}

/// <summary>
/// Represents a meeting in the Unity system.
/// </summary>
public sealed class Meeting
{
    public int Id { get; init; }
    public string Name { get; init; } = string.Empty;
    public string Slug { get; init; } = string.Empty;
    public string Location { get; init; } = string.Empty;
    public string Url { get; init; } = string.Empty;
    public int Day { get; init; }
    public string DayOfWeek { get; init; } = string.Empty;
    public string Time { get; init; } = string.Empty;
    public string EndTime { get; init; } = string.Empty;
    public List<string> Types { get; init; } = [];
    public string State { get; init; } = string.Empty;
    public bool IsOnline { get; init; }
    public string OnlineLink { get; init; } = string.Empty;
    public string OnlineNotes { get; init; } = string.Empty;
    public List<Contact> Contacts { get; init; } = [];
    public Dictionary<string, object>? Meta { get; init; }
}

/// <summary>
/// Contact information.
/// </summary>
public sealed class Contact
{
    public string Name { get; init; } = string.Empty;
    public string Email { get; init; } = string.Empty;
    public string Phone { get; init; } = string.Empty;
}

/// <summary>
/// Digital contribution options for a group.
/// </summary>
public sealed class ContributionOptions
{
    public string Venmo { get; init; } = string.Empty;
    public string Paypal { get; init; } = string.Empty;
    public string Square { get; init; } = string.Empty;
    public bool HasOptions { get; init; }
}

#endregion
