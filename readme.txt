=== Integrity ===
Contributors: thebleedingdeacons
Tags: api, rest, authentication, security, unity
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.18.1
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Secure REST API bridge for Unity plugin - provides authenticated access to Groups and Meetings for external applications.

== Description ==

Secure REST API bridge for the [Unity](https://github.com/thebleedingdeacons/unity) WordPress plugin suite. Integrity provides authenticated, rate-limited access to Groups, Meetings, Members, Positions, and Intergroup Meetings for external applications — with client libraries for both PHP and .NET.

**Key features:**

* **API Key Authentication** — cryptographically secure keys using Argon2id hashing; plain-text key shown once at creation
* **Granular Permissions** — scope each key to specific resources and actions (`groups:read`, `members:write`, `intergroup-meetings:write`, `*`, etc.)
* **Rate Limiting** — configurable per-key hourly limits with sliding-window enforcement and standard `X-RateLimit-*` headers
* **IP Whitelisting** — optionally restrict keys to specific IPv4/IPv6 addresses or CIDR ranges
* **HTTPS Enforcement** — reject plain HTTP in production (auto-bypassed when `WP_DEBUG` is true)
* **Audit Logging** — every request logged with key, endpoint, method, status, response time, IP, user-agent, and sanitised parameters
* **Key Expiration** — optional expiration dates; expired keys are automatically rejected
* **Automatic Cleanup** — daily cron removes stale rate-limit records and aged audit logs
* **Client Libraries** — typed clients for PHP (cURL-based) and .NET 9 (C# / `System.Text.Json`)

== Installation ==

1. Ensure **Unity** and **Scrutiny** are installed and activated.
2. Upload the `integrity` folder to `/wp-content/plugins/`, or install via the WordPress plugin uploader.
3. Activate **Integrity** in the Plugins screen.
4. On activation three database tables are created (`wp_integrity_api_keys`, `wp_integrity_rate_limits`, `wp_integrity_audit_log`) and a daily cleanup cron is scheduled.
5. Navigate to **Integrity API** in the admin sidebar to create your first API key.

== Frequently Asked Questions ==

= Where can I get support? =

Contact The Bleeding Deacons at thebleedingdeacons@gmail.com.

== Screenshots ==

1. Plugin admin settings page.

== Changelog ==

= 1.9.7 =
* Current stable release.

== Upgrade Notice ==

= 1.9.7 =
Latest stable release of Integrity.

== Requirements ==

| Component | Version |
|-----------|---------|
| WordPress | 6.0+ |
| PHP | 8.1+ |
| Unity plugin | Installed and activated |
| Scrutiny plugin | Installed and activated |
| HTTPS | Strongly recommended (enforced by default) |

== API Endpoints ==

All endpoints are served under `/wp-json/integrity/v1/`. Every authenticated response includes `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers.

= Health =

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/health` | None | Returns API status, version, and Unity availability |

= Groups =

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/groups` | `groups:read` | List groups — `per_page`, `page`, `search`, `district_id`, `expand=meetings` |
| `GET` | `/groups/{id}` | `groups:read` | Single group — optional `expand=meetings` |

= Meetings =

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/meetings` | `meetings:read` | List meetings — `per_page`, `page`, `search`, `day` (0–6), `online` (true/false), `group_id` |
| `GET` | `/meetings/{id}` | `meetings:read` | Single meeting |

= Positions =

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/positions` | `positions:read` | List positions — `per_page`, `page`, `search` |
| `GET` | `/positions/{id}` | `positions:read` | Single position |

= Members =

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/members` | `members:read` | List members — `per_page`, `page`, `search`, `home_group_id` |
| `GET` | `/members/{id}` | `members:read` | Single member |
| `POST` | `/members/create` | `members:write` | Create a new member (`anonymous_name` required) |
| `POST` | `/members/{id}/update` | `members:write` | Partial update — only supplied fields are changed |

= Intergroup Meetings =

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/intergroup-meetings` | `intergroup-meetings:read` | List meetings — `per_page`, `page`, `date_from`, `date_to` (Y-m-d) |
| `GET` | `/intergroup-meetings/{id}` | `intergroup-meetings:read` | Single intergroup meeting with attendance |
| `POST` | `/intergroup-meetings/{id}/register-group` | `intergroup-meetings:write` | Register a group's GSR attendance |
| `POST` | `/intergroup-meetings/{id}/unregister-group` | `intergroup-meetings:write` | Remove a group's registration |
| `POST` | `/intergroup-meetings/{id}/register-officer` | `intergroup-meetings:write` | Register an officer's attendance |
| `POST` | `/intergroup-meetings/{id}/unregister-officer` | `intergroup-meetings:write` | Remove an officer's registration |

== Authentication ==

Pass your API key via the `Authorization` header (preferred) or the `X-API-Key` header:

```
Authorization: Bearer int_a1b2c3d4...
```

== Permissions ==

| Permission | Type | Grants Access To |
|------------|------|------------------|
| `groups:read` | Read | List and view groups |
| `meetings:read` | Read | List and view meetings |
| `positions:read` | Read | List and view positions |
| `members:read` | Read | List and view members |
| `members:write` | Write | Create and update member data |
| `intergroup-meetings:read` | Read | List and view intergroup meetings |
| `intergroup-meetings:write` | Write | Register/unregister group and officer attendance |
| `*` | All | Full access to every endpoint |

If no permissions are selected when creating a key, it defaults to `groups:read` and `meetings:read`.

== Security Headers ==

All API responses include:

* `X-Content-Type-Options: nosniff`
* `X-Frame-Options: DENY`
* `X-XSS-Protection: 1; mode=block`
* `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`

== Response Format ==

= Success =

```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "total": 42,
    "page": 1,
    "per_page": 100,
    "total_pages": 1
  }
}
```

= Error =

```json
{
  "success": false,
  "error": {
    "code": "invalid_api_key",
    "message": "Invalid or expired API key"
  }
}
```

== Error Codes ==

| Code | HTTP | Description |
|------|------|-------------|
| `missing_api_key` | 401 | No API key provided |
| `invalid_api_key` | 401 | Key is invalid, revoked, or expired |
| `insufficient_permissions` | 403 | Key lacks the required permission |
| `https_required` | 403 | HTTPS is required but request was HTTP |
| `rate_limit_exceeded` | 429 | Hourly rate limit exceeded |
| `not_found` | 404 | Resource not found |
| `not_registered` | 404 | Group/officer not registered for meeting |
| `invalid_home_group` | 422 | Referenced home group does not exist |
| `invalid_intergroup_position` | 422 | Referenced position does not exist |
| `already_registered` | 409 | Group/officer already registered for meeting |
| `create_failed` | 500 | Failed to create a new member post |
| `save_failed` | 500 | Failed to save data |
| `internal_error` | 500 | Unexpected server error |

== Client Libraries ==

= PHP Client =

The PHP client is a standalone single-file library with no external dependencies (requires PHP 8.1+ and cURL).

```php
require_once 'client/php/IntegrityClient.php';

$client = new IntegrityClient('https://example.com', 'int_a1b2c3d4...');

// List groups with expanded meetings
$response = $client->getGroups(new GroupsQuery(expandMeetings: true));

if ($response->success) {
    foreach ($response->data as $group) {
        echo "{$group->title} — " . count($group->meetingIds) . " meetings\n";
    }
    echo "Page {$response->meta->page} of {$response->meta->totalPages}\n";
}

// Create a member
$response = $client->createMember(new CreateMemberRequest(
    anonymousName: 'John D.',
    homeGroupId: 15,
    isGsr: true,
));

// Update a member (partial)
$client->updateMember(42, new UpdateMemberRequest(anonymousName: 'John D.'));

// Register group attendance
$client->registerGroup(7, new RegisterGroupRequest(
    groupId: 12,
    gsrName: 'John D.',
));

// Error / rate-limit handling
$response = $client->getGroups();
if (!$response->success) {
    echo "Error {$response->httpStatus}: {$response->errorCode}\n";
    if ($response->rateLimit) {
        echo "Retry after: {$response->rateLimit->resetDateTime()->format('c')}\n";
    }
}
```

= .NET Client =

The .NET client targets **.NET 9** with C# 13 and uses `System.Text.Json` with `snake_case` naming.

```csharp
using TheBleedingDeacons.Unity.Client;

using var client = new UnityRestSharp(
    "https://example.com",
    "int_a1b2c3d4e5f6..."
);

// List groups
var response = await client.GetGroupsAsync(expandMeetings: true);
if (response.Success && response.Data != null)
{
    foreach (var group in response.Data)
        Console.WriteLine($"{group.Title} — {group.Meetings.Count} meetings");
}

// Create a member
var created = await client.CreateMemberAsync(new CreateMemberRequest
{
    AnonymousName = "Jane S.",
    HomeGroupId = 8,
});

// Update a member
await client.UpdateMemberAsync(42, new UpdateMemberRequest
{
    AnonymousName = "John D.",
    IsGsr = true,
    HomeGroupId = 15
});

// Register group attendance
await client.RegisterAttendeeAsync(
    intergroupMeetingId: 7,
    memberId: 42,
    meetingGroup: "Tuesday Night Group",
    gsrName: "John D.",
    gsrProxy: false
);

// Error handling
var resp = await client.GetGroupsAsync();
if (!resp.Success)
{
    Console.WriteLine($"Error {resp.StatusCode}: {resp.Error?.Code}");
    if (resp.StatusCode == 429)
        Console.WriteLine($"Rate limited until {resp.RateLimit?.ResetDateTime}");
}
```

= Direct HTTP =

```bash
# Health check (no auth required)
curl https://example.com/wp-json/integrity/v1/health

# List groups
curl -H "Authorization: Bearer int_..." \
     https://example.com/wp-json/integrity/v1/groups?expand=meetings

# Monday online meetings
curl -H "Authorization: Bearer int_..." \
     "https://example.com/wp-json/integrity/v1/meetings?day=1&online=true"

# Create a member
curl -X POST -H "Authorization: Bearer int_..." \
     -H "Content-Type: application/json" \
     -d '{"anonymous_name":"Jane S.","home_group_id":8}' \
     https://example.com/wp-json/integrity/v1/members/create

# Register group attendance
curl -X POST -H "Authorization: Bearer int_..." \
     -H "Content-Type: application/json" \
     -d '{"group_id":12,"gsr_name":"John D."}' \
     https://example.com/wp-json/integrity/v1/intergroup-meetings/7/register-group
```

== Admin Settings ==

Navigate to **Integrity API → Settings** in wp-admin:

| Setting | Default | Description |
|---------|---------|-------------|
| Require HTTPS | On | Reject API requests over plain HTTP (bypassed with `WP_DEBUG`) |
| Default Rate Limit | 1,000/hr | Per-hour limit for new keys (overridable per key) |
| Enable Audit Log | On | Record every API request |
| Log Retention | 90 days | Auto-delete logs older than this via daily cron |

== Project Structure ==

```
integrity/
├── integrity.php              # Plugin bootstrap
├── src/
│   ├── Plugin.php             # Main plugin class (DI, hooks)
│   ├── Admin/SettingsPage.php # WP-admin UI
│   ├── Api/RestController.php # All REST route handlers
│   └── Auth/
│       ├── ApiKeyManager.php  # Key creation, validation, Argon2id
│       ├── AuditLogger.php    # Request logging
│       └── RateLimiter.php    # Sliding-window rate limiting
├── assets/
│   ├── admin.css              # Admin stylesheet
│   └── docs/integrity.html    # Full HTML documentation
├── client/
│   ├── php/
│   │   ├── IntegrityClient.php      # PHP client library
│   │   └── client-example.php       # PHP usage example
│   └── sharp/
│       ├── TheBleedingDeacons.Unity.Client/   # .NET client
│       ├── TheBleedingDeacons.Unity.Models/   # .NET model classes
│       └── TheBleedingDeacons.Unity.Tests/    # .NET unit tests
├── templates/                 # Admin page templates
├── tests/                     # PHP unit tests (PHPUnit + WP_Mock)
├── composer.json
├── phpunit.xml
└── phpstan.neon
```

== Development ==

```bash
# Install PHP dependencies
composer install

# Run tests
composer test

# Run static analysis
composer analyse

# Code style check
composer cs
```
