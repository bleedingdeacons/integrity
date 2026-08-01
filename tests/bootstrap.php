<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Integrity Plugin Tests
 *
 * WordPress stand-ins, the Brain Monkey lifecycle and WP_Error all come from
 * bleedingdeacons/wp-mocks, shared across the plugin suite. The package's
 * bootstrap loads Patchwork before anything patchable — Brain Monkey only
 * requires it inside Monkey\setUp(), by which time the stubs are defined, so
 * leaving it to Brain Monkey means any attempt to override a stub dies with
 * Patchwork\Exceptions\DefinedTooEarly.
 *
 * Anything defining WordPress functions of its own must therefore come after
 * the require below, not before it.
 */

use BleedingDeacons\WpMocks\WpState;

// Load Composer autoloader
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Composer autoloader not found. Run 'composer install' first.\n");
}
require_once $autoloader;
require_once dirname(__DIR__) . '/vendor/bleedingdeacons/wp-mocks/bootstrap.php';

// Makes plugins_url()/plugin_dir_url() answer with Integrity's own path.
WpState::$pluginSlug = 'integrity';

// --- Unity ---------------------------------------------------------------
// The API controllers type-hint Unity's Groups, Meetings, Members, Positions
// and IntergroupMeetings contracts throughout. Load them from the sibling
// checkout that CI already arranges (see the "Checkout Unity" step in
// ci.yml), so the controllers are checked against the real interfaces.
//
// Until now nothing put Unity on this suite's classpath at all: the tests
// mock its contracts by string name, and Mockery will happily generate a
// double for a class that does not exist -- with no methods, and no signature
// checking whatsoever. Unity could change any signature here and these tests
// would stay green.
$unitySrc = dirname(__DIR__, 2) . '/unity/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . "Integrity is built on Unity's interfaces, so the Unity plugin must be" . PHP_EOL
        . 'checked out as a sibling directory for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity' . chr(92))) {
        return;
    }

    $file = $unitySrc . '/' . str_replace(chr(92), '/', substr($class, strlen('Unity' . chr(92)))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Define WordPress constants that might be needed
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('INTEGRITY_PLUGIN_DIR')) {
    define('INTEGRITY_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('INTEGRITY_PLUGIN_URL')) {
    define('INTEGRITY_PLUGIN_URL', 'http://example.com/wp-content/plugins/integrity/');
}

if (!defined('INTEGRITY_VERSION')) {
    define('INTEGRITY_VERSION', '1.0.0');
}

// WP_Error is not defined here any more — wp-mocks carries an equivalent, and
// this plugin only ever reads get_error_code()/message()/data() off one.
//
// WP_REST_Response is not part of the shared stubs, so it stays local.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        protected $data;
        protected $status;
        protected $headers = [];

        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
        }

        public function header($name, $value) {
            $this->headers[$name] = $value;
        }

        public function get_headers() {
            return $this->headers;
        }
    }
}

// Global wpdb mock placeholder
global $wpdb;
$wpdb = null;
