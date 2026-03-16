using System.Net;
using System.Text.Json;
using Microsoft.VisualStudio.TestTools.UnitTesting;
using NaturalApi;
using TheBleedingDeacons.Unity.Client;
using TheBleedingDeacons.Unity.Models;

namespace TheBleedingDeacons.Unity.Tests;

/// <summary>
/// Mocked unit tests for UnityRestSharp using NaturalApi's MockHttpExecutor pattern.
/// Each test injects a MockHttpMessageHandler into UnityRestSharp's HttpClient, verifying
/// that the client builds correct URLs, sends proper headers, and deserializes responses.
/// NaturalApi's fluent assertions validate the mock responses where applicable.
/// </summary>
[TestClass]
public class UnityRestSharpTests
{
    private const string BaseUrl = "https://test.example.com";
    private const string ApiKey = "test-api-key-12345";

    private MockHttpMessageHandler _mockHandler = null!;
    private HttpClient _httpClient = null!;
    private UnityRestSharp _client = null!;

    [TestInitialize]
    public void Setup()
    {
        _mockHandler = new MockHttpMessageHandler();
        _httpClient = new HttpClient(_mockHandler);
        _client = new UnityRestSharp(BaseUrl, ApiKey, _httpClient);
    }

    [TestCleanup]
    public void Cleanup()
    {
        _client.Dispose();
    }

    #region Constructor Tests

    [TestMethod]
    public void Constructor_Should_Throw_When_BaseUrl_Is_Null()
    {
        Assert.ThrowsException<ArgumentNullException>(() =>
            new UnityRestSharp(null!, ApiKey));
    }

    [TestMethod]
    public void Constructor_Should_Throw_When_BaseUrl_Is_Empty()
    {
        Assert.ThrowsException<ArgumentException>(() =>
            new UnityRestSharp("", ApiKey));
    }

    [TestMethod]
    public void Constructor_Should_Throw_When_ApiKey_Is_Null()
    {
        Assert.ThrowsException<ArgumentNullException>(() =>
            new UnityRestSharp(BaseUrl, null!));
    }

    [TestMethod]
    public void Constructor_Should_Throw_When_ApiKey_Is_Whitespace()
    {
        Assert.ThrowsException<ArgumentException>(() =>
            new UnityRestSharp(BaseUrl, "   "));
    }

    [TestMethod]
    public void Constructor_Should_Set_Authorization_Header()
    {
        // The HttpClient should have a Bearer token set
        Assert.AreEqual("Bearer", _httpClient.DefaultRequestHeaders.Authorization?.Scheme);
        Assert.AreEqual(ApiKey, _httpClient.DefaultRequestHeaders.Authorization?.Parameter);
    }

    [TestMethod]
    public void Constructor_Should_Set_Accept_Header()
    {
        var acceptHeader = _httpClient.DefaultRequestHeaders.Accept.FirstOrDefault();
        Assert.IsNotNull(acceptHeader);
        Assert.AreEqual("application/json", acceptHeader.MediaType);
    }

    [TestMethod]
    public void Constructor_Should_Set_UserAgent_Header()
    {
        var userAgent = _httpClient.DefaultRequestHeaders.UserAgent.ToString();
        Assert.IsTrue(userAgent.Contains("IntegrityClient/1.0"));
    }

    #endregion

    #region Groups - GET /groups

    [TestMethod]
    public async Task GetGroupsAsync_Should_Return_Groups_List()
    {
        // Arrange
        var groups = new List<Group>
        {
            new() { Id = 1, Title = "Serenity Group", Email = "serenity@test.com" },
            new() { Id = 2, Title = "Hope Group", Email = "hope@test.com" }
        };

        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = groups,
            meta = new { total = 2, page = 1, per_page = 100, total_pages = 1 }
        });

        // Act
        var result = await _client.GetGroupsAsync();

        // Assert
        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(2, result.Data.Count);
        Assert.AreEqual("Serenity Group", result.Data[0].Title);
        Assert.AreEqual("Hope Group", result.Data[1].Title);

        // Verify URL
        var request = _mockHandler.LastRequest!;
        Assert.IsTrue(request.RequestUri!.ToString().Contains("/wp-json/integrity/v1/groups"));
        Assert.IsTrue(request.RequestUri.ToString().Contains("page=1"));
        Assert.IsTrue(request.RequestUri.ToString().Contains("per_page=100"));
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Include_Search_Parameter()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetGroupsAsync(search: "Serenity");

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("search=Serenity"));
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Include_DistrictId_Parameter()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetGroupsAsync(districtId: 5);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("district_id=5"));
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Include_Expand_Meetings_Parameter()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetGroupsAsync(expandMeetings: true);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("expand=meetings"));
    }

    [TestMethod]
    public async Task GetGroupAsync_Should_Return_Single_Group()
    {
        var group = new { id = 42, title = "Unity Group", email = "unity@test.com" };

        _mockHandler.SetupResponse("/groups/42", HttpStatusCode.OK, new
        {
            success = true,
            data = group
        });

        var result = await _client.GetGroupAsync(42);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(42, result.Data.Id);
        Assert.AreEqual("Unity Group", result.Data.Title);
    }

    [TestMethod]
    public async Task GetGroupAsync_Should_Append_Expand_Param()
    {
        _mockHandler.SetupResponse("/groups/1", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 1, title = "Test" }
        });

        await _client.GetGroupAsync(1, expandMeetings: true);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("expand=meetings"));
    }

    #endregion

    #region Meetings - GET /meetings

    [TestMethod]
    public async Task GetMeetingsAsync_Should_Return_Meetings_List()
    {
        var meetings = new[]
        {
            new { id = 1, name = "Monday Night", day = 1, day_of_week = "Monday", time = "19:00", is_online = false },
            new { id = 2, name = "Friday Noon", day = 5, day_of_week = "Friday", time = "12:00", is_online = true }
        };

        _mockHandler.SetupResponse("/meetings", HttpStatusCode.OK, new
        {
            success = true,
            data = meetings,
            meta = new { total = 2, page = 1, per_page = 100, total_pages = 1 }
        });

        var result = await _client.GetMeetingsAsync();

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(2, result.Data.Count);
        Assert.AreEqual("Monday Night", result.Data[0].Name);
    }

    [TestMethod]
    public async Task GetMeetingsAsync_Should_Filter_By_DayOfWeek()
    {
        _mockHandler.SetupResponse("/meetings", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Meeting>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetMeetingsAsync(dayOfWeek: DayOfWeek.Wednesday);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("day=3")); // Wednesday = 3
    }

    [TestMethod]
    public async Task GetMeetingsAsync_Should_Filter_By_Online()
    {
        _mockHandler.SetupResponse("/meetings", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Meeting>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetMeetingsAsync(online: true);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("online=true"));
    }

    [TestMethod]
    public async Task GetMeetingsAsync_Should_Filter_By_GroupId()
    {
        _mockHandler.SetupResponse("/meetings", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Meeting>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetMeetingsAsync(groupId: 7);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("group_id=7"));
    }

    [TestMethod]
    public async Task GetMeetingAsync_Should_Return_Single_Meeting()
    {
        _mockHandler.SetupResponse("/meetings/10", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 10, name = "Big Book Study", day = 2, day_of_week = "Tuesday", time = "20:00" }
        });

        var result = await _client.GetMeetingAsync(10);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(10, result.Data.Id);
        Assert.AreEqual("Big Book Study", result.Data.Name);
    }

    #endregion

    #region Positions - GET /positions

    [TestMethod]
    public async Task GetPositionsAsync_Should_Return_Positions_List()
    {
        var positions = new[]
        {
            new { id = 1, long_name = "Chairperson", short_description = "Chair", term_years = 2 },
            new { id = 2, long_name = "Treasurer", short_description = "Treas", term_years = 2 }
        };

        _mockHandler.SetupResponse("/positions", HttpStatusCode.OK, new
        {
            success = true,
            data = positions,
            meta = new { total = 2, page = 1, per_page = 100, total_pages = 1 }
        });

        var result = await _client.GetPositionsAsync();

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(2, result.Data.Count);
        Assert.AreEqual("Chairperson", result.Data[0].LongName);
    }

    [TestMethod]
    public async Task GetPositionsAsync_Should_Include_Search_Parameter()
    {
        _mockHandler.SetupResponse("/positions", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Position>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetPositionsAsync(search: "Chair");

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("search=Chair"));
    }

    [TestMethod]
    public async Task GetPositionAsync_Should_Return_Single_Position()
    {
        _mockHandler.SetupResponse("/positions/3", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 3, long_name = "Secretary", short_description = "Sec", term_years = 1 }
        });

        var result = await _client.GetPositionAsync(3);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(3, result.Data.Id);
        Assert.AreEqual("Secretary", result.Data.LongName);
    }

    #endregion

    #region Members - GET /members

    [TestMethod]
    public async Task GetMembersAsync_Should_Return_Members_List()
    {
        var members = new[]
        {
            new { id = 1, private_name = "John D.", anonymous_name = "John D.", email = "john@test.com", home_group_id = 10 },
            new { id = 2, private_name = "Jane S.", anonymous_name = "Jane S.", email = "jane@test.com", home_group_id = 20 }
        };

        _mockHandler.SetupResponse("/members", HttpStatusCode.OK, new
        {
            success = true,
            data = members,
            meta = new { total = 2, page = 1, per_page = 100, total_pages = 1 }
        });

        var result = await _client.GetMembersAsync();

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(2, result.Data.Count);
        Assert.AreEqual("John D.", result.Data[0].PrivateName);
    }

    [TestMethod]
    public async Task GetMembersAsync_Should_Filter_By_HomeGroupId()
    {
        _mockHandler.SetupResponse("/members", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Member>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetMembersAsync(homeGroupId: 42);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("home_group_id=42"));
    }

    [TestMethod]
    public async Task GetMembersAsync_Should_Include_Expand_HomeGroup()
    {
        _mockHandler.SetupResponse("/members", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Member>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetMembersAsync(expandHomeGroup: true);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("expand=home_group"));
    }

    [TestMethod]
    public async Task GetMemberAsync_Should_Return_Single_Member()
    {
        _mockHandler.SetupResponse("/members/5", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 5, private_name = "Bob R.", anonymous_name = "Bob R.", email = "bob@test.com" }
        });

        var result = await _client.GetMemberAsync(5);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(5, result.Data.Id);
        Assert.AreEqual("Bob R.", result.Data.PrivateName);
    }

    [TestMethod]
    public async Task GetMemberAsync_Should_Append_Expand_Param()
    {
        _mockHandler.SetupResponse("/members/5", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 5, private_name = "Bob R." }
        });

        await _client.GetMemberAsync(5, expandHomeGroup: true);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("expand=home_group"));
    }

    #endregion

    #region Members - POST /members/{id}/update

    [TestMethod]
    public async Task UpdateMemberAsync_Should_Send_Post_With_Body()
    {
        _mockHandler.SetupResponse("/members/5/update", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 5, private_name = "Bob R.", anonymous_name = "Updated Bob", email = "bob@test.com" }
        });

        var updateRequest = new UpdateMemberRequest
        {
            AnonymousName = "Updated Bob",
            MobileNumber = "555-1234"
        };

        var result = await _client.UpdateMemberAsync(5, updateRequest);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);

        // Verify it was a POST
        Assert.AreEqual(HttpMethod.Post, _mockHandler.LastRequest!.Method);

        // Verify the URL
        Assert.IsTrue(_mockHandler.LastRequest.RequestUri!.ToString().Contains("/members/5/update"));

        // Verify the body was sent
        var body = await _mockHandler.LastRequest.Content!.ReadAsStringAsync();
        Assert.IsTrue(body.Contains("anonymous_name"));
        Assert.IsTrue(body.Contains("Updated Bob"));
    }

    [TestMethod]
    public async Task UpdateMemberAsync_Should_Omit_Null_Fields()
    {
        _mockHandler.SetupResponse("/members/1/update", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 1, private_name = "Test" }
        });

        var updateRequest = new UpdateMemberRequest
        {
            AnonymousName = "Only This Field"
            // All other fields are null → should be omitted
        };

        await _client.UpdateMemberAsync(1, updateRequest);

        var body = await _mockHandler.LastRequest!.Content!.ReadAsStringAsync();
        Assert.IsTrue(body.Contains("anonymous_name"));
        Assert.IsFalse(body.Contains("personal_email"));
        Assert.IsFalse(body.Contains("mobile_number"));
    }

    #endregion

    #region Intergroup Meetings - GET

    [TestMethod]
    public async Task GetIntergroupMeetingsAsync_Should_Return_List()
    {
        _mockHandler.SetupResponse("/intergroup-meetings", HttpStatusCode.OK, new
        {
            success = true,
            data = new[]
            {
                new { id = 1, title = "January Intergroup", date = "2025-01-15" },
                new { id = 2, title = "February Intergroup", date = "2025-02-12" }
            },
            meta = new { total = 2, page = 1, per_page = 100, total_pages = 1 }
        });

        var result = await _client.GetIntergroupMeetingsAsync();

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(2, result.Data.Count);
        Assert.AreEqual("January Intergroup", result.Data[0].Title);
    }

    [TestMethod]
    public async Task GetIntergroupMeetingsAsync_Should_Include_Date_Filters()
    {
        _mockHandler.SetupResponse("/intergroup-meetings", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<IntergroupMeeting>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetIntergroupMeetingsAsync(
            dateFrom: new DateOnly(2025, 1, 1),
            dateTo: new DateOnly(2025, 12, 31));

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("date_from=2025-01-01"));
        Assert.IsTrue(url.Contains("date_to=2025-12-31"));
    }

    [TestMethod]
    public async Task GetIntergroupMeetingAsync_Should_Return_Single()
    {
        _mockHandler.SetupResponse("/intergroup-meetings/7", HttpStatusCode.OK, new
        {
            success = true,
            data = new { id = 7, title = "March Intergroup", date = "2025-03-12" }
        });

        var result = await _client.GetIntergroupMeetingAsync(7);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.AreEqual(7, result.Data.Id);
    }

    #endregion

    #region Intergroup Meetings - Registration

    [TestMethod]
    public async Task RegisterGroupAsync_Should_Post_Registration()
    {
        _mockHandler.SetupResponse("/register-group", HttpStatusCode.OK, new
        {
            success = true,
            data = new
            {
                intergroup_meeting_id = 1,
                group_id = 10,
                member_id = 5,
                gsr_name = "John D.",
                gsr_proxy = false,
                registered = true
            }
        });

        var result = await _client.RegisterGroupAsync(
            intergroupMeetingId: 1,
            groupId: 10,
            memberId: 5,
            gsrName: "John D.");

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.IsTrue(result.Data.Registered);

        // Verify POST method
        Assert.AreEqual(HttpMethod.Post, _mockHandler.LastRequest!.Method);

        // Verify body
        var body = await _mockHandler.LastRequest.Content!.ReadAsStringAsync();
        Assert.IsTrue(body.Contains("group_id"));
        Assert.IsTrue(body.Contains("gsr_name"));
    }

    [TestMethod]
    public async Task RegisterGroupAsync_Should_Include_Proxy_Info()
    {
        _mockHandler.SetupResponse("/register-group", HttpStatusCode.OK, new
        {
            success = true,
            data = new
            {
                intergroup_meeting_id = 1,
                group_id = 10,
                member_id = 5,
                gsr_name = "John D.",
                gsr_proxy = true,
                gsr_proxy_name = "Jane S.",
                registered = true
            }
        });

        await _client.RegisterGroupAsync(
            intergroupMeetingId: 1,
            groupId: 10,
            memberId: 5,
            gsrName: "John D.",
            gsrProxy: true,
            gsrProxyName: "Jane S.");

        var body = await _mockHandler.LastRequest!.Content!.ReadAsStringAsync();
        Assert.IsTrue(body.Contains("gsr_proxy"));
        Assert.IsTrue(body.Contains("Jane S."));
    }

    [TestMethod]
    public async Task UnregisterGroupAsync_Should_Post_Unregistration()
    {
        _mockHandler.SetupResponse("/unregister-group", HttpStatusCode.OK, new
        {
            success = true,
            data = new
            {
                intergroup_meeting_id = 1,
                group_id = 10,
                registered = false
            }
        });

        var result = await _client.UnregisterGroupAsync(intergroupMeetingId: 1, groupId: 10);

        Assert.IsTrue(result.Success);
        Assert.AreEqual(HttpMethod.Post, _mockHandler.LastRequest!.Method);
    }

    [TestMethod]
    public async Task RegisterOfficerAsync_Should_Post_Registration()
    {
        _mockHandler.SetupResponse("/register-officer", HttpStatusCode.OK, new
        {
            success = true,
            data = new
            {
                intergroup_meeting_id = 1,
                officer_id = 3,
                officer_name = "Bob R.",
                position_name = "Chairperson",
                registered = true
            }
        });

        var result = await _client.RegisterOfficerAsync(
            intergroupMeetingId: 1,
            officerId: 3,
            positionName: "Chairperson",
            officerName: "Bob R.");

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Data);
        Assert.IsTrue(result.Data.Registered);
        Assert.AreEqual(HttpMethod.Post, _mockHandler.LastRequest!.Method);
    }

    [TestMethod]
    public async Task UnregisterOfficerAsync_Should_Post_Unregistration()
    {
        _mockHandler.SetupResponse("/unregister-officer", HttpStatusCode.OK, new
        {
            success = true,
            data = new
            {
                intergroup_meeting_id = 1,
                officer_id = 3,
                registered = false
            }
        });

        var result = await _client.UnregisterOfficerAsync(intergroupMeetingId: 1, officerId: 3);

        Assert.IsTrue(result.Success);
        Assert.AreEqual(HttpMethod.Post, _mockHandler.LastRequest!.Method);
    }

    #endregion

    #region Health Check

    [TestMethod]
    public async Task CheckHealthAsync_Should_Return_Health_Status()
    {
        _mockHandler.SetupResponse("/health", HttpStatusCode.OK, new
        {
            status = "ok",
            timestamp = "2025-02-26T12:00:00Z",
            version = "1.0.0",
            unity_available = true
        });

        var result = await _client.CheckHealthAsync();

        Assert.IsNotNull(result);
        Assert.AreEqual("ok", result.Status);
        Assert.IsTrue(result.UnityAvailable);
    }

    [TestMethod]
    public async Task CheckHealthAsync_Should_Return_Null_On_Error()
    {
        _mockHandler.SetupException("/health", "Connection refused");

        var result = await _client.CheckHealthAsync();

        Assert.IsNull(result);
    }

    #endregion

    #region Error Handling

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_401_Unauthorized()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.Unauthorized, new
        {
            success = false,
            error = new { code = "unauthorized", message = "Invalid API key" }
        });

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual(401, result.StatusCode);
        Assert.IsNotNull(result.Error);
        Assert.AreEqual("unauthorized", result.Error.Code);
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_403_Forbidden()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.Forbidden, new
        {
            success = false,
            error = new { code = "forbidden", message = "Insufficient permissions" }
        });

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual(403, result.StatusCode);
        Assert.AreEqual("forbidden", result.Error!.Code);
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_404_NotFound()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.NotFound, new
        {
            success = false,
            error = new { code = "not_found", message = "Resource not found" }
        });

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual(404, result.StatusCode);
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_429_RateLimit()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.TooManyRequests, new
        {
            success = false,
            error = new { code = "rate_limited", message = "Too many requests" }
        }, headers: new Dictionary<string, string>
        {
            ["X-RateLimit-Limit"] = "100",
            ["X-RateLimit-Remaining"] = "0",
            ["X-RateLimit-Reset"] = "1740600000"
        });

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual(429, result.StatusCode);
        Assert.IsNotNull(result.RateLimit);
        Assert.AreEqual(100, result.RateLimit.Limit);
        Assert.AreEqual(0, result.RateLimit.Remaining);
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_500_ServerError()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.InternalServerError, new
        {
            success = false,
            error = new { code = "server_error", message = "Internal server error" }
        });

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual(500, result.StatusCode);
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_Network_Error()
    {
        _mockHandler.SetupException("/groups", "Connection timed out");

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual(0, result.StatusCode);
        Assert.IsNotNull(result.Error);
        Assert.AreEqual("network_error", result.Error.Code);
    }

    [TestMethod]
    public async Task GetGroupsAsync_Should_Handle_Malformed_Json()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, "this is not valid json{{{");

        var result = await _client.GetGroupsAsync();

        Assert.IsFalse(result.Success);
        Assert.AreEqual("parse_error", result.Error!.Code);
    }

    #endregion

    #region Rate Limit Headers

    [TestMethod]
    public async Task Should_Parse_RateLimit_Headers()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        }, headers: new Dictionary<string, string>
        {
            ["X-RateLimit-Limit"] = "1000",
            ["X-RateLimit-Remaining"] = "999",
            ["X-RateLimit-Reset"] = "1740600000"
        });

        var result = await _client.GetGroupsAsync();

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.RateLimit);
        Assert.AreEqual(1000, result.RateLimit.Limit);
        Assert.AreEqual(999, result.RateLimit.Remaining);
        Assert.AreEqual(1740600000L, result.RateLimit.Reset);
    }

    #endregion

    #region Pagination

    [TestMethod]
    public async Task GetGroupsAsync_Should_Support_Pagination()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 250, page = 3, per_page = 50, total_pages = 5 }
        });

        var result = await _client.GetGroupsAsync(page: 3, perPage: 50);

        Assert.IsTrue(result.Success);
        Assert.IsNotNull(result.Meta);
        Assert.AreEqual(250, result.Meta.Total);
        Assert.AreEqual(3, result.Meta.Page);
        Assert.AreEqual(50, result.Meta.PerPage);
        Assert.AreEqual(5, result.Meta.TotalPages);

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        Assert.IsTrue(url.Contains("page=3"));
        Assert.IsTrue(url.Contains("per_page=50"));
    }

    #endregion

    #region URL Construction

    [TestMethod]
    public async Task Should_Trim_Trailing_Slash_From_BaseUrl()
    {
        var handler = new MockHttpMessageHandler();
        handler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        var httpClient = new HttpClient(handler);
        using var client = new UnityRestSharp("https://test.example.com/", ApiKey, httpClient);

        await client.GetGroupsAsync();

        var url = handler.LastRequest!.RequestUri!.ToString();
        Assert.IsFalse(url.Contains("test.example.com//"));
        Assert.IsTrue(url.StartsWith("https://test.example.com/wp-json/integrity/v1/groups"));
    }

    [TestMethod]
    public async Task Should_Encode_Search_Parameters()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        await _client.GetGroupsAsync(search: "test group & friends");

        var url = _mockHandler.LastRequest!.RequestUri!.ToString();
        // Should be URL-encoded
        Assert.IsTrue(url.Contains("search=test%20group%20%26%20friends") ||
                       url.Contains("search=test+group+%26+friends"));
    }

    #endregion

    #region Cancellation

    [TestMethod]
    public async Task GetGroupsAsync_Should_Respect_CancellationToken()
    {
        _mockHandler.SetupResponse("/groups", HttpStatusCode.OK, new
        {
            success = true,
            data = new List<Group>(),
            meta = new { total = 0, page = 1, per_page = 100, total_pages = 0 }
        });

        using var cts = new CancellationTokenSource();
        cts.Cancel();

        // Should throw OperationCanceledException or TaskCanceledException
        await Assert.ThrowsExceptionAsync<TaskCanceledException>(async () =>
            await _client.GetGroupsAsync(cancellationToken: cts.Token));
    }

    #endregion
}

/// <summary>
/// NaturalApi-style fluent tests that demonstrate using MockHttpExecutor
/// to validate UnityRestSharp response shapes through NaturalApi's DSL.
/// These tests show how the NaturalApi pattern can complement direct unit tests.
/// </summary>
[TestClass]
public class UnityRestSharpNaturalApiTests
{
    private UnityMockHttpExecutor _mockExecutor = null!;
    private IApi _api = null!;

    [TestInitialize]
    public void Setup()
    {
        _mockExecutor = new UnityMockHttpExecutor();
        _api = new Api(_mockExecutor);
    }

    [TestMethod]
    public void GetGroups_Fluent_Should_Return_200_With_Groups()
    {
        // Arrange — mock a Unity-style wrapped response
        _mockExecutor.SetupSuccessResponse(new[]
        {
            new { id = 1, title = "Serenity Group" },
            new { id = 2, title = "Hope Group" }
        }, total: 2);

        // Act & Assert — NaturalApi fluent DSL
        _api.For("/wp-json/integrity/v1/groups")
            .Get()
            .ShouldReturn(200);
    }

    [TestMethod]
    public void GetGroups_Fluent_Should_Validate_Response_Headers()
    {
        _mockExecutor.SetupResponse(200, new
        {
            success = true,
            data = new[] { new { id = 1, title = "Test" } }
        }, new Dictionary<string, string>
        {
            ["X-RateLimit-Limit"] = "1000",
            ["X-RateLimit-Remaining"] = "999"
        });

        _api.For("/wp-json/integrity/v1/groups")
            .Get()
            .ShouldReturn(200, headers => headers.ContainsKey("X-RateLimit-Limit"));
    }

    [TestMethod]
    public void GetGroups_Fluent_Should_Validate_Error_Response()
    {
        _mockExecutor.SetupErrorResponse(401, "unauthorized", "Invalid API key");

        var result = _api.For("/wp-json/integrity/v1/groups")
            .UsingAuth("Bearer bad-key")
            .Get();

        Assert.AreEqual(401, result.StatusCode);
    }

    [TestMethod]
    public void PostUpdateMember_Fluent_Should_Send_Body()
    {
        _mockExecutor.SetupSuccessResponse(new { id = 5, anonymous_name = "Updated" });

        var updateBody = new { anonymous_name = "Updated Bob" };

        var result = _api.For("/wp-json/integrity/v1/members/5/update")
            .WithHeader("Content-Type", "application/json")
            .Post(updateBody);

        result.ShouldReturn(200);

        // Verify the executor captured the request spec with body
        Assert.IsNotNull(_mockExecutor.LastSpec);
        Assert.AreEqual(HttpMethod.Post, _mockExecutor.LastSpec.Method);
    }

    [TestMethod]
    public void RegisterGroup_Fluent_Should_Post_To_Correct_Endpoint()
    {
        _mockExecutor.SetupSuccessResponse(new
        {
            intergroup_meeting_id = 1,
            group_id = 10,
            registered = true
        });

        _api.For("/wp-json/integrity/v1/intergroup-meetings/1/register-group")
            .Post(new { group_id = 10, member_id = 5, gsr_name = "John D." })
            .ShouldReturn(200);

        Assert.IsNotNull(_mockExecutor.LastSpec);
        Assert.IsTrue(_mockExecutor.LastSpec.Endpoint.Contains("register-group"));
    }

    [TestMethod]
    public void HealthCheck_Fluent_Should_Return_Status()
    {
        _mockExecutor.SetupResponse(200,
            """{"status":"ok","timestamp":"2025-02-26T12:00:00Z","version":"1.0.0","unity_available":true}""");

        _api.For("/wp-json/integrity/v1/health")
            .Get()
            .ShouldReturn(200);
    }

    [TestMethod]
    public void GetMeetings_Fluent_With_QueryParams_Should_Build_Correct_Spec()
    {
        _mockExecutor.SetupSuccessResponse(new List<object>(), total: 0);

        _api.For("/wp-json/integrity/v1/meetings")
            .WithQueryParam("day", 3)
            .WithQueryParam("online", "true")
            .Get();

        Assert.IsNotNull(_mockExecutor.LastSpec);
        Assert.IsTrue(_mockExecutor.LastSpec.QueryParams.ContainsKey("day"));
        Assert.IsTrue(_mockExecutor.LastSpec.QueryParams.ContainsKey("online"));
    }

    [TestMethod]
    public void GetGroup_Fluent_Should_Include_Auth_Header()
    {
        _mockExecutor.SetupSuccessResponse(new { id = 1, title = "Test Group" });

        _api.For("/wp-json/integrity/v1/groups/1")
            .UsingAuth("Bearer test-key-123")
            .Get()
            .ShouldReturn(200);

        Assert.IsNotNull(_mockExecutor.LastSpec);
        Assert.IsTrue(_mockExecutor.LastSpec.Headers.ContainsKey("Authorization"));
        Assert.AreEqual("Bearer test-key-123", _mockExecutor.LastSpec.Headers["Authorization"]);
    }

    [TestMethod]
    public void GetMembers_Fluent_Should_Chain_Multiple_Params()
    {
        _mockExecutor.SetupSuccessResponse(new List<object>());

        _api.For("/wp-json/integrity/v1/members")
            .WithQueryParam("page", 2)
            .WithQueryParam("per_page", 50)
            .WithQueryParam("search", "John")
            .WithQueryParam("home_group_id", 10)
            .WithQueryParam("expand", "home_group")
            .Get()
            .ShouldReturn(200);

        var spec = _mockExecutor.LastSpec!;
        Assert.AreEqual(5, spec.QueryParams.Count);
    }
}
