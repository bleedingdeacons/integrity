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
use Integrity\Tests\TestCase;
use Mockery;
use ReflectionMethod;

/**
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
 *     expectException.
 *   - The four handle* methods end in wp_redirect() followed by a bare exit.
 *     wp_redirect is recorded rather than thrown, so exit runs and would take
 *     PHPUnit with it — the live path genuinely cannot run in-process. Their
 *     guards are covered; the branchy logic behind them is reached through
 *     reflection on getAuditPageData(), the same approach Amber documents for
 *     its own redirect-and-exit handlers.
 *
 * @covers \Integrity\Admin\SettingsPage
 */
final class SettingsPageTest extends TestCase
{
    private $keys;
    private $audit;
    private SettingsPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys  = Mockery::mock(ApiKeyManager::class);
        $this->audit = Mockery::mock(AuditLogger::class);
        $this->page  = new SettingsPage($this->keys, $this->audit);

        $_REQUEST = [];
        $_POST    = [];

        $this->stubFormHelpers();
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
        $_POST    = [];

        parent::tearDown();
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function init_registers_every_admin_hook(): void
    {
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
    }

    /** @test */
    public function add_menu_page_registers_the_top_level_and_three_submenus(): void
    {
        $this->page->addMenuPage();

        $slugs = array_column(WpState::$menus, 'slug');

        $this->assertContains('integrity-settings', $slugs);
        $this->assertContains('integrity-settings-audit', $slugs);
        $this->assertContains('integrity-settings-config', $slugs);
        // Top level plus three submenus, one of which reuses the parent slug.
        $this->assertCount(4, WpState::$menus);
    }

    /** @test */
    public function register_settings_registers_the_whole_settings_group(): void
    {
        $registered = [];
        Functions\when('register_setting')->alias(
            static function (string $group, string $name, $args = []) use (&$registered): void {
                $registered[$name] = ['group' => $group, 'args' => $args];
            }
        );

        $this->page->registerSettings();

        $this->assertSame([
            'integrity_enable_audit_log',
            'integrity_audit_log_retention_days',
            'integrity_default_rate_limit',
            'integrity_require_https',
            'integrity_audit_auto_refresh_enabled',
            'integrity_audit_auto_refresh_interval',
        ], array_keys($registered));

        foreach ($registered as $name => $spec) {
            $this->assertSame('integrity_settings', $spec['group'], $name . ' is in the wrong group');
        }
    }

    /**
     * The auto-refresh interval is the one setting with real behaviour: a
     * sanitize callback clamping the value into 5..3600 seconds, so a hand-typed
     * 1 cannot hammer admin-ajax and a huge value cannot disable refresh by
     * accident.
     *
     * @test
     * @dataProvider refreshIntervals
     */
    public function refresh_interval_is_clamped_between_five_seconds_and_an_hour(
        mixed $input,
        int $expected
    ): void {
        $callback = null;
        Functions\when('register_setting')->alias(
            static function (string $group, string $name, $args = []) use (&$callback): void {
                if ($name === 'integrity_audit_auto_refresh_interval') {
                    $callback = $args['sanitize_callback'] ?? null;
                }
            }
        );

        $this->page->registerSettings();

        $this->assertIsCallable($callback, 'the interval setting should register a sanitize callback');
        $this->assertSame($expected, $callback($input));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function refreshIntervals(): array
    {
        return [
            'below the floor'    => [1, 5],
            'at the floor'       => [5, 5],
            'in range'           => [30, 30],
            'at the ceiling'     => [3600, 3600],
            'above the ceiling'  => [99999, 3600],
            'non-numeric string' => ['nonsense', 5],
            'numeric string'     => ['45', 45],
        ];
    }

    // ── asset enqueuing ───────────────────────────────────────────────

    /** @test */
    public function assets_are_not_enqueued_outside_the_plugin_screens(): void
    {
        $this->page->enqueueAssets('edit.php');

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_stylesheet_is_enqueued_on_a_plugin_screen(): void
    {
        $this->page->enqueueAssets('toplevel_page_integrity-settings');

        $handles = array_column(WpState::$enqueued, 'handle');
        $this->assertContains('integrity-admin', $handles);
        // The audit script belongs to the audit screen only.
        $this->assertNotContains('integrity-admin-audit', $handles);
    }

    /**
     * The audit script is gated on the hook suffix add_submenu_page() returned,
     * so it only loads once addMenuPage() has run and only on that screen.
     *
     * @test
     */
    public function the_audit_script_is_enqueued_and_localised_only_on_the_audit_screen(): void
    {
        $this->page->addMenuPage();

        $auditHook = $this->auditHookSuffix();
        $this->assertNotSame('', $auditHook, 'add_submenu_page should yield a hook suffix');

        $this->page->enqueueAssets($auditHook);

        $handles = array_column(WpState::$enqueued, 'handle');
        $this->assertContains('integrity-admin-audit', $handles);

        $this->assertArrayHasKey('integrityAuditRefresh', WpState::$localized);
        $payload = WpState::$localized['integrityAuditRefresh'];
        $this->assertSame('integrity_audit_refresh_logs', $payload['action']);
        $this->assertArrayHasKey('nonce', $payload);
    }

    // ── capability guards ─────────────────────────────────────────────

    /**
     * Every screen and every action re-checks the capability rather than
     * trusting the menu to have hidden itself.
     *
     * @test
     * @dataProvider guardedMethods
     */
    public function every_entry_point_refuses_a_user_without_the_capability(string $method): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->page->{$method}();
    }

    /** @return array<string, array{0: string}> */
    public static function guardedMethods(): array
    {
        return [
            'keys screen'     => ['renderPage'],
            'audit screen'    => ['renderAuditPage'],
            'settings screen' => ['renderSettingsPage'],
            'create key'      => ['handleCreateKey'],
            'revoke key'      => ['handleRevokeKey'],
            'delete key'      => ['handleDeleteKey'],
            'clear logs'      => ['handleClearLogs'],
        ];
    }

    // ── AJAX refresh ──────────────────────────────────────────────────

    /** @test */
    public function ajax_refresh_rejects_a_user_without_the_capability(): void
    {
        WpState::$userCan = false;

        try {
            $this->page->ajaxRefreshLogs();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
            $this->assertSame(403, $e->status);
        }
    }

    // ── rendered screens ──────────────────────────────────────────────

    /**
     * The three screens each guard, fetch, then include a template. Driving
     * them for real is what proves the template's expected variables are
     * actually in scope — a renamed field shows up here as an undefined
     * variable rather than as a blank panel in wp-admin.
     */
    private function stubFormHelpers(): void
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

    /** @test */
    public function the_keys_screen_renders_the_keys_it_is_given(): void
    {
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

        $this->assertStringContainsString('Reporting key', $html);
    }

    /**
     * A freshly created key is handed over once through a transient, then
     * deleted so a refresh cannot show the secret again.
     *
     * @test
     */
    public function a_newly_created_key_is_shown_once_and_the_transient_cleared(): void
    {
        WpState::$transients['integrity_new_key_1'] = 'itg_secret_value';
        $this->keys->shouldReceive('getAllKeys')->once()->andReturn([]);

        ob_start();
        try {
            $this->page->renderPage();
        } finally {
            ob_end_clean();
        }

        $this->assertArrayNotHasKey(
            'integrity_new_key_1',
            WpState::$transients,
            'the one-shot transient should be deleted after being read'
        );
    }

    /** @test */
    public function the_settings_screen_renders(): void
    {
        ob_start();
        try {
            $this->page->renderSettingsPage();
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertNotSame('', trim($html), 'the settings screen should render markup');
    }

    /** @test */
    public function the_audit_screen_renders_with_its_data_in_scope(): void
    {
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

        $this->assertNotSame('', trim($html), 'the audit screen should render markup');
    }

    /**
     * The AJAX refresh re-renders the same partial the page uses and returns it
     * as JSON, so the script can swap innerHTML without a reload.
     *
     * @test
     */
    public function ajax_refresh_returns_the_rendered_partial_as_json(): void
    {
        $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => 0]);
        $this->audit->shouldReceive('getStats')->once()->andReturn(['total_requests' => 0, 'successful_requests' => 0, 'failed_auth' => 0,
             'rate_limited' => 0, 'avg_response_time' => 0]);
        $this->keys->shouldReceive('getAllKeys')->once()->andReturn([]);

        try {
            $this->page->ajaxRefreshLogs();
            $this->fail('expected a JSON response to be sent');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertArrayHasKey('html', $e->data);
        }
    }

    // ── audit page data (reflection: the live callers exit) ───────────

    /** @test */
    public function audit_data_defaults_to_the_first_page_with_no_filters(): void
    {
        $this->audit->shouldReceive('getLogs')->once()
            ->with(Mockery::on(static fn (array $a): bool =>
                $a['page'] === 1 && $a['per_page'] === 50 && $a['api_key_id'] === null))
            ->andReturn(['logs' => [], 'total' => 0]);
        $this->audit->shouldReceive('getStats')->with(30)->andReturn([]);
        $this->keys->shouldReceive('getAllKeys')->andReturn([]);

        $data = $this->auditPageData();

        $this->assertSame(1, $data['page']);
        $this->assertSame(50, $data['per_page']);
        $this->assertSame(0, $data['total_pages']);
        foreach ($data['filters'] as $name => $value) {
            $this->assertNull($value, $name . ' should default to null');
        }
    }

    /**
     * Filters arrive from $_REQUEST — query string on a page load, serialized
     * form fields on an AJAX refresh — and empty strings must mean "unset"
     * rather than a filter on the empty value.
     *
     * @test
     */
    public function blank_filter_fields_are_treated_as_absent(): void
    {
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

        $data = $this->auditPageData();

        $this->assertNull($data['filters']['api_key_id']);
        $this->assertNull($data['filters']['ip_address']);
    }

    /** @test */
    public function populated_filters_are_typed_and_passed_through(): void
    {
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

        $data = $this->auditPageData();

        $this->assertSame(3, $data['page']);
        $this->assertSame(7, $data['filters']['api_key_id'], 'api_key_id should be an int');
        $this->assertSame(404, $data['filters']['response_code'], 'response_code should be an int');
        $this->assertSame('10.0.0.1', $data['filters']['ip_address']);
        $this->assertSame('2026-01-31', $data['filters']['date_to']);
    }

    /** @test */
    public function a_negative_or_zero_page_is_clamped_to_the_first_page(): void
    {
        $_REQUEST = ['paged' => '-5'];

        $this->audit->shouldReceive('getLogs')->once()
            ->with(Mockery::on(static fn (array $a): bool => $a['page'] === 1))
            ->andReturn(['logs' => [], 'total' => 0]);
        $this->audit->shouldReceive('getStats')->andReturn([]);
        $this->keys->shouldReceive('getAllKeys')->andReturn([]);

        $this->assertSame(1, $this->auditPageData()['page']);
    }

    /**
     * 50 rows per page, so 101 rows is three pages — the boundary that decides
     * whether the last partial page gets a link.
     *
     * @test
     * @dataProvider totals
     */
    public function total_pages_rounds_up(int $total, int $expected): void
    {
        $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => $total]);
        $this->audit->shouldReceive('getStats')->andReturn([]);
        $this->keys->shouldReceive('getAllKeys')->andReturn([]);

        $this->assertSame($expected, $this->auditPageData()['total_pages']);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function totals(): array
    {
        return [
            'empty'             => [0, 0],
            'one row'           => [1, 1],
            'exactly one page'  => [50, 1],
            'one over'          => [51, 2],
            'two full pages'    => [100, 2],
            'two pages and one' => [101, 3],
        ];
    }

    /**
     * Pagination links inside the partial are built from this, and it has to
     * point at the audit screen rather than admin-ajax.php — otherwise links
     * rendered during an AJAX refresh would navigate to the AJAX endpoint.
     *
     * @test
     */
    public function the_pagination_base_url_points_at_the_audit_screen(): void
    {
        $this->audit->shouldReceive('getLogs')->once()->andReturn(['logs' => [], 'total' => 0]);
        $this->audit->shouldReceive('getStats')->andReturn([]);
        $this->keys->shouldReceive('getAllKeys')->andReturn([]);

        $baseUrl = $this->auditPageData()['base_url'];

        $this->assertStringContainsString('page=integrity-settings-audit', $baseUrl);
        $this->assertStringNotContainsString('admin-ajax.php', $baseUrl);
    }

    // ── create-key form parsing (reflection: handleCreateKey exits) ───

    /**
     * @param array<string, mixed> $post
     * @return array<int, string>
     */
    private function permissionsFor(array $post): array
    {
        $_POST = $post;
        $m = new ReflectionMethod(SettingsPage::class, 'parsePermissions');

        /** @var array<int, string> $out */
        $out = $m->invoke($this->page);

        return $out;
    }

    /**
     * @test
     * @dataProvider permissionForms
     * @param array<string, mixed> $post
     * @param array<int, string>   $expected
     */
    public function the_permission_checkboxes_map_onto_scopes(array $post, array $expected): void
    {
        $this->assertSame($expected, $this->permissionsFor($post));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<int, string>}> */
    public static function permissionForms(): array
    {
        return [
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
        ];
    }

    /**
     * @test
     * @dataProvider whitelists
     * @param array<int, string>|null $expected
     */
    public function the_ip_whitelist_textarea_is_parsed_into_a_list(mixed $raw, ?array $expected): void
    {
        $_POST = $raw === null ? [] : ['ip_whitelist' => $raw];
        $m = new ReflectionMethod(SettingsPage::class, 'parseIpWhitelist');

        $this->assertSame($expected, $m->invoke($this->page));
    }

    /** @return array<string, array{0: mixed, 1: array<int, string>|null}> */
    public static function whitelists(): array
    {
        return [
            'absent'                 => [null, null],
            'empty'                  => ['', null],
            'whitespace only'        => ["  
  
", null],
            'one address'            => ['10.0.0.1', ['10.0.0.1']],
            'several'                => ["10.0.0.1
10.0.0.2", ['10.0.0.1', '10.0.0.2']],
            'padded and blank lines' => ["  10.0.0.1  

 10.0.0.2 
", ['10.0.0.1', '10.0.0.2']],
        ];
    }

    // ── nonce field ───────────────────────────────────────────────────

    /** @test */
    public function the_nonce_field_is_built_for_the_shared_admin_action(): void
    {
        Functions\when('wp_nonce_field')->alias(
            static fn (string $action, string $name, bool $referer, bool $echo): string
                => "field:{$action}:{$name}:" . ($echo ? 'echo' : 'return')
        );

        $this->assertSame(
            'field:integrity_admin_action:_wpnonce:return',
            SettingsPage::getNonceField()
        );
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function auditPageData(): array
    {
        $m = new ReflectionMethod(SettingsPage::class, 'getAuditPageData');

        /** @var array<string, mixed> $data */
        $data = $m->invoke($this->page);

        return $data;
    }

    private function auditHookSuffix(): string
    {
        $p = new \ReflectionProperty(SettingsPage::class, 'auditHookSuffix');

        return (string) $p->getValue($this->page);
    }
}
