# Integrity WordPress Plugin

A secure REST API bridge for the Unity WordPress plugin, providing authenticated access to Groups and Meetings data for external applications (especially C# applications).

## Features

### Security
- **API Key Authentication**: Secure, hashed API keys using Argon2id
- **Rate Limiting**: Configurable per-key rate limits with sliding window
- **IP Whitelisting**: Optional IP address/CIDR range restrictions
- **HTTPS Enforcement**: Configurable HTTPS-only mode
- **Audit Logging**: Complete request logging for security monitoring
- **Key Expiration**: Optional expiration dates for API keys

### API Endpoints
- `GET /wp-json/integrity/v1/groups` - List all groups
- `GET /wp-json/integrity/v1/groups/{id}` - Get single group
- `GET /wp-json/integrity/v1/meetings` - List all meetings
- `GET /wp-json/integrity/v1/meetings/{id}` - Get single meeting
- `GET /wp-json/integrity/v1/health` - Health check (no auth required)

## Installation

### WordPress Plugin

1. Upload the `integrity` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Ensure the Unity plugin is also installed and activated
4. Navigate to **Integrity API** in the admin menu
5. Create your first API key

### C# Client

#### Via NuGet (when published)
```bash
dotnet add package Integrity.Client
```

#### Manual Installation
Copy `IntegrityClient.cs` to your project.

## Usage

### Creating an API Key

1. Go to **WordPress Admin > Integrity API > API Keys**
2. Enter a descriptive name (e.g., "Mobile App Production")
3. Select permissions (Groups, Meetings, or Full Access)
4. Optionally configure:
   - Rate limit (requests per hour)
   - Expiration date
   - IP whitelist
5. Click **Create API Key**
6. **Copy the key immediately** - it cannot be recovered

### C# Client Usage

```csharp
using Integrity.Client;

// Initialize the client
using var client = new IntegrityClient(
    baseUrl: "https://your-wordpress-site.com",
    apiKey: "int_your_api_key_here"
);

// Check API health
var health = await client.CheckHealthAsync();
Console.WriteLine($"API Status: {health?.Status}");

// Get all groups
var groupsResponse = await client.GetGroupsAsync();
if (groupsResponse.Success)
{
    foreach (var group in groupsResponse.Data!)
    {
        Console.WriteLine($"Group: {group.Title}");
        Console.WriteLine($"  Email: {group.Email}");
        Console.WriteLine($"  Meetings: {group.MeetingIds.Count}");
    }
}

// Get a specific group
var groupResponse = await client.GetGroupAsync(123);
if (groupResponse.Success)
{
    var group = groupResponse.Data!;
    Console.WriteLine($"Found: {group.Title}");
}

// Get meetings with filters
var meetingsResponse = await client.GetMeetingsAsync(
    day: 0,           // Sunday
    online: true,     // Online meetings only
    page: 1,
    perPage: 50
);

// Check rate limit status
Console.WriteLine($"Rate Limit Remaining: {meetingsResponse.RateLimit?.Remaining}");
Console.WriteLine($"Resets At: {meetingsResponse.RateLimit?.ResetDateTime}");
```

### Direct HTTP Usage

```bash
# Get all groups
curl -X GET "https://your-site.com/wp-json/integrity/v1/groups" \
  -H "Authorization: Bearer int_your_api_key_here" \
  -H "Accept: application/json"

# Get meetings for a specific day
curl -X GET "https://your-site.com/wp-json/integrity/v1/meetings?day=1&online=true" \
  -H "Authorization: Bearer int_your_api_key_here"

# Health check (no auth required)
curl -X GET "https://your-site.com/wp-json/integrity/v1/health"
```

## API Response Format

### Success Response
```json
{
  "success": true,
  "data": [...],
  "meta": {
    "total": 150,
    "page": 1,
    "per_page": 100,
    "total_pages": 2
  }
}
```

### Error Response
```json
{
  "success": false,
  "error": {
    "code": "invalid_api_key",
    "message": "Invalid or expired API key"
  }
}
```

### Rate Limit Headers
Every authenticated response includes:
- `X-RateLimit-Limit`: Maximum requests per hour
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Unix timestamp when limit resets

## Security Best Practices

1. **Always use HTTPS** in production
2. **Rotate API keys** periodically
3. **Use IP whitelisting** when possible
4. **Set appropriate rate limits** based on expected usage
5. **Monitor the audit log** for suspicious activity
6. **Use minimal permissions** - only grant what's needed
7. **Set expiration dates** for temporary integrations

## Query Parameters

### Groups Endpoint
| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | int | Page number (default: 1) |
| `per_page` | int | Items per page, max 500 (default: 100) |
| `search` | string | Search term |
| `district_id` | int | Filter by district ID |

### Meetings Endpoint
| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | int | Page number (default: 1) |
| `per_page` | int | Items per page, max 500 (default: 100) |
| `day` | int | Day of week (0=Sunday, 6=Saturday) |
| `online` | bool | Filter online meetings |
| `group_id` | int | Filter by group ID |
| `search` | string | Search term |

## Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `missing_api_key` | 401 | No API key provided |
| `invalid_api_key` | 401 | Key is invalid, revoked, or expired |
| `insufficient_permissions` | 403 | Key lacks required permission |
| `https_required` | 403 | HTTPS is required but request was HTTP |
| `rate_limit_exceeded` | 429 | Rate limit has been exceeded |
| `not_found` | 404 | Requested resource not found |
| `internal_error` | 500 | Server error occurred |

## Requirements

### WordPress Plugin
- WordPress 6.0+
- PHP 8.0+
- Unity plugin installed and activated
- MySQL 5.7+ or MariaDB 10.2+

### C# Client
- .NET 6.0, 7.0, or 8.0
- System.Text.Json 8.0+

## License

GPL v2 or later (WordPress plugin)
MIT (C# client)
