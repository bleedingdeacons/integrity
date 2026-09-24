<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Integrity\Admin\SettingsPage;
use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\AuditLogger;
use Mockery;
use ReflectionMethod;

/*
 * Tests for the admin settings page.
 *
 * This class was excluded from the coverage source set until now, on the
 * grounds that admin screens are "render/menu/enqueue glue exercised through
 * the admin UI at runtime". Amber covers its whole src/Admin on the same
 * tooling, so the exclusion was habit rather than necessity — but the reason it
 * was plausible is real, and shapes what is asserted here.
 *
 * Three kinds of method, three techniques:
 *
 *   - Registration (init, addMenuPage, registerSettings, enqueueAssets) is
 *     driven for real and asserted against WpState, which records hooks, menu
 *     pages and enqueued handles.
 *   - Capability guards call wp_die(), which the shared stubs turn into a
 *     WpDieException, so every "you do not have permission" branch is a plain
 *     expected exception.
 *   - The four handle* methods end in wp_redirect() followed by a bare exit.
 *     wp_redirect is recorded rather than thrown, so exit runs and would take
 *     PHPUnit with it — the live path genuinely cannot run in-process. Their
 *     guards are covered; the branchy logic behind them is reached through
 *     reflection on getAuditPageData(), the same approach Amber documents for
 *     its own redirect-and-exit handlers.
 */

covers(SettingsPage::class);

/**
 * The three screens each guard, fetch, then include a template. Driving
 * them for real is what proves the template's expected variables are
 * actually in scope — a renamed field shows up here as an undefined
 * variable rather than as a blank panel in wp-admin.
 */
function settingsPageStubFormHelpers(): void
{
    foreach (['settings_fields', 'do_settings_sections'] as $fn) {
        Functions\when($fn)->justReturn(null);
    }
    Functions\when('submit_button')->alias(static function (): void {
        echo '<button type="submit">Save</button>';
    });
    // admin-keys.php resolves the creating user for the "created by" column.
    Functions\when('get_user_by')->justReturn(false);
}

/** @return array<string, mixed> */
function settingsPageAuditData(SettingsPage $page): array
{
    $m = new ReflectionMethod(SettingsPage::class, 'getAuditPageData');

    /** @var array<string, mixed> $data */
    $data = $m->invoke($page);

    return $data;
}

function settingsPageAuditHookSuffix(SettingsPage $page): string
{
    $p = new \ReflectionProperty(SettingsPage::class, 'auditHookSuffix');

    return (string) $p->getValue($page);
}

/**
 * @param array<string, mixed> $post
 * @return array<int, string>
 */
function settingsPagePermissionsFor(SettingsPage $page, array $post): array
{
    $_POST = $post;
    $m = new ReflectionMethod(SettingsPage::class, 'parsePermissions');

    /** @var array<int, string> $out */
    $out = $m->invoke($page);

    return $out;
}

beforeEach(function () {
    $this->keys  = Mockery::mock(ApiKeyManager::class);
    $this->audit = Mockery::mock(AuditLogger::class);
    $this->page  = new SettingsPage($this->keys, $this->audit);

    $_REQUEST = [];
    $_POST    = [];

    settingsPageStubFormHelpers();
});

afterEach(function () {
    $_REQUEST = [];
    $_POST    = [];
});

// ── registration ──────────────────────────────────────────────────
it('registers every admin hook on init', function () {
    $this->page->init();

    foreach (
        [
        'admin_menu',
        'admin_init',
        'admin_post_integrity_create_key',
        'admin_post_integrity_revoke_key',
        'admin_post_integrity_delete_key',
        'admin_post_integrity_clear_logs',
        'admin_enqueue_scripts',
        'wp_ajax_integrity_audit_refresh_logs',
        ] as $hook
    ) {
        $this->assertActionAdded($hook, false, 'expected ' . $hook . ' to be hooked');
    }
});

it('registers the top-level menu page and three submenus', function () {
    $this->page->addMenuPage();

    $slugs = array_column(WpState::$menus, 'slug');

    expect($slugs)
        ->toContain('integrity-settings')
        ->toContain('integrity-settings-audit')
        ->toContain('integrity-settings-config')
        // Top level plus three submenus, one of which reuses the parent slug.
        ->and(WpState::$menus)->toHaveCount(4);
});

it('registers the whole settings group', function () {
    $registered = [];
    Functions\when('register_setting')->alias(
        static function (string $group, string $name, $args = []) use (&$registered): void {
            $registered[$name] = ['group' => $group, 'args' => $args];
        }
    );

    $this->page->registerSettings();

    expect(array_keys($registered))->toBe([
        'integrity_enable_audit_log',
        'integrity_audit_log_retention_days',
        'integrity_default_rate_limit',
        'integrity_require_https',
        'integrity_audit_auto_refresh_enabled',
        'integrity_audit_auto_refresh_interval',
    ]);

    foreach ($registered as $name => $spec) {
        expect($spec['group'])->toBe('integrity_settings', $name . ' is in the wrong group');
    }
});

/**
 * The auto-refresh interval is the one setting with real behaviour: a
 * sanitize callback clamping the value into 5..3600 seconds, so a hand-typed
 * 1 cannot hammer admin-ajax and a huge value cannot disable refresh by
 * accident.
 */
it('clamps the refresh interval between five seconds and an hour', function (mixed $input, int $expected) {
    $callback = null;
    Functions\when('register_setting')->alias(
        static function (string $group, string $name, $args = []) use (&$callback): void {
            if ($name === 'integrity_audit_auto_refresh_interval') {
                $callback = $args['sanitize_callback'] ?? null;
            }
        }
    );

    $this->page->registerSettings();

    expect($callback)->toBeCallable('the interval setting should register a sanitize callback')
        ->and($callback($input))->toBe($expected);
})->with([
    'below the floor'    => [1, 5],
    'at the floor'       => [5, 5],
    'in range'           => [30, 30],
    'at the ceiling'     => [3600, 3600],
    'above the ceiling'  => [99999, 3600],
    'non-numeric string' => ['nonsense', 5],
    'numeric string'     => ['45', 45],
]);

// ── asset enqueuing ───────────────────────────────────────────────
it('does not enqueue assets outside the plugin screens', function () {
    $this->page->enqueueAssets('edit.php');

    expect(WpState::$enqueued)->toBe([]);
});

it('enqueues the stylesheet on a plugin screen', function () {
    $this->page->enqueueAssets('toplevel_page_integrity-settings');

    $handles = array_column(WpState::$enqueued, 'handle');
    expect($handles)
        ->toContain('integrity-admin')
        // The audit script belongs to the audit screen only.
        ->not->toContain('integrity-admin-audit');
});

/**
 * The audit script is gated on the hook suffix add_submenu_page() returned,
 * so it only loads once addMenuPage() has run and only on that screen.
 */
it('enqueues and localises the audit script only on the audit screen', function () {
    $this->page->addMenuPage();

    $auditHook = settingsPageAuditHookSuffix($this->page);
    expect($auditHook)->not->toBe('', 'add_submenu_page should yield a hook suffix');

    $this->page->enqueueAssets($auditHook);

    $handles = array_column(WpState::$enqueued, 'handle');
    expect($handles)->toContain('integrity-admin-audit')
        ->and(WpState::$localized)->toHaveKey('integrityAuditRefresh');

    $payload = WpState::$localized['integrityAuditRefresh'];
    expect($payload['action'])->toBe('integrity_audit_refresh_logs')
        ->and($payload)->toHaveKey('nonce');
});

// ── capability guards ─────────────────────────────────────────────
/**
 * Every screen and every action re-checks the capability rather than
 * trusting the menu to have hidden itself.
 */
it('refuses a user without the capability at every entry point', function (string $method) {
    WpState::$userCan = false;

    $this->page->{$method}();
})->with([
    'keys screen'     => ['renderPage'],
    'audit screen'    => ['renderAuditPage'],
    'settings screen' => ['renderSettingsPage'],
    'create key'      => ['handleCreateKey'],
    'revoke key'      => ['handleRevokeKey'],
    'delete key'      => ['handleDeleteKey'],
    'clear logs'      => ['handleClearLogs'],
])->throws(WpDieException::class);

// ── AJAX refresh ──────────────────────────────────────────────────
it('rejects an AJAX refresh from a user without the capability', function () {
    WpState::$userCan = false;

    try {
        $this->page->ajaxRefreshLogs();
        $this->fail('expected a JSON response to be sent');
    } catch (JsonResponseException $e) {
        expect($e->success)->toBeFalse()
            ->and($e->status)->toBe(403);
    }
});

// ── rendered screens ──────────────────────────────────────────────
it('renders the keys it is given on the keys screen', function () {
    $this->keys->shouldReceive('getAllKeys')->once()->andReturn([
        [
            'id'             => 1,
            'name'           => 'Reporting key',
            'api_key_prefix' => 'itg_abc',
            'permissions'    => ['groups:read'],
            'is_active'      => 1,
            'rate_limit'     => 60,
            'created_at'     => '2026-01-01 00:00:00',
            'created_by'     => 1,
            'last_used'      => null,
            'expires_at'     => null,
            'ip_whitelist'   => null,
            'request_count'  => 0,
        ],
    ]);

    ob_start();
    try {
        $this->page->renderPage();
    } finally {
        $html = (string) ob_get_clean();
    }

    expect($html)->toContain('Reporting key');
});

/**
 * A freshly created key is handed over once through a transient, then
 * deleted so a refresh cannot show the secret again.
 */
it('shows a newly created key once and clears the transient', function () {
    WpState::$transients['integrity_new_key_1'] = 'itg_secret_value';
    $this->keys->shouldReceive('getAllKeys')->once()->andReturn([]);

    ob_start();
    try {
        $this->page->renderPage();
    } finally {
        ob_end_clean();
    }

    expect(WpState::$transients)->not->toHaveKey(
        'integrity_new_key_1',
        message: 'the one-shot transient should be deleted after being read'
    );
});

it('renders the settings screen', function () {
    ob_start();
    try {
        $this->page->renderSettingsPage();
    } finally {
        $html = (string) ob_get_clean();
    }

    expect(trim($html))->not->toBe('', 'the settings screen should render markup');
});

it('renders the audit screen with its data in scope', function () {
    $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->once()->andReturn(['total_requests' => 0, 'successful_requests' => 0, 'failed_auth' => 0,
         'rate_limited' => 0, 'avg_response_time' => 0]);
    $this->keys->shouldReceive('getAllKeys')->once()->andReturn([]);

    ob_start();
    try {
        $this->page->renderAuditPage();
    } finally {
        $html = (string) ob_get_clean();
    }

    expect(trim($html))->not->toBe('', 'the audit screen should render markup');
});

/**
 * The AJAX refresh re-renders the same partial the page uses and returns it
 * as JSON, so the script can swap innerHTML without a reload.
 */
it('returns the rendered partial as JSON from the AJAX refresh', function () {
    $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->once()->andReturn(['total_requests' => 0, 'successful_requests' => 0, 'failed_auth' => 0,
         'rate_limited' => 0, 'avg_response_time' => 0]);
    $this->keys->shouldReceive('getAllKeys')->once()->andReturn([]);

    try {
        $this->page->ajaxRefreshLogs();
        $this->fail('expected a JSON response to be sent');
    } catch (JsonResponseException $e) {
        expect($e->success)->toBeTrue()
            ->and($e->data)->toHaveKey('html');
    }
});

// ── audit page data (reflection: the live callers exit) ───────────
it('defaults the audit data to the first page with no filters', function () {
    $this->audit->shouldReceive('getLogs')->once()
        ->with(Mockery::on(static fn (array $a): bool =>
            $a['page'] === 1 && $a['per_page'] === 50 && $a['api_key_id'] === null))
        ->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->with(30)->andReturn([]);
    $this->keys->shouldReceive('getAllKeys')->andReturn([]);

    $data = settingsPageAuditData($this->page);

    expect($data['page'])->toBe(1)
        ->and($data['per_page'])->toBe(50)
        ->and($data['total_pages'])->toBe(0);
    foreach ($data['filters'] as $name => $value) {
        expect($value)->toBeNull($name . ' should default to null');
    }
});

/**
 * Filters arrive from $_REQUEST — query string on a page load, serialized
 * form fields on an AJAX refresh — and empty strings must mean "unset"
 * rather than a filter on the empty value.
 */
it('treats blank filter fields as absent', function () {
    $_REQUEST = [
        'api_key_id'    => '',
        'response_code' => '',
        'ip_address'    => '',
        'date_from'     => '',
        'date_to'       => '',
    ];

    $this->audit->shouldReceive('getLogs')->once()
        ->with(Mockery::on(static fn (array $a): bool =>
            $a['api_key_id'] === null && $a['ip_address'] === null && $a['date_to'] === null))
        ->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->andReturn([]);
    $this->keys->shouldReceive('getAllKeys')->andReturn([]);

    $data = settingsPageAuditData($this->page);

    expect($data['filters']['api_key_id'])->toBeNull()
        ->and($data['filters']['ip_address'])->toBeNull();
});

it('types populated filters and passes them through', function () {
    $_REQUEST = [
        'paged'         => '3',
        'api_key_id'    => '7',
        'response_code' => '404',
        'ip_address'    => '10.0.0.1',
        'date_from'     => '2026-01-01',
        'date_to'       => '2026-01-31',
    ];

    $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->andReturn([]);
    $this->keys->shouldReceive('getAllKeys')->andReturn([]);

    $data = settingsPageAuditData($this->page);

    expect($data['page'])->toBe(3)
        ->and($data['filters']['api_key_id'])->toBe(7, 'api_key_id should be an int')
        ->and($data['filters']['response_code'])->toBe(404, 'response_code should be an int')
        ->and($data['filters']['ip_address'])->toBe('10.0.0.1')
        ->and($data['filters']['date_to'])->toBe('2026-01-31');
});

it('clamps a negative or zero page to the first page', function () {
    $_REQUEST = ['paged' => '-5'];

    $this->audit->shouldReceive('getLogs')->once()
        ->with(Mockery::on(static fn (array $a): bool => $a['page'] === 1))
        ->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->andReturn([]);
    $this->keys->shouldReceive('getAllKeys')->andReturn([]);

    expect(settingsPageAuditData($this->page)['page'])->toBe(1);
});

/**
 * 50 rows per page, so 101 rows is three pages — the boundary that decides
 * whether the last partial page gets a link.
 */
it('rounds the total pages up', function (int $total, int $expected) {
    $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => $total]);
    $this->audit->shouldReceive('getStats')->andReturn([]);
    $this->keys->shouldReceive('getAllKeys')->andReturn([]);

    expect(settingsPageAuditData($this->page)['total_pages'])->toBe($expected);
})->with([
    'empty'             => [0, 0],
    'one row'           => [1, 1],
    'exactly one page'  => [50, 1],
    'one over'          => [51, 2],
    'two full pages'    => [100, 2],
    'two pages and one' => [101, 3],
]);

/**
 * Pagination links inside the partial are built from this, and it has to
 * point at the audit screen rather than admin-ajax.php — otherwise links
 * rendered during an AJAX refresh would navigate to the AJAX endpoint.
 */
it('points the pagination base URL at the audit screen', function () {
    $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => 0]);
    $this->audit->shouldReceive('getStats')->andReturn([]);
    $this->keys->shouldReceive('getAllKeys')->andReturn([]);

    $baseUrl = settingsPageAuditData($this->page)['base_url'];

    expect($baseUrl)
        ->toContain('page=integrity-settings-audit')
        ->not->toContain('admin-ajax.php');
});

// ── create-key form parsing (reflection: handleCreateKey exits) ───
it('maps the permission checkboxes onto scopes', function (array $post, array $expected) {
    expect(settingsPagePermissionsFor($this->page, $post))->toBe($expected);
})->with([
    'nothing ticked falls back to read-only' => [
        [],
        ['groups:read', 'meetings:read'],
    ],
    'a single scope' => [
        ['perm_groups' => '1'],
        ['groups:read'],
    ],
    'unticked boxes are absent, not false' => [
        ['perm_groups' => '1', 'perm_meetings' => '0', 'perm_positions' => ''],
        ['groups:read'],
    ],
    'members:clear implies members:read' => [
        ['perm_members_clear' => '1'],
        ['members:clear', 'members:read'],
    ],
    'members:clear does not duplicate an explicit members:read' => [
        ['perm_members' => '1', 'perm_members_clear' => '1'],
        ['members:read', 'members:clear'],
    ],
    'write does not imply read' => [
        ['perm_members_write' => '1'],
        ['members:write'],
    ],
    'perm_all overrides everything else' => [
        ['perm_groups' => '1', 'perm_members_write' => '1', 'perm_all' => '1'],
        ['*'],
    ],
    'perm_all alone' => [
        ['perm_all' => '1'],
        ['*'],
    ],
    'the full set' => [
        [
            'perm_groups' => '1', 'perm_meetings' => '1', 'perm_positions' => '1',
            'perm_members' => '1', 'perm_members_write' => '1', 'perm_members_clear' => '1',
            'perm_intergroup_meetings' => '1', 'perm_intergroup_meetings_write' => '1',
        ],
        [
            'groups:read', 'meetings:read', 'positions:read', 'members:read',
            'members:write', 'members:clear', 'intergroup-meetings:read',
            'intergroup-meetings:write',
        ],
    ],
]);

/**
 * @param array<int, string>|null $expected
 */
it('parses the IP whitelist textarea into a list', function (mixed $raw, ?array $expected) {
    $_POST = $raw === null ? [] : ['ip_whitelist' => $raw];
    $m = new ReflectionMethod(SettingsPage::class, 'parseIpWhitelist');

    expect($m->invoke($this->page))->toBe($expected);
})->with([
    'absent'                 => [null, null],
    'empty'                  => ['', null],
    'whitespace only'        => ["  \n  \n", null],
    'one address'            => ['10.0.0.1', ['10.0.0.1']],
    'several'                => ["10.0.0.1\n10.0.0.2", ['10.0.0.1', '10.0.0.2']],
    'padded and blank lines' => ["  10.0.0.1  \n\n 10.0.0.2 \n", ['10.0.0.1', '10.0.0.2']],
]);

// ── nonce field ───────────────────────────────────────────────────
it('builds the nonce field for the shared admin action', function () {
    Functions\when('wp_nonce_field')->alias(
        static fn (string $action, string $name, bool $referer, bool $echo): string
            => "field:{$action}:{$name}:" . ($echo ? 'echo' : 'return')
    );

    expect(SettingsPage::getNonceField())->toBe('field:integrity_admin_action:_wpnonce:return');
});
