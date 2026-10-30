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
namespace App\Exceptions { class DisplayException extends \Exception {} }
namespace Illuminate\Database { class UniqueConstraintViolationException extends \RuntimeException {} }
namespace Illuminate\Http\Client {
    class ConnectionException extends \RuntimeException {}
    class RequestException extends \RuntimeException {
        public $response;
        public function __construct($status, $error = []) {
            parent::__construct('sensitive request payload');
            $this->response = new class($status, $error) {
                public function __construct(private $code, private $error) {}
                public function status() { return $this->code; }
                public function json($key) {
                    $value = $this->error;
                    foreach (explode('.', $key) as $part) $value = $value[$part] ?? null;
                    return $value;
                }
            };
        }
    }
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
        public function __construct(private $value, private ?string $model = null) {}
        public function first() { return $this->value; }
        public function exists() { return (bool) $this->value; }
        public function firstOrFail() { return $this->value ?? throw new \RuntimeException('Missing row'); }
        public function where($key, $value) { return $this; }
        public function lockForUpdate() { if ($this->model === User::class && ($hook = \Illuminate\Support\Facades\DB::$beforeUserLock)) { \Illuminate\Support\Facades\DB::$beforeUserLock = null; $hook(); } if ($this->model === User::class) \Illuminate\Support\Facades\DB::$userLocked = true; \Illuminate\Support\Facades\DB::$locked = true; \Illuminate\Support\Facades\DB::$modelLocks[] = $this->model; return $this; }
    }
    class Properties {
        public array $values = [];
        public function __construct(public string $customer) {}
        public function where($key, $value) {
            $stored = $value === 'stripe_id' ? $this->customer : ($this->values[$value] ?? null);
            return new class($stored ? (object) ['value' => $stored] : null, $this, $value) extends Query {
                public function __construct($stored, private Properties $properties, private string $key) { parent::__construct($stored); }
                public function delete() { unset($this->properties->values[$this->key]); }
            };
        }
        public function updateOrCreate($key, $data) {
            if ($key['key'] === 'stripe_id') $this->customer = $data['value'];
            else $this->values[$key['key']] = $data['value'];
        }
    }
    class User {
        public static $row;
        public static $staff = false, $gatewayUpdate = false;
        public function canAccessPanel($panel) { return self::$staff; }
        public function can($ability, $model) { return self::$gatewayUpdate; }
        public $id = 7;
        public $name = 'Test';
        public $email = 'user@example.test';
        public function __construct(public Properties $props) {}
        public function properties() { return $this->props; }
        public function __get($key) { return $this->props; }
        public static function where($key, $id) { return new Query(self::$row, User::class); }
        public static function findOrFail($id) { return self::$row; }
        public static function whereNotNull($key) { return new class { public function get() { return [(object) ['id' => 99]]; } }; }
    }
    class Invoice {
        public static $row;
        public static $rows = [];
        public $id = 12;
        public $number = 'INV-12';
        public $user_id = 7;
        public $currency_code = 'usd', $status = 'pending';
        public $total = 20;
        public $items = [];
        public function __construct(public User $user) {}
        public function getMorphClass() { return self::class; }
        public static function where($key, $id) { return new Query(self::$rows[$id] ?? (self::$row && self::$row->id == $id ? self::$row : null), Invoice::class); }
        public function transactions() { return new class($this->id) {
            private $id, $status;
            public function __construct(private $invoiceId) {}
            public function where($key, $value) { if ($key === 'transaction_id') $this->id = $value; if ($key === 'status') $this->status = $value; return $this; }
            public function lockForUpdate() { return $this; }
            public function get() {
                $rows = array_filter(\App\Helpers\ExtensionHelper::$payments, fn ($row, $key) => str_starts_with($key, $this->invoiceId . ':') && (!$this->status || $row['status'] === $this->status), ARRAY_FILTER_USE_BOTH);
                return new class($rows) {
                    public function __construct(private $rows) {}
                    public function sum($field) { return array_sum(array_column($this->rows, $field)); }
                };
            }
            public function exists() { return (\App\Helpers\ExtensionHelper::$payments[$this->invoiceId . ':' . $this->id]['status'] ?? null) === 'succeeded'; }
        }; }
        public static function findOrFail($id) {
            return self::$rows[$id] ?? ($id == self::$row->id ? self::$row : throw new \RuntimeException('Missing invoice'));
        }
    }
    class Gateway extends Extension {
        public static $rows = null;
        public static $enabled = true;
        public static $present = true, $deleted = false;
        public static $bundled = true;
        public static $bundledEnabled = false;
        public static $bundledDeleted = false;
        public static function withTrashed() { return new class {
            public function where($key, $value) { return Gateway::query($value, true); }
        }; }
        public static function where($key, $value) { return self::query($value); }
        public static function query($value, $withTrashed = false) {
            if (self::$rows !== null) {
                $rows = self::$rows[$value] ?? [];
                if (!$withTrashed) $rows = array_filter($rows, function ($row) {
                    foreach (\Illuminate\Support\Facades\DB::$tables['extensions'] as $raw) {
                        if ($raw['id'] === $row->id) return $raw['deleted_at'] === null;
                    }
                    return false;
                });
                return new NativeModelQuery($rows);
            }
            $bundled = $value === 'Stripe';
            $present = $bundled ? self::$bundled : self::$present;
            $deleted = $bundled ? self::$bundledDeleted : self::$deleted;
            return new NativeModelQuery($present && ($withTrashed || !$deleted)
                ? [new Extension(['id' => $bundled ? 1 : 2, 'extension' => $value, 'enabled' => $bundled ? self::$bundledEnabled : self::$enabled])] : []);
        }
    }
    class NativeModelQuery implements \IteratorAggregate {
        public function __construct(private array $rows) {}
        public static function table($table) { return new self(array_map(fn ($r) => (object) $r, \Illuminate\Support\Facades\DB::$tables[$table] ?? [])); }
        public function where($key, $value) { $this->rows = array_filter($this->rows, fn ($r) => ($r->$key ?? null) == $value); return $this; }
        public function whereIn($key, $values) { $this->rows = array_filter($this->rows, fn ($r) => in_array($r->$key ?? null, (array) $values)); return $this; }
        public function whereNull($key) { return $this->where($key, null); }
        public function whereNotNull($key) { $this->rows = array_filter($this->rows, fn ($r) => ($r->$key ?? null) !== null); return $this; }
        public function whereHas($relation, $callback) {
            $this->rows = array_filter($this->rows, function ($r) use ($callback) {
                $query = self::table('properties')->where('model_id', $r->id)->where('model_type', Service::class);
                $callback($query); return $query->exists();
            }); return $this;
        }
        public function lockForUpdate() { \Illuminate\Support\Facades\DB::$locked = true; \Illuminate\Support\Facades\DB::$modelLocks[] = Gateway::class; return $this; }
        public function get() { return $this; }
        public function first() { return reset($this->rows) ?: null; }
        public function firstOrFail() { return $this->first() ?? throw new \RuntimeException('Missing row'); }
        public function exists() { return (bool) $this->rows; }
        public function pluck($key) { return array_map(fn ($r) => $r->$key, array_values($this->rows)); }
        public function count() { return count($this->rows); }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
    }
    class Service {
        public const STATUS_CANCELLED = 'cancelled';
        public static function whereIn($key, $values) { return NativeModelQuery::table('services')->whereIn($key, $values); }
        public static function whereNull($key) { return NativeModelQuery::table('services')->whereNull($key); }
        public $user_id = 7, $status = 'active';
        public $billing_agreement_id = null, $auto_pay = false;
        public $id = 41, $subscription_id = 'sub_test', $price = '19.99', $quantity = 3;
        public static $row;
        public $currency, $plan, $product, $properties;
        public array $invoiceRows = [];
        public static function where($key, $value) { return new Query(self::$row && self::$row->$key === $value ? self::$row : null, Service::class); }
        public function update($values) { foreach ($values as $key => $value) $this->$key = $value; }
        public function properties() { return $this->properties; }
        public function invoiceItems() { return new class($this->invoiceRows) {
            public function __construct(private $rows) {}
            public function where($key, $value) {
                if ($key !== 'invoice_id') throw new \RuntimeException('Unexpected invoice item filter: ' . $key);
                $this->rows = array_filter($this->rows, fn ($row) => ($row->invoice_id ?? $row->invoice->id) == $value);
                return $this;
            }
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
    class BillingAgreement {
        public $external_reference = 'pm_test', $id = 4;
        public static ?array $snapshot = null;
        public static $beforeInsert = null;
        public static function withTrashed() { return new AgreementQuery(true); }
        public static function where($key, $value) { return (new AgreementQuery)->where($key, $value); }
        public function __construct(private array $attributes = []) {
            $this->external_reference = $attributes['external_reference'] ?? 'pm_test';
            $this->id = $attributes['id'] ?? 4;
        }
        public function __get($key) { return $this->attributes[$key] ?? null; }
        public function trashed() { return $this->deleted_at !== null; }
        public function services() { return Service::whereIn('billing_agreement_id', [$this->id]); }
        public function delete() { $this->update(['deleted_at' => '2026-10-23 12:00:00']); }
        public function update($data) {
            \App\Helpers\ExtensionHelper::$agreements[$this->attributes['external_reference']] = array_replace($this->attributes, $data);
        }
    }
    class AgreementQuery {
        private array $filters = [];
        private bool $current = false;
        public function __construct(private bool $trashed = false) {}
        public function where($key, $value) { $this->filters[$key] = $value; return $this; }
        public function lockForUpdate() { $this->current = true; return $this; }
        private function nativeRows() {
            $q = NativeModelQuery::table('billing_agreements');
            if (!$this->trashed) $q->whereNull('deleted_at');
            foreach ($this->filters as $key => $value) $q->where($key, $value);
            return $q;
        }
        public function exists() { return $this->nativeRows()->exists(); }
        public function pluck($key) { return $this->nativeRows()->pluck($key); }
        public function get() {
            $rows = $this->current ? \App\Helpers\ExtensionHelper::$agreements : (BillingAgreement::$snapshot ?? \App\Helpers\ExtensionHelper::$agreements);
            foreach ($rows as $id => $row) {
                $row += ['external_reference' => $id, 'deleted_at' => null];
                if (!$this->trashed && $row['deleted_at'] !== null) continue;
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) != $value) continue 2;
                $result[] = new BillingAgreement($row);
            }
            return $result ?? [];
        }
        public function first() { return $this->get()[0] ?? null; }
    }
    #[\AllowDynamicProperties]
    class Extension {
        public function __construct(array $values = []) { foreach ($values as $k => $v) $this->$k = $v; }
        public function settings() { return new class { public function where($k, $v) { return new Query((object) ['value' => 'whsec_test']); } }; }
        public function wasChanged($key) { return $this->changed ?? false; }
        public function update($values) {
            foreach ($values as $k => $v) $this->$k = $v;
            foreach (\Illuminate\Support\Facades\DB::$tables['extensions'] ?? [] as $i => $r) if ($r['id'] == $this->id) \Illuminate\Support\Facades\DB::$tables['extensions'][$i] = array_replace($r, $values);
            if (Gateway::$rows === null && ($this->extension ?? '') === 'Stripe') Gateway::$bundledEnabled = $this->enabled;
        }
    }
    class Audit {
        public static $rows = [], $fail = false;
        public static function where($key, $value) { return new class($key, $value) {
            private $filters = [], $currentRead = false;
            public function __construct($key, $value) { $this->where($key, $value); }
            public function where($key, $value) { $this->filters[$key] = $value; return $this; }
            public function lockForUpdate() { $this->currentRead = true; \Illuminate\Support\Facades\DB::$modelLocks[] = Audit::class; return $this; }
            public function first() {
                if (!$this->currentRead && \Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Review guard must use a current read');
                foreach (Audit::$rows as $row) {
                    foreach ($this->filters as $key => $value) {
                        $actual = str_starts_with($key, 'new_values->') ? ($row['new_values'][substr($key, 12)] ?? null) : ($row[$key] ?? null);
                        if ($actual !== $value) continue 2;
                    }
                    return (object) $row;
                }
                return null;
            }
        }; }
        public static function create($row) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Review record lock missing');
            if (self::$fail) { self::$fail = false; throw new \RuntimeException('Review record unavailable'); }
            self::$rows[] = $row;
        }
    }
    class Notification {
        public static $rows = [], $fail = false;
        public static function create($row) { if (self::$fail) throw new \RuntimeException('Inbox unavailable'); self::$rows[] = $row; }
    }
    class InvoiceTransaction {
        public static function where($key, $value) { return new class($value) {
            private $status, $currentRead = false;
            public function __construct(private $id) {}
            public function lockForUpdate() { $this->currentRead = true; \Illuminate\Support\Facades\DB::$modelLocks[] = InvoiceTransaction::class; return $this; }
            public function first() {
                if ($this->status && !$this->currentRead) throw new \RuntimeException('Settlement guard must use a current read');
                foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                if (str_ends_with($key, ':' . $this->id) && (!$this->status || $row['status'] === $this->status)) return (object) [...$row, 'id' => crc32($key), 'invoice' => Invoice::findOrFail(explode(':', $key)[0]), 'gateway' => (object) ['extension' => $row['gateway']]];
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
        public static function getExtension($type, $extension, $config = []) {
            $class = '\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $extension . '\\' . $extension;
            return new $class($config);
        }
        public static $agreements = [];
        public static $agreementFailure = false;
        public static $payments = [];
        public static $applications = 0;
        public static $fees = [], $feeWrites = 0;
        public static function addPaymentFee($id, $fee) { ++self::$feeWrites; self::$fees[$id] = $fee; }
        public static function makeBillingAgreement($user, $gateway, $name, $id, $type, $expiry) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Agreement lock missing');
            $gateway_id = \App\Models\Gateway::where('extension', $gateway)->firstOrFail()->id;
            $row = \App\Models\BillingAgreement::where('external_reference', $id)->where('user_id', $user->id)->where('gateway_id', $gateway_id)->first();
            if (!$row) {
                if ($hook = \App\Models\BillingAgreement::$beforeInsert) {
                    \App\Models\BillingAgreement::$beforeInsert = null;
                    $hook();
                }
                if (isset(self::$agreements[$id])) throw new \Illuminate\Database\UniqueConstraintViolationException('Duplicate external_reference');
            }
            self::$agreements[$id] = compact('name', 'gateway', 'type', 'expiry', 'gateway_id')
                + ['id' => 4, 'external_reference' => $id, 'user_id' => $user->id, 'deleted_at' => null];
            if (self::$agreementFailure) { self::$agreementFailure = false; throw new \RuntimeException('Interrupted agreement response'); }
            return new \App\Models\BillingAgreement(self::$agreements[$id]);
        }
        // Native helper updateOrCreate is keyed by invoice + transaction_id under an invoice lock.
        public static function addPayment($invoice, $gateway, $amount, $fee, $id, $status = 'succeeded') {
            ++self::$applications;
            self::$payments[$invoice->id . ':' . $id] = compact('gateway', 'amount', 'status');
            return (object) ['id' => crc32($invoice->id . ':' . $id), 'invoice_id' => $invoice->id];
        }
        public static function addProcessingPayment(...$args) { return self::addPayment(...[...$args, 'processing']); }
        public static function addFailedPayment(...$args) { return self::addPayment(...[...$args, 'failed']); }
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
        public static $routes = [], $definitions = [], $names = [];
        public static function has($name) { return isset(self::$names[$name]); }
        public static function post($path, $action) { self::$routes[] = $path; self::$definitions[$path] = ['method' => 'POST', 'action' => $action]; return new self; }
        public static function get($path, $action) { self::$routes[] = $path; self::$definitions[$path] = ['method' => 'GET', 'action' => $action]; return new self; }
        public function withoutMiddleware($value) { self::$definitions[end(self::$routes)]['withoutMiddleware'] = $value; return $this; }
        public function middleware($value) { self::$definitions[end(self::$routes)]['middleware'] = $value; return $this; }
        public function name($value) { self::$names[$value] = end(self::$routes); return $this; }
    }
    class Schedule {
        public static $callbacks = [], $events = [];
        public static function call($callback) { self::$callbacks[] = $callback; return self::$events[] = new self; }
        public function name($value) { return $this; }
        public function hourly() { return $this; }
        public $singleServer = false, $overlapMinutes = null;
        public function onOneServer() { $this->singleServer = true; return $this; }
        public function withoutOverlapping($minutes = 1440) { $this->overlapMinutes = $minutes; return $this; }
    }
    class View { public static function addNamespace($name, $path) {} }
    class Event { public static $listeners = []; public static function listen($type, $callback) { self::$listeners[] = $callback; } }
    class Auth { public static $id = 7; public static function id() { return self::$id; } public static function user() { return self::$id ? \App\Models\User::$row : null; } }
    class DB {
        public static $tables = [], $locks = [], $updates = [];
        public static function connection() { return new class { public function getDatabaseName() { return 'paymenter'; } }; }
        public static $concurrentAgreements = [];
        public static function table($name) { return match ($name) { 'ae_stripe_transaction_methods' => new \TransactionMethodQuery, 'ae_stripe_payment_fees' => new \FeeQuery, default => new \AdoptionQuery($name) }; }
        public static $beforeUserLock = null, $afterTransaction = null;
        private static $afterCommit = [];
        public static function afterCommit($callback) { self::$afterCommit[] = $callback; }
        public static $locked = false, $userLocked = false;
        public static $modelLocks = [];
        public static function transaction($callback) {
            $priorUserLock = self::$userLocked;
            $outerLocked = self::$locked;
            $callbacks = count(self::$afterCommit);
            $properties = \App\Models\User::$row?->props->values;
            $before = self::$tables;
            $agreements = \App\Helpers\ExtensionHelper::$agreements;
            $fees = \App\Helpers\ExtensionHelper::$fees;
            $audit = \App\Models\Audit::$rows;
            $notifications = \App\Models\Notification::$rows;
            self::$locked = true;
            try { $result = $callback(); } catch (\Throwable $e) { self::$afterCommit = array_slice(self::$afterCommit, 0, $callbacks); if (\App\Models\User::$row && $properties !== null) \App\Models\User::$row->props->values = $properties; self::$tables = $before; \App\Helpers\ExtensionHelper::$agreements = array_replace($agreements, self::$concurrentAgreements); \App\Helpers\ExtensionHelper::$fees = $fees; \App\Models\Audit::$rows = $audit; \App\Models\Notification::$rows = $notifications; throw $e; }
            finally { self::$locked = $outerLocked; self::$userLocked = $priorUserLock; }
            if ($hook = self::$afterTransaction) { self::$afterTransaction = null; $hook(); }
            if (!$outerLocked) { $pending = self::$afterCommit; self::$afterCommit = []; foreach ($pending as $callback) $callback(); }
            return $result;
        }
    }
    class Log { public static $lines = [], $contexts = [], $warnings = [], $errors = []; public static function info($message, $context = []) { self::$lines[] = $message; self::$contexts[] = $context; } public static function warning($message, $context = []) { self::$warnings[] = [$message, $context]; } public static function error($message, $context = []) { self::$errors[] = [$message, $context]; } }
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
    class TransactionMethodQuery {
        private $id;
        public function where($key, $value) { $this->id = $value; return $this; }
        public function exists() { return isset(\Illuminate\Support\Facades\DB::$tables['ae_stripe_transaction_methods'][$this->id]); }
        public function delete() {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Method deletion requires settlement locks');
            unset(\Illuminate\Support\Facades\DB::$tables['ae_stripe_transaction_methods'][$this->id]);
        }
        public function insertOrIgnore($row) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Method write requires settlement locks');
            \Illuminate\Support\Facades\DB::$tables['ae_stripe_transaction_methods'][$row['invoice_transaction_id']] ??= $row;
        }
    }
    function report($error) { $GLOBALS['reportedErrors'][] = $error; }
    function now() { return new class { public $timestamp = 1700000000; public function startOfDay() { return $this; } }; }
    function base_path($path) { return ($GLOBALS['paymenterBase'] ?? dirname(__DIR__)) . '/' . (isset($GLOBALS['paymenterBase']) ? $path : str_replace('extensions/Gateways/', 'extension/', $path)); }
    function storage_path($path) { return $GLOBALS['adoptionStorage'] . '/' . $path; }
    function app($class = null) { if ($class) return new $class; return new class { public function runningInConsole() { return true; } public function booted($callback) { $callback(); } }; }
    function abort($status, $message = '') { throw new \RuntimeException('HTTP ' . $status); }
    function abort_unless($condition, $status) { if (!$condition) throw new \RuntimeException('HTTP ' . $status); }
    function __($key, $values = []) { return $key; }
    function view($name, $data) { return compact('name', 'data'); }
    function url($path) { return 'https://paymenter.example.com' . $path; }
    function route($name) { return url(\Illuminate\Support\Facades\Route::$names[$name]); }
    function redirect() { return new class { public function route($name) { return $this; } public function with($key, $value) { return $value; } }; }
    function response() { return new class { public function json($data, $status = 200) { return compact('data', 'status'); } }; }
}

namespace {
    class FeeQuery {
        public static $beforeRead = null;
        private $filters = [], $locked = false, $limit = 100, $order = [];
        public function where($key, $value) { $this->filters[$key] = $value; return $this; }
        public function orderBy($key) { $this->order[] = $key; return $this; }
        public function limit($value) { $this->limit = $value; return $this; }
        public function lockForUpdate() { $this->locked = true; return $this; }
        public function get() {
            $rows = [];
            foreach (\Illuminate\Support\Facades\DB::$tables['ae_stripe_payment_fees'] ?? [] as $row) {
                foreach ($this->filters as $key => $value) if ($row[$key] !== $value) continue 2;
                $rows[] = (object) $row;
            }
            usort($rows, function ($a, $b) { foreach ($this->order as $key) { if ($a->$key !== $b->$key) return $a->$key <=> $b->$key; } return 0; });
            return array_slice($rows, 0, $this->limit);
        }
        public function first() {
            if ($hook = self::$beforeRead) { self::$beforeRead = null; $hook(); }
            if (!$this->locked || !\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Fee current read lock missing');
            return $this->get()[0] ?? null;
        }
        public function insert($row) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Fee insert lock missing');
            $rows = &\Illuminate\Support\Facades\DB::$tables['ae_stripe_payment_fees'];
            if (isset($rows[$row['payment_intent_id']])) throw new \RuntimeException('Duplicate PaymentIntent');
            $rows[$row['payment_intent_id']] = $row;
        }
        public function update($values) {
            foreach ($this->get() as $row) \Illuminate\Support\Facades\DB::$tables['ae_stripe_payment_fees'][$row->payment_intent_id] = array_merge((array) $row, $values);
        }
    }
}

namespace Illuminate\Database\Migrations {
    class Migration {}
    class Migrator {
        public static $ran = [];
        public function run($path) {
            foreach (glob($path . '/*.php') as $file) {
                if (isset(self::$ran[$file])) continue;
                (require $file)->up(); self::$ran[$file] = true;
            }
        }
    }
}
namespace Illuminate\Database\Schema {
    class Blueprint {
        public static $primary = [];
        public function __call($method, $args) { if ($method === 'primary') self::$primary[] = $GLOBALS['feeColumn']; else $GLOBALS['feeColumn'] = $args[0] ?? null; return $this; }
    }
}
namespace Illuminate\Support\Facades {
    class Schema {
        public static $checks = 0, $beforeCheck = null, $beforeCreate = null, $afterRename = null, $afterColumnRename = null;
        public static function rename($from, $to) { if (DB::$locked) throw new \RuntimeException('MySQL DDL ends the active transaction'); DB::$tables[$to] = DB::$tables[$from]; unset(DB::$tables[$from]); if ($hook = self::$afterRename) $hook(); }
        public static function table($table, $callback) { if (DB::$locked) throw new \RuntimeException('MySQL DDL ends the active transaction'); $callback(new class($table) {
            public function __construct(private $table) {}
            public function renameColumn($from, $to) { foreach (DB::$tables[$this->table] as &$row) { $row[$to] = $row[$from]; unset($row[$from]); } if ($hook = Schema::$afterColumnRename) $hook(); }
        }); }
        public static $foreignTables = [], $inspectedTables = [];
        public static function getTableListing($schema = null, $schemaQualified = true) {
            $schemas = ['paymenter' => DB::$tables] + self::$foreignTables;
            $names = [];
            foreach ($schemas as $database => $tables) {
                if ($schema !== null && $schema !== $database) continue;
                foreach ($tables as $table => $rows) $names[] = $schemaQualified ? $database . '.' . $table : $table;
            }
            return $names;
        }
        public static function getColumnListing($name) {
            self::$inspectedTables[] = $name;
            [$schema, $table] = str_contains($name, '.') ? explode('.', $name, 2) : ['paymenter', $name];
            $rows = $schema === 'paymenter' ? (DB::$tables[$table] ?? []) : (self::$foreignTables[$schema][$table] ?? []);
            return array_keys($rows[array_key_first($rows)] ?? []);
        }
        public static function hasTable($name) { ++self::$checks; if ($hook = self::$beforeCheck) $hook(); return isset(DB::$tables[$name]); }
        public static function create($name, $callback) { if ($hook = self::$beforeCreate) $hook(); if (isset(DB::$tables[$name])) throw new \RuntimeException('Table exists'); DB::$tables[$name] = []; $callback(new \Illuminate\Database\Schema\Blueprint); }
        public static function dropIfExists($name) { unset(DB::$tables[$name]); }
    }
}

namespace Filament\Notifications {
    class Notification {
        public static $sent = [];
        private $title;
        public static function make() { return new self; }
        public function title($value) { $this->title = $value; return $this; }
        public function body($value) { return $this; }
        public function success() { return $this; }
        public function send() { self::$sent[] = $this->title; }
    }
}

namespace Filament\Facades {
    class Filament {
        public static $panel = 'admin';
        public static function getCurrentPanel() { return new class { public function getId() { return Filament::$panel; } }; }
    }
}
namespace Filament\Support\Facades {
    class FilamentView {
        public static $hooks = [];
        public static function registerRenderHook($name, $callback) { self::$hooks[$name] = $callback; }
    }
}
namespace Filament\View { class PanelsRenderHook { const PAGE_START = 'panels::page.start'; const CONTENT_START = 'panels::content.start'; } }
