using System.Net;
using System.Text.Json;
using NaturalApi;
using NaturalApi.Reporter;

namespace TheBleedingDeacons.Unity.Tests;

/// <summary>
/// A MockHttpExecutor tailored for testing UnityRestSharp endpoints via NaturalApi's fluent DSL.
/// Wraps mock API responses in NaturalApi's IApiResultContext so ShouldReturn() works naturally.
/// </summary>
internal sealed class UnityMockHttpExecutor : IHttpExecutor
{
    public ApiRequestSpec? LastSpec { get; private set; }

    private int _statusCode = 200;
    private string _responseBody = """{"success":true,"data":null}""";
    private IDictionary<string, string> _headers = new Dictionary<string, string>();

    private INaturalReporter _reporter = new NullReporter();
    public INaturalReporter Reporter { get => _reporter; set => _reporter = value ?? new NullReporter(); }

    public void SetupResponse(int statusCode, object responseBody, IDictionary<string, string>? headers = null)
    {
        _statusCode = statusCode;
        _responseBody = responseBody is string s ? s : JsonSerializer.Serialize(responseBody, new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
            DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull
        });
        _headers = headers ?? new Dictionary<string, string>();
    }

    public void SetupSuccessResponse<T>(T data, int total = 1, int page = 1, int perPage = 100) where T : class
    {
        var wrapped = new
        {
            success = true,
            data,
            meta = new { total, page, per_page = perPage, total_pages = (int)Math.Ceiling((double)total / perPage) }
        };
        SetupResponse(200, wrapped);
    }

    public void SetupErrorResponse(int statusCode, string code, string message)
    {
        var error = new { success = false, error = new { code, message } };
        SetupResponse(statusCode, error);
    }

    public IApiResultContext Execute(ApiRequestSpec spec)
    {
        LastSpec = spec;

        var response = new HttpResponseMessage((HttpStatusCode)_statusCode)
        {
            Content = new StringContent(_responseBody, System.Text.Encoding.UTF8, "application/json")
        };

        foreach (var header in _headers)
            response.Headers.TryAddWithoutValidation(header.Key, header.Value);

        return new UnityMockApiResultContext(response, _responseBody, _headers, this);
    }
}

/// <summary>
/// Mock API result context that properly deserializes UnityRestSharp's ApiResponse wrapper
/// while supporting NaturalApi's fluent ShouldReturn assertions.
/// </summary>
internal sealed class UnityMockApiResultContext : IApiResultContext
{
    public HttpResponseMessage Response { get; }
    public int StatusCode { get; }
    public IDictionary<string, string> Headers { get; }
    public string RawBody { get; }
    public long Duration { get; set; }
    private readonly IHttpExecutor _httpExecutor;

    public UnityMockApiResultContext(
        HttpResponseMessage response,
        string responseBody,
        IDictionary<string, string> headers,
        IHttpExecutor httpExecutor)
    {
        Response = response;
        StatusCode = (int)response.StatusCode;
        Headers = headers;
        RawBody = responseBody;
        _httpExecutor = httpExecutor;
        Duration = 0;
    }

    public T BodyAs<T>()
    {
        if (typeof(T) == typeof(string))
            return (T)(object)RawBody;

        try
        {
            return JsonSerializer.Deserialize<T>(RawBody, new JsonSerializerOptions
            {
                PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
                PropertyNameCaseInsensitive = true
            }) ?? default!;
        }
        catch
        {
            return default!;
        }
    }

    private ApiAssertionException CreateAssertionException(string message, string expectation, string actual)
    {
        var endpoint = "mock-endpoint";
        var verb = "GET";
        return new ApiAssertionException(message, expectation, actual, endpoint, verb, RawBody);
    }

    public IApiResultContext ShouldReturn<T>(
        int? status = null,
        Func<T, bool>? bodyValidator = null,
        Func<IDictionary<string, string>, bool>? headers = null)
    {
        if (status.HasValue && StatusCode != status.Value)
            throw CreateAssertionException(
                $"Expected status {status.Value} but got {StatusCode}",
                $"Status {status.Value}",
                $"Status {StatusCode}");

        if (bodyValidator != null)
        {
            var body = BodyAs<T>();
            if (!bodyValidator(body))
                throw CreateAssertionException(
                    $"Body validation failed for type {typeof(T).Name}",
                    $"Body of type {typeof(T).Name} passing validator",
                    "Validator returned false");
        }

        if (headers != null && !headers(Headers))
            throw CreateAssertionException(
                "Header validation failed",
                "Headers passing validator",
                "Validator returned false");

        return this;
    }

    public IApiResultContext ShouldReturn(int status)
    {
        if (StatusCode != status)
            throw CreateAssertionException(
                $"Expected status {status} but got {StatusCode}",
                $"Status {status}",
                $"Status {StatusCode}");
        return this;
    }

    public IApiResultContext ShouldReturn<T>(Func<T, bool> bodyValidator)
    {
        var body = BodyAs<T>();
        if (!bodyValidator(body))
            throw CreateAssertionException(
                $"Body validation failed for type {typeof(T).Name}",
                $"Body of type {typeof(T).Name} passing validator",
                "Validator returned false");
        return this;
    }

    public IApiResultContext ShouldReturn(int status, Func<IDictionary<string, string>, bool> headers)
    {
        if (StatusCode != status)
            throw CreateAssertionException(
                $"Expected status {status} but got {StatusCode}",
                $"Status {status}",
                $"Status {StatusCode}");
        if (!headers(Headers))
            throw CreateAssertionException(
                "Header validation failed",
                "Headers passing validator",
                "Validator returned false");
        return this;
    }

    public T ShouldReturn<T>() => BodyAs<T>();

    public IApiResultContext Then(Action<IApiResult> next)
    {
        // Use fully-qualified name to resolve ambiguity with TheBleedingDeacons.Unity.Client.ApiResponse<T>
        var result = new NaturalApi.ApiResponse<object>(this, _httpExecutor);
        next?.Invoke(result);
        return this;
    }

    public string? GetCookie(string name) => null;
}
