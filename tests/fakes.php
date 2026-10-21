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
namespace App\Events\Service { class Updated { public function __construct(public $service) {} } }
namespace App\Events\ServiceCancellation { class Created { public function __construct(public $cancellation) {} } }
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
        public function where($key, $value) { return $this; }
        public function lockForUpdate() { \Illuminate\Support\Facades\DB::$locked = true; return $this; }
    }
    class Properties {
        public array $values = [];
        public function __construct(public string $customer) {}
        public function where($key, $value) {
            $stored = $value === 'stripe_id' ? $this->customer : ($this->values[$value] ?? null);
            return new Query($stored ? (object) ['value' => $stored] : null);
        }
        public function updateOrCreate($key, $data) { $this->values[$key['key']] = $data['value']; }
    }
    class User {
        public static $row;
        public $id = 7;
        public $name = 'Test';
        public $email = 'user@example.test';
        public function __construct(public Properties $props) {}
        public function properties() { return $this->props; }
        public function __get($key) { return $this->props; }
        public static function where($key, $id) { return new Query(self::$row); }
        public static function findOrFail($id) { return self::$row; }
    }
    class Invoice {
        public static $row;
        public static $rows = [];
        public $id = 12;
        public $number = 'INV-12';
        public $user_id = 7;
        public $currency_code = 'usd', $status = 'pending';
        public $items = [];
        public function __construct(public User $user) {}
        public static function where($key, $id) { return new Query(self::$rows[$id] ?? (self::$row && self::$row->id == $id ? self::$row : null)); }
        public function transactions() { return new class {
            private $id;
            public function where($key, $value) { if ($key === 'transaction_id') $this->id = $value; return $this; }
            public function exists() { return (\App\Helpers\ExtensionHelper::$payments['12:' . $this->id]['status'] ?? null) === 'succeeded'; }
        }; }
        public static function findOrFail($id) {
            return self::$rows[$id] ?? ($id == self::$row->id ? self::$row : throw new \RuntimeException('Missing invoice'));
        }
    }
    class Gateway {
        public static $rows = null;
        public static $enabled = true;
        public static $bundled = true;
        public static $bundledEnabled = false;
        public static function where($key, $value) { if (self::$rows !== null) return new \AdoptionCollection(self::$rows[$value] ?? []); return new Query($value === 'Stripe' ? (self::$bundled ? (object) ['enabled' => self::$bundledEnabled] : null) : (object) ['enabled' => self::$enabled]); }
    }
    class Service {
        public const STATUS_CANCELLED = 'cancelled';
        public $id = 41, $subscription_id = 'sub_test', $price = '19.99', $quantity = 3;
        public static $row;
        public $currency, $plan, $product, $properties;
        public array $invoiceRows = [];
        public static function where($key, $value) { return new Query(self::$row && self::$row->$key === $value ? self::$row : null); }
        public function update($values) { foreach ($values as $key => $value) $this->$key = $value; }
        public function properties() { return $this->properties; }
        public function invoiceItems() { return new class($this->invoiceRows) {
            public function __construct(private $rows) {}
            public function whereHas($relation, $callback) { $this->rows = array_filter($this->rows, fn ($row) => $row->invoice->status === 'pending'); return $this; }
            public function get() { return $this->rows; }
            public function latest($field) { usort($this->rows, fn ($a, $b) => $b->created_at <=> $a->created_at); return $this; }
            public function first() { return array_values($this->rows)[0] ?? null; }
        }; }
        public array $dirty = ['price'];
        public function __construct() {
            $this->properties = new Properties('');
            $this->currency = (object) ['code' => 'usd'];
            $this->plan = (object) ['billing_unit' => 'month', 'billing_period' => 1];
            $this->product = (object) ['id' => 8, 'name' => 'Test product'];
        }
        public function isDirty($field) { return in_array($field, $this->dirty); }
    }
    class BillingAgreement { public $external_reference = 'pm_test', $id = 4; }
    class Extension {}
    class InvoiceTransaction {
        public static function where($key, $value) { return new class($value) {
            private $status, $currentRead = false;
            public function __construct(private $id) {}
            public function lockForUpdate() { $this->currentRead = true; return $this; }
            public function first() {
                if ($this->status && !$this->currentRead) throw new \RuntimeException('Settlement guard must use a current read');
                foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                if (str_ends_with($key, ':' . $this->id) && (!$this->status || $row['status'] === $this->status)) return (object) [...$row, 'gateway' => (object) ['extension' => $row['gateway']]];
            } return null; }
            public function where($key, $value) { if ($key === 'status') $this->status = $value; return $this; }
            public function exists() { foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                if (str_ends_with($key, ':' . $this->id) && $row['status'] === 'succeeded') return true;
            } return false; }
        }; }
    }
}
namespace App\Helpers {
    class ExtensionHelper {
        public static $agreements = [];
        public static $payments = [];
        public static $applications = 0;
        public static $fees = [];
        public static function addPaymentFee($id, $fee) { self::$fees[$id] = $fee; }
        public static function makeBillingAgreement($user, $gateway, $name, $id, $type, $expiry) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Agreement lock missing');
            self::$agreements[$id] = compact('name', 'gateway', 'type', 'expiry');
        }
        // Native helper updateOrCreate is keyed by invoice + transaction_id under an invoice lock.
        public static function addPayment($invoice, $gateway, $amount, $fee, $id, $status = 'succeeded') {
            ++self::$applications;
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
    class Log { public static $lines = [], $contexts = [], $warnings = []; public static function info($message, $context = []) { self::$lines[] = $message; self::$contexts[] = $context; } public static function warning($message, $context = []) { self::$warnings[] = [$message, $context]; } }
    class Http {
        public static $calls = [];
        public static $handler;
        private $headers = [];
        public static function withHeaders($headers) { $http = new self; $http->headers = $headers; return $http; }
        public function asForm() { return $this; }
        public function timeout($seconds) { return $this; }
        public function __call($verb, $args) {
            [$url, $data] = $args;
            $path = substr($url, strlen('https://api.stripe.com/v1'));
            self::$calls[] = [$verb, $path, $data, $this->headers];
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
    function now() { return new class { public $timestamp = 1700000000; public function startOfDay() { return $this; } }; }
    function storage_path($path) { return $GLOBALS['adoptionStorage'] . '/' . $path; }
    function app() { return new class { public function runningInConsole() { return true; } }; }
    function abort($status, $message = '') { throw new \RuntimeException('HTTP ' . $status); }
    function abort_unless($condition, $status) { if (!$condition) throw new \RuntimeException('HTTP ' . $status); }
    function __($key, $values = []) { return $key; }
    function view($name, $data) { return compact('name', 'data'); }
    function redirect() { return new class { public function route($name) { return $this; } public function with($key, $value) { return $value; } }; }
    function response() { return new class { public function json($data, $status = 200) { return compact('data', 'status'); } }; }
}
