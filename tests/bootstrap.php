<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Integrity Plugin Tests
 */

// Load Composer autoloader
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Composer autoloader not found. Run 'composer install' first.\n");
}
require_once $autoloader;

// Load WP_Mock
if (!class_exists('WP_Mock')) {
    die("WP_Mock is required for testing. Run 'composer install' first.\n");
}
WP_Mock::bootstrap();

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

// Mock WordPress classes that don't exist in test environment
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];

        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code() {
            return array_key_first($this->errors) ?? '';
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }
    }
}

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
