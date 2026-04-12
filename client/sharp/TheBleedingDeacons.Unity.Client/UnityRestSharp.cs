using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;
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
	private readonly bool _httpClientSupplied;
	private readonly ILogger<UnityRestSharp> _logger;

	// Retry configuration for transient failures (network errors, 5xx, 429, 408,
	// and WAF-style HTML 403s served by the host's web server rather than the API).
	private const int MaxRetryAttempts = 5;
	private static readonly TimeSpan InitialRetryDelay = TimeSpan.FromMilliseconds(500);
	private static readonly TimeSpan MaxRetryDelay = TimeSpan.FromSeconds(8);
	private static readonly Random RetryJitter = new();

	/// <summary>
	/// Creates a new Integrity API client.
	/// </summary>
	/// <param name="baseUrl">The WordPress site URL (e.g., "https://example.com")</param>
	/// <param name="apiKey">Your Integrity API key</param>
	/// <param name="httpClient">Optional HttpClient instance for dependency injection</param>
	/// <param name="logger">Optional ILogger instance for structured logging</param>
	public UnityRestSharp(string baseUrl, string apiKey, HttpClient? httpClient = null, ILogger<UnityRestSharp>? logger = null)
	{
		ArgumentException.ThrowIfNullOrWhiteSpace(baseUrl);
		ArgumentException.ThrowIfNullOrWhiteSpace(apiKey);

		_logger = logger ?? Microsoft.Extensions.Logging.Abstractions.NullLogger<UnityRestSharp>.Instance;

		if (httpClient != null) _httpClientSupplied = true;

		_baseUrl = baseUrl.TrimEnd('/');
		_httpClient = httpClient ?? new HttpClient();

		// Set authorization header
		_httpClient.DefaultRequestHeaders.Authorization =
			new AuthenticationHeaderValue("Bearer", apiKey);

		// Set default headers
		_httpClient.DefaultRequestHeaders.Accept.ParseAdd("application/json");
		_httpClient.DefaultRequestHeaders.UserAgent.ParseAdd("IntegrityClient/1.0");

		// Configure JSON options
		_jsonOptions = new JsonSerializerOptions
		{
			PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
			PropertyNameCaseInsensitive = true,
			DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull
		};

		_logger.LogDebug("Integrity API client initialized for {BaseUrl}", _baseUrl);
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

	/// <summary>
	/// Creates a new member.
	/// Requires the members:write permission.
	/// </summary>
	/// <param name="request">The member data to create</param>
	/// <param name="cancellationToken">Cancellation token</param>
	public async Task<ApiResponse<Member>> CreateMemberAsync(
		CreateMemberRequest request,
		CancellationToken cancellationToken = default)
	{
		var url = $"{_baseUrl}/wp-json/integrity/v1/members/create";
		return await PostAsync<Member>(url, request, cancellationToken);
	}

	/// <summary>
	/// Updates a member. Only the fields set on the request object will be changed (partial update).
	/// Requires the members:write permission.
	/// </summary>
	/// <param name="id">Member ID to update</param>
	/// <param name="request">Fields to update</param>
	/// <param name="cancellationToken">Cancellation token</param>
	public async Task<ApiResponse<Member>> UpdateMemberAsync(
		int id,
		UpdateMemberRequest request,
		CancellationToken cancellationToken = default)
	{
		var url = $"{_baseUrl}/wp-json/integrity/v1/members/{id}/update";
		return await PostAsync<Member>(url, request, cancellationToken);
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

	/// <summary>
	/// Registers a group as an attendee of an intergroup meeting.
	/// </summary>
	/// <param name="intergroupMeetingId">The intergroup meeting ID</param>
	/// <param name="groupId">The group CPT post ID to register</param>
	/// <param name="memberId">The member ID of the GSR (optional, 0 if not applicable)</param>
	/// <param name="gsrName">The GSR name (plain text)</param>
	/// <param name="gsrProxy">Whether a proxy attended in place of the GSR</param>
	/// <param name="gsrProxyName">The proxy name when a proxy attended</param>
	/// <param name="cancellationToken">Cancellation token</param>
	public async Task<ApiResponse<IntergroupMeetingGroupRegistration>> RegisterGroupAsync(
		int intergroupMeetingId,
		int groupId,
		int memberId,
		string gsrName,
		bool gsrProxy = false,
		string? gsrProxyName = null,
		CancellationToken cancellationToken = default)
	{
		var url = $"{_baseUrl}/wp-json/integrity/v1/intergroup-meetings/{intergroupMeetingId}/register-group";
		var payload = new
		{
			group_id = groupId,
			member_id = memberId,
			gsr_name = gsrName,
			gsr_proxy = gsrProxy,
			gsr_proxy_name = gsrProxyName ?? string.Empty
		};
		return await PostAsync<IntergroupMeetingGroupRegistration>(url, payload, cancellationToken);
	}

	/// <summary>
	/// Unregisters a group from an intergroup meeting.
	/// </summary>
	/// <param name="intergroupMeetingId">The intergroup meeting ID</param>
	/// <param name="groupId">The group CPT post ID to unregister</param>
	/// <param name="cancellationToken">Cancellation token</param>
	public async Task<ApiResponse<IntergroupMeetingGroupRegistration>> UnregisterGroupAsync(
		int intergroupMeetingId,
		int groupId,
		CancellationToken cancellationToken = default)
	{
		var url = $"{_baseUrl}/wp-json/integrity/v1/intergroup-meetings/{intergroupMeetingId}/unregister-group";
		var payload = new { group_id = groupId };
		return await PostAsync<IntergroupMeetingGroupRegistration>(url, payload, cancellationToken);
	}

	/// <summary>
	/// Registers an officer as an attendee of an intergroup meeting.
	/// </summary>
	/// <param name="intergroupMeetingId">The intergroup meeting ID</param>
	/// <param name="officerId">The officer (member) ID to register</param>
	/// <param name="positionName">The position name (plain text)</param>
	/// <param name="officerName">The officer name (plain text)</param>
	/// <param name="cancellationToken">Cancellation token</param>
	public async Task<ApiResponse<IntergroupMeetingOfficerRegistration>> RegisterOfficerAsync(
		int intergroupMeetingId,
		int officerId,
		string positionName,
		string officerName,
		CancellationToken cancellationToken = default)
	{
		var url = $"{_baseUrl}/wp-json/integrity/v1/intergroup-meetings/{intergroupMeetingId}/register-officer";
		var payload = new
		{
			officer_id = officerId,
			position_name = positionName,
			officer_name = officerName
		};
		return await PostAsync<IntergroupMeetingOfficerRegistration>(url, payload, cancellationToken);
	}

	/// <summary>
	/// Unregisters an officer from an intergroup meeting.
	/// </summary>
	/// <param name="intergroupMeetingId">The intergroup meeting ID</param>
	/// <param name="officerId">The officer (member) ID to unregister</param>
	/// <param name="cancellationToken">Cancellation token</param>
	public async Task<ApiResponse<IntergroupMeetingOfficerRegistration>> UnregisterOfficerAsync(
		int intergroupMeetingId,
		int officerId,
		CancellationToken cancellationToken = default)
	{
		var url = $"{_baseUrl}/wp-json/integrity/v1/intergroup-meetings/{intergroupMeetingId}/unregister-officer";
		var payload = new { officer_id = officerId };
		return await PostAsync<IntergroupMeetingOfficerRegistration>(url, payload, cancellationToken);
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
			_logger.LogDebug("Health check GET {Url}", url);
			using var response = await SendWithRetryAsync(
				() => new HttpRequestMessage(HttpMethod.Get, url),
				"GET", url, cancellationToken);
			if (!response.IsSuccessStatusCode)
			{
				_logger.LogWarning("Health check returned HTTP {StatusCode} for {Url}", (int)response.StatusCode, url);
				return null;
			}
			var body = await response.Content.ReadAsStringAsync(cancellationToken);
			var result = JsonSerializer.Deserialize<HealthResponse>(body, _jsonOptions);
			_logger.LogDebug("Health check completed: {Status}", result?.Status ?? "null");
			return result;
		}
		catch (Exception ex)
		{
			_logger.LogWarning(ex, "Health check failed for {Url}", url);
			return null;
		}
	}

	#endregion

	#region Private Methods

	private async Task<ApiResponse<T>> GetAsync<T>(string url, CancellationToken cancellationToken) where T : class
	{
		int statusCode = 0;
		string? contentSnippet = null;

		_logger.LogDebug("GET {Url}", url);

		try
		{
			using var response = await SendWithRetryAsync(
				() => new HttpRequestMessage(HttpMethod.Get, url),
				"GET", url, cancellationToken);
			statusCode = (int)response.StatusCode;
			var content = await response.Content.ReadAsStringAsync(cancellationToken);
			contentSnippet = content.Length > 500 ? content[..500] : content;

			// Parse rate limit headers
			var rateLimit = new RateLimitInfo
			{
				Limit = GetHeaderInt(response, "X-RateLimit-Limit"),
				Remaining = GetHeaderInt(response, "X-RateLimit-Remaining"),
				Reset = GetHeaderLong(response, "X-RateLimit-Reset")
			};

			if (!response.IsSuccessStatusCode)
			{
				// Dump full details on 403 to help diagnose server-side blocking
				if (statusCode == 403)
				{
					var headers = string.Join("\n", response.Headers.Select(h => $"  {h.Key}: {string.Join(", ", h.Value)}"));
					var contentHeaders = string.Join("\n", response.Content.Headers.Select(h => $"  {h.Key}: {string.Join(", ", h.Value)}"));
					_logger.LogWarning("403 Forbidden on GET {Url}. Response headers:\n{Headers}\nContent headers:\n{ContentHeaders}\nBody:\n{Body}",
						url, headers, contentHeaders, content);
				}

				// If the response doesn't look like JSON, return the body snippet directly
				var trimmed = content.TrimStart();
				if (trimmed.Length > 0 && trimmed[0] != '{' && trimmed[0] != '[')
				{
					return new ApiResponse<T>
					{
						Success = false,
						Error = new ApiError
						{
							Code = "unexpected_response",
							Message = $"HTTP {statusCode} from GET {url} — expected JSON but response starts with: {contentSnippet}"
						},
						StatusCode = statusCode,
						RateLimit = rateLimit
					};
				}

				var errorResponse = JsonSerializer.Deserialize<ApiErrorResponse>(content, _jsonOptions);
				return new ApiResponse<T>
				{
					Success = false,
					Error = errorResponse?.Error ?? new ApiError
					{
						Code = "unknown_error",
						Message = $"HTTP {statusCode}: {response.ReasonPhrase}"
					},
					StatusCode = statusCode,
					RateLimit = rateLimit
				};
			}

			var apiResponse = JsonSerializer.Deserialize<ApiDataResponse<T>>(content, _jsonOptions);

			_logger.LogDebug("GET {Url} completed with HTTP {StatusCode}", url, statusCode);

			return new ApiResponse<T>
			{
				Success = apiResponse?.Success ?? false,
				Data = apiResponse?.Data,
				Meta = apiResponse?.Meta,
				StatusCode = statusCode,
				RateLimit = rateLimit
			};
		}
		catch (HttpRequestException ex)
		{
			_logger.LogError(ex, "Network error on GET {Url}", url);
			return new ApiResponse<T>
			{
				Success = false,
				Error = new ApiError { Code = "network_error", Message = $"GET {url} failed: {ex.Message}" },
				StatusCode = 0
			};
		}
		catch (JsonException ex)
		{
			_logger.LogError(ex, "JSON parse error on GET {Url}, HTTP {StatusCode}", url, statusCode);
			return new ApiResponse<T>
			{
				Success = false,
				Error = new ApiError
				{
					Code = "parse_error",
					Message = $"GET {url} returned HTTP {statusCode} — JSON parse failed: {ex.Message}. Response starts with: {contentSnippet}"
				},
				StatusCode = statusCode
			};
		}
	}

	private async Task<ApiResponse<T>> PostAsync<T>(string url, object payload, CancellationToken cancellationToken) where T : class
	{
		int statusCode = 0;
		string? contentSnippet = null;

		_logger.LogDebug("POST {Url}", url);

		try
		{
			var serializedPayload = JsonSerializer.Serialize(payload, _jsonOptions);

			using var response = await SendWithRetryAsync(
				() => new HttpRequestMessage(HttpMethod.Post, url)
				{
					Content = new StringContent(serializedPayload, System.Text.Encoding.UTF8, "application/json")
				},
				"POST", url, cancellationToken);
			statusCode = (int)response.StatusCode;
			var content = await response.Content.ReadAsStringAsync(cancellationToken);
			contentSnippet = content.Length > 500 ? content[..500] : content;

			// Parse rate limit headers
			var rateLimit = new RateLimitInfo
			{
				Limit = GetHeaderInt(response, "X-RateLimit-Limit"),
				Remaining = GetHeaderInt(response, "X-RateLimit-Remaining"),
				Reset = GetHeaderLong(response, "X-RateLimit-Reset")
			};

			if (!response.IsSuccessStatusCode)
			{
				_logger.LogWarning("HTTP {StatusCode} on POST {Url}: {ReasonPhrase}", statusCode, url, response.ReasonPhrase);

				// If the response doesn't look like JSON, return the body snippet directly
				var trimmed = content.TrimStart();
				if (trimmed.Length > 0 && trimmed[0] != '{' && trimmed[0] != '[')
				{
					return new ApiResponse<T>
					{
						Success = false,
						Error = new ApiError
						{
							Code = "unexpected_response",
							Message = $"HTTP {statusCode} from POST {url} — expected JSON but response starts with: {contentSnippet}"
						},
						StatusCode = statusCode,
						RateLimit = rateLimit
					};
				}

				var errorResponse = JsonSerializer.Deserialize<ApiErrorResponse>(content, _jsonOptions);
				return new ApiResponse<T>
				{
					Success = false,
					Error = errorResponse?.Error ?? new ApiError
					{
						Code = "unknown_error",
						Message = $"HTTP {statusCode}: {response.ReasonPhrase}"
					},
					StatusCode = statusCode,
					RateLimit = rateLimit
				};
			}

			var apiResponse = JsonSerializer.Deserialize<ApiDataResponse<T>>(content, _jsonOptions);

			_logger.LogDebug("POST {Url} completed with HTTP {StatusCode}", url, statusCode);

			return new ApiResponse<T>
			{
				Success = apiResponse?.Success ?? false,
				Data = apiResponse?.Data,
				Meta = apiResponse?.Meta,
				StatusCode = statusCode,
				RateLimit = rateLimit
			};
		}
		catch (HttpRequestException ex)
		{
			_logger.LogError(ex, "Network error on POST {Url}", url);
			return new ApiResponse<T>
			{
				Success = false,
				Error = new ApiError { Code = "network_error", Message = $"POST {url} failed: {ex.Message}" },
				StatusCode = 0
			};
		}
		catch (JsonException ex)
		{
			_logger.LogError(ex, "JSON parse error on POST {Url}, HTTP {StatusCode}", url, statusCode);
			return new ApiResponse<T>
			{
				Success = false,
				Error = new ApiError
				{
					Code = "parse_error",
					Message = $"POST {url} returned HTTP {statusCode} — JSON parse failed: {ex.Message}. Response starts with: {contentSnippet}"
				},
				StatusCode = statusCode
			};
		}
	}

	/// <summary>
	/// Sends an HTTP request with retries for transient failures including:
	/// network exceptions, timeouts, HTTP 5xx, 408, 429, and HTML 403 responses
	/// from an upstream WAF / web server (as opposed to legitimate API JSON 403s).
	/// Uses exponential backoff with jitter, honouring Retry-After when present.
	/// The factory is invoked once per attempt because HttpRequestMessage cannot be reused.
	/// </summary>
	private async Task<HttpResponseMessage> SendWithRetryAsync(
		Func<HttpRequestMessage> requestFactory,
		string method,
		string url,
		CancellationToken cancellationToken)
	{
		Exception? lastException = null;

		for (int attempt = 1; attempt <= MaxRetryAttempts; attempt++)
		{
			HttpResponseMessage? response = null;
			bool shouldRetry = false;
			TimeSpan? retryAfter = null;
			string retryReason = string.Empty;

			_logger.LogDebug(
				"{Method} {Url} attempt {Attempt}/{Max} starting",
				method, url, attempt, MaxRetryAttempts);

			try
			{
				var request = requestFactory();
				response = await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);

				int status = (int)response.StatusCode;
				_logger.LogDebug(
					"{Method} {Url} attempt {Attempt}/{Max} received HTTP {StatusCode}",
					method, url, attempt, MaxRetryAttempts, status);

				// 5xx, 408, 429 are always transient.
				if (status >= 500 || status == 408 || status == 429)
				{
					shouldRetry = true;
					retryReason = $"HTTP {status}";
					retryAfter = GetRetryAfter(response);
				}
				// 403 from a WAF / upstream web server typically returns HTML, not JSON.
				// A real API 403 (auth/permission) returns JSON and should NOT be retried.
				else if (status == 403 && IsLikelyWafHtml(response))
				{
					shouldRetry = true;
					retryReason = "HTTP 403 (HTML body — likely WAF)";
					retryAfter = GetRetryAfter(response);
				}

				if (!shouldRetry)
				{
					if (attempt > 1)
					{
						_logger.LogInformation(
							"{Method} {Url} succeeded on attempt {Attempt}/{Max} (HTTP {StatusCode}) after {PriorFailures} prior failure(s)",
							method, url, attempt, MaxRetryAttempts, status, attempt - 1);
					}
					return response;
				}
			}
			catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
			{
				response?.Dispose();
				throw;
			}
			catch (HttpRequestException ex)
			{
				lastException = ex;
				shouldRetry = true;
				retryReason = $"network error: {ex.Message}";
			}
			catch (TaskCanceledException ex)
			{
				// Caller cancellation handled above; this is a request timeout.
				lastException = ex;
				shouldRetry = true;
				retryReason = "request timeout";
			}

			// Out of attempts — return the last response (so caller can surface it) or rethrow.
			if (attempt == MaxRetryAttempts)
			{
				if (response != null)
				{
					_logger.LogWarning(
						"{Method} {Url} giving up after {Attempts} attempts ({Reason})",
						method, url, attempt, retryReason);
					return response;
				}
				_logger.LogError(lastException,
					"{Method} {Url} failed after {Attempts} attempts ({Reason})",
					method, url, attempt, retryReason);
				throw lastException ?? new HttpRequestException($"{method} {url} failed after {attempt} attempts");
			}

			response?.Dispose();

			var delay = retryAfter ?? ComputeBackoff(attempt);
			_logger.LogWarning(
				"{Method} {Url} attempt {Attempt}/{Max} failed ({Reason}); retrying in {DelayMs}ms",
				method, url, attempt, MaxRetryAttempts, retryReason, (int)delay.TotalMilliseconds);

			try
			{
				await Task.Delay(delay, cancellationToken);
			}
			catch (OperationCanceledException)
			{
				throw;
			}
		}

		// Unreachable — loop either returns or throws.
		throw lastException ?? new HttpRequestException($"{method} {url} failed");
	}

	private static bool IsLikelyWafHtml(HttpResponseMessage response)
	{
		var contentType = response.Content.Headers.ContentType?.MediaType;
		if (string.IsNullOrEmpty(contentType)) return true; // missing CT — treat as suspicious
		if (contentType.Contains("html", StringComparison.OrdinalIgnoreCase)) return true;
		if (contentType.Contains("json", StringComparison.OrdinalIgnoreCase)) return false;
		return true;
	}

	private static TimeSpan? GetRetryAfter(HttpResponseMessage response)
	{
		var ra = response.Headers.RetryAfter;
		if (ra == null) return null;
		if (ra.Delta.HasValue) return ra.Delta.Value;
		if (ra.Date.HasValue)
		{
			var delta = ra.Date.Value - DateTimeOffset.UtcNow;
			if (delta > TimeSpan.Zero) return delta;
		}
		return null;
	}

	private static TimeSpan ComputeBackoff(int attempt)
	{
		// Exponential backoff: 0.5s, 1s, 2s, 4s, 8s — capped — plus 0–250ms jitter.
		var exp = InitialRetryDelay.TotalMilliseconds * Math.Pow(2, attempt - 1);
		var capped = Math.Min(exp, MaxRetryDelay.TotalMilliseconds);
		int jitter;
		lock (RetryJitter) { jitter = RetryJitter.Next(0, 250); }
		return TimeSpan.FromMilliseconds(capped + jitter);
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
			if (_httpClientSupplied) _httpClient.Dispose();
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