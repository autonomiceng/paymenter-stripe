<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Illuminate\Console {
    class Command {
        const SUCCESS = 0, FAILURE = 1;
        public array $options = [], $output = [];
        public function option($key) { return $this->options[$key] ?? null; }
        public function line($message) { $this->output[] = $message; }
        public function info($message) { $this->line($message); }
        public function error($message) { $this->line($message); }
    }
    class Application { public static $callbacks = []; public static function starting($callback) { self::$callbacks[] = $callback; } }
}
namespace App\Attributes { #[\Attribute] class ExtensionMeta { public function __construct(...$args) {} } }
namespace App\Classes\Extension {
    class Gateway {
        public function __construct(public array $config = []) {}
        public function config($key) { return $this->config[$key] ?? null; }
    }
}
namespace App\Models {
    class Query {
        public function __construct(private $value) {}
        public function first() { return $this->value; }
        public function exists() { return (bool) $this->value; }
        public function firstOrFail() { return $this->value ?? throw new \RuntimeException('Missing row'); }
        public function lockForUpdate() { \Illuminate\Support\Facades\DB::$locked = true; return $this; }
    }
    class Properties {
        public function __construct(public string $customer) {}
        public function where($key, $value) { return new Query((object) ['value' => $this->customer]); }
    }
    class User {
        public static $row;
        public $id = 7;
        public $name = 'Test';
        public $email = 'user@example.test';
        public function __construct(public Properties $props) {}
        public function properties() { return $this->props; }
        public static function where($key, $id) { return new Query(self::$row); }
        public static function findOrFail($id) { return self::$row; }
    }
    class Invoice {
        public static $row;
        public $id = 12;
        public $number = 'INV-12';
        public $user_id = 7;
        public $currency_code = 'usd';
        public function __construct(public User $user) {}
        public static function where($key, $id) { return new Query(self::$row); }
        public function transactions() { return new class {
            private $id;
            public function where($key, $value) { if ($key === 'transaction_id') $this->id = $value; return $this; }
            public function exists() { return (\App\Helpers\ExtensionHelper::$payments['12:' . $this->id]['status'] ?? null) === 'succeeded'; }
        }; }
        public static function findOrFail($id) {
            if ($id != self::$row->id) throw new \RuntimeException('Missing invoice');
            return self::$row;
        }
    }
    class Gateway {
        public static $rows = null;
        public static $enabled = true;
        public static $bundled = true;
        public static function where($key, $value) { if (self::$rows !== null) return new \AdoptionCollection(self::$rows[$value] ?? []); return new Query($value === 'Stripe' ? (self::$bundled ? (object) ['enabled' => false] : null) : (object) ['enabled' => self::$enabled]); }
    }
    class BillingAgreement {}
    class Extension {}
    class InvoiceTransaction {}
}
namespace App\Helpers {
    class ExtensionHelper {
        public static $agreements = [];
        public static $payments = [];
        public static function makeBillingAgreement($user, $gateway, $name, $id, $type, $expiry) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Agreement lock missing');
            self::$agreements[$id] = compact('name', 'gateway', 'type', 'expiry');
        }
        // Native helper updateOrCreate is keyed by invoice + transaction_id under an invoice lock.
        public static function addPayment($invoice, $gateway, $amount, $fee, $id, $status = 'succeeded') {
            self::$payments[$invoice->id . ':' . $id] = compact('gateway', 'amount', 'status');
        }
        public static function addProcessingPayment(...$args) { self::addPayment(...[...$args, 'processing']); }
        public static function addFailedPayment(...$args) { self::addPayment(...[...$args, 'failed']); }
    }
}
namespace Illuminate\Http {
    class Request {
        public function __construct(public array $input = [], public string $body = '', public ?string $signature = null) {}
        public function input($key) { return $this->input[$key] ?? null; }
        public function boolean($key) { return (bool) $this->input($key); }
        public function validate($rules) {} // Laravel validation is covered by sandbox checks.
        public function getContent() { return $this->body; }
        public function header($name) { return $this->signature; }
    }
}
namespace Illuminate\Support\Facades {
    class Route {
        public static $routes = [];
        public static function post($path, $action) { self::$routes[] = $path; return new self; }
        public static function get($path, $action) { self::$routes[] = $path; return new self; }
        public function withoutMiddleware($value) { return $this; }
        public function middleware($value) { return $this; }
        public function name($value) { return $this; }
    }
    class View { public static function addNamespace($name, $path) {} }
    class Event { public static $listeners = []; public static function listen($type, $callback) { self::$listeners[] = $callback; } }
    class Auth { public static $id = 7; public static function id() { return self::$id; } }
    class DB {
        public static $tables = [], $locks = [], $updates = [];
        public static function table($name) { return new \AdoptionQuery($name); }
        public static $locked = false;
        public static function transaction($callback) {
            $before = self::$tables;
            self::$locked = true;
            try { return $callback(); } catch (\Throwable $e) { self::$tables = $before; throw $e; }
            finally { self::$locked = false; }
        }
    }
    class Log { public static $lines = []; public static function info($message) { self::$lines[] = $message; } }
    class Http {
        public static $calls = [];
        public static $handler;
        public static function withHeaders($headers) { return new self; }
        public function asForm() { return $this; }
        public function timeout($seconds) { return $this; }
        public function __call($verb, $args) {
            [$url, $data] = $args;
            $path = substr($url, strlen('https://api.stripe.com/v1'));
            self::$calls[] = [$verb, $path, $data];
            $value = (self::$handler)($verb, $path, $data);
            return new class($value) {
                public function __construct(private $value) {}
                public function throw() { return $this; }
                public function json() { return (array) $this->value; }
                public function object() { return json_decode(json_encode($this->value)); }
            };
        }
    }
}
namespace Carbon {
    class Carbon extends \DateTime {
        public static function createFromDate($y, $m, $d) { return new self(sprintf('%04d-%02d-%02d', $y, $m, $d)); }
        public function endOfMonth() { $this->modify('last day of this month'); return $this; }
    }
}
namespace {
    function storage_path($path) { return $GLOBALS['adoptionStorage'] . '/' . $path; }
    function app() { return new class { public function runningInConsole() { return true; } }; }
    function abort($status, $message = '') { throw new \RuntimeException('HTTP ' . $status); }
    function abort_unless($condition, $status) { if (!$condition) throw new \RuntimeException('HTTP ' . $status); }
    function __($key, $values = []) { return $key; }
    function view($name, $data) { return compact('name', 'data'); }
    function redirect() { return new class { public function route($name) { return $this; } public function with($key, $value) { return $value; } }; }
    function response() { return new class { public function json($data, $status = 200) { return compact('data', 'status'); } }; }
}
