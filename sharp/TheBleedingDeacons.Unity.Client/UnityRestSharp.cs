using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using TheBleedingDeacons.Unity.Models;

namespace TheBleedingDeacons.Unity.Client;

/// <summary>
/// Client for the Integrity WordPress API.
/// Provides secure access to Groups, Meetings, Positions, Members, and Intergroup Meetings from the Unity plugin.
/// </summary>
public sealed class UnityRestSharp : IDisposable
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
    public UnityRestSharp(string baseUrl, string apiKey, HttpClient? httpClient = null)
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
    /// <param name="page">Page number (default: 1)</param>
    /// <param name="perPage">Results per page (default: 100, max: 500)</param>
    /// <param name="search">Search term to filter groups</param>
    /// <param name="districtId">Filter by district ID</param>
    /// <param name="expandMeetings">When true, includes full meeting data instead of just IDs</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<List<Group>>> GetGroupsAsync(
        int page = 1,
        int perPage = 100,
        string? search = null,
        int? districtId = null,
        bool expandMeetings = false,
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

        if (expandMeetings)
            queryParams.Add("expand=meetings");

        var url = $"{_baseUrl}/wp-json/integrity/v1/groups?{string.Join("&", queryParams)}";
        return await GetAsync<List<Group>>(url, cancellationToken);
    }

    /// <summary>
    /// Gets a single group by ID.
    /// </summary>
    /// <param name="id">Group ID</param>
    /// <param name="expandMeetings">When true, includes full meeting data instead of just IDs</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<Group>> GetGroupAsync(
        int id,
        bool expandMeetings = false,
        CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/groups/{id}";

        if (expandMeetings)
            url += "?expand=meetings";

        return await GetAsync<Group>(url, cancellationToken);
    }

    #endregion

    #region Meetings

    /// <summary>
    /// Gets all meetings with optional filtering.
    /// </summary>
    /// <param name="page">Page number (default: 1)</param>
    /// <param name="perPage">Results per page (default: 100, max: 500)</param>
    /// <param name="dayOfWeek">Filter by day of week (Sunday=0, Monday=1, etc.)</param>
    /// <param name="online">Filter by online (true) or in-person (false) meetings</param>
    /// <param name="groupId">Filter by group ID</param>
    /// <param name="search">Search term to filter meetings</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<List<Meeting>>> GetMeetingsAsync(
        int page = 1,
        int perPage = 100,
        DayOfWeek? dayOfWeek = null,
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

        if (dayOfWeek.HasValue)
            queryParams.Add($"day={(int)dayOfWeek.Value}");

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

    #region Positions

    /// <summary>
    /// Gets all positions with optional filtering.
    /// </summary>
    /// <param name="page">Page number (default: 1)</param>
    /// <param name="perPage">Results per page (default: 100, max: 500)</param>
    /// <param name="search">Search term to filter positions</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<List<Position>>> GetPositionsAsync(
        int page = 1,
        int perPage = 100,
        string? search = null,
        CancellationToken cancellationToken = default)
    {
        var queryParams = new List<string>
        {
            $"page={page}",
            $"per_page={perPage}"
        };

        if (!string.IsNullOrEmpty(search))
            queryParams.Add($"search={Uri.EscapeDataString(search)}");

        var url = $"{_baseUrl}/wp-json/integrity/v1/positions?{string.Join("&", queryParams)}";
        return await GetAsync<List<Position>>(url, cancellationToken);
    }

    /// <summary>
    /// Gets a single position by ID.
    /// </summary>
    /// <param name="id">Position ID</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<Position>> GetPositionAsync(
        int id,
        CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/positions/{id}";
        return await GetAsync<Position>(url, cancellationToken);
    }

    #endregion

    #region Members

    /// <summary>
    /// Gets all members with optional filtering.
    /// </summary>
    /// <param name="page">Page number (default: 1)</param>
    /// <param name="perPage">Results per page (default: 100, max: 500)</param>
    /// <param name="search">Search term to filter members</param>
    /// <param name="homeGroupId">Filter by home group ID</param>
    /// <param name="expandHomeGroup">When true, includes full home group data instead of just ID</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<List<Member>>> GetMembersAsync(
        int page = 1,
        int perPage = 100,
        string? search = null,
        int? homeGroupId = null,
        bool expandHomeGroup = false,
        CancellationToken cancellationToken = default)
    {
        var queryParams = new List<string>
        {
            $"page={page}",
            $"per_page={perPage}"
        };

        if (!string.IsNullOrEmpty(search))
            queryParams.Add($"search={Uri.EscapeDataString(search)}");

        if (homeGroupId.HasValue)
            queryParams.Add($"home_group_id={homeGroupId.Value}");

        if (expandHomeGroup)
            queryParams.Add("expand=home_group");

        var url = $"{_baseUrl}/wp-json/integrity/v1/members?{string.Join("&", queryParams)}";
        return await GetAsync<List<Member>>(url, cancellationToken);
    }

    /// <summary>
    /// Gets a single member by ID.
    /// </summary>
    /// <param name="id">Member ID</param>
    /// <param name="expandHomeGroup">When true, includes full home group data instead of just ID</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<Member>> GetMemberAsync(
        int id,
        bool expandHomeGroup = false,
        CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/members/{id}";

        if (expandHomeGroup)
            url += "?expand=home_group";

        return await GetAsync<Member>(url, cancellationToken);
    }

    #endregion

    #region Intergroup Meetings

    /// <summary>
    /// Gets all intergroup meetings with optional filtering.
    /// </summary>
    /// <param name="page">Page number (default: 1)</param>
    /// <param name="perPage">Results per page (default: 100, max: 500)</param>
    /// <param name="dateFrom">Filter meetings on or after this date (format: yyyy-MM-dd)</param>
    /// <param name="dateTo">Filter meetings on or before this date (format: yyyy-MM-dd)</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<List<IntergroupMeeting>>> GetIntergroupMeetingsAsync(
        int page = 1,
        int perPage = 100,
        DateOnly? dateFrom = null,
        DateOnly? dateTo = null,
        CancellationToken cancellationToken = default)
    {
        var queryParams = new List<string>
        {
            $"page={page}",
            $"per_page={perPage}"
        };

        if (dateFrom.HasValue)
            queryParams.Add($"date_from={dateFrom.Value:yyyy-MM-dd}");

        if (dateTo.HasValue)
            queryParams.Add($"date_to={dateTo.Value:yyyy-MM-dd}");

        var url = $"{_baseUrl}/wp-json/integrity/v1/intergroup-meetings?{string.Join("&", queryParams)}";
        return await GetAsync<List<IntergroupMeeting>>(url, cancellationToken);
    }

    /// <summary>
    /// Gets a single intergroup meeting by ID.
    /// </summary>
    /// <param name="id">Intergroup meeting ID</param>
    /// <param name="cancellationToken">Cancellation token</param>
    public async Task<ApiResponse<IntergroupMeeting>> GetIntergroupMeetingAsync(
        int id,
        CancellationToken cancellationToken = default)
    {
        var url = $"{_baseUrl}/wp-json/integrity/v1/intergroup-meetings/{id}";
        return await GetAsync<IntergroupMeeting>(url, cancellationToken);
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

#endregion