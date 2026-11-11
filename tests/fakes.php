<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\AeStripe\Concerns {
    function time(): int { return $GLOBALS['fakeStripeTime'] ?? \time(); }
}
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
namespace { if (!class_exists('PDOException')) { class PDOException extends \RuntimeException {} } }
namespace App\Exceptions { class DisplayException extends \Exception {} }
namespace Livewire {
    class Livewire {
        public static $component;
        public static function current() { return self::$component; }
    }
}
namespace App\Admin\Resources\GatewayResource\Pages {
    class EditGateway {
        public array $data = [];
        public function __construct(private $record) {}
        public function getRecord() { return $this->record; }
    }
}
namespace Illuminate\Database { class UniqueConstraintViolationException extends \RuntimeException {} class QueryException extends \RuntimeException {} }
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
namespace App\Events\Invoice { class Updated { public function __construct(public $invoice) {} } }
namespace App\Events\Service { class Updated { public function __construct(public $service) {} } }
namespace App\Events\Service { class Created { public function __construct(public $service) {} } }
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
        private bool $currentRead = false;
        public function __construct(private $value, private ?string $model = null) {}
        public function first() { return $this->value; }
        public function exists() { return (bool) $this->value; }
        public function firstOrFail() { if (!$this->currentRead && $this->model === User::class && \Illuminate\Support\Facades\DB::$trackPaymentSnapshot && !\Illuminate\Support\Facades\DB::$readCommitted && \Illuminate\Support\Facades\DB::$locked) \Illuminate\Support\Facades\DB::$paymentSnapshot ??= \App\Helpers\ExtensionHelper::$payments; return $this->value ?? throw new \RuntimeException('Missing row'); }
        public function get() { return $this->value ? [$this->value] : []; }
        public function where($key, $value) { return $this; }
        public function lockForUpdate() { $this->currentRead = true; if ($this->model === Invoice::class && ($hook = \Illuminate\Support\Facades\DB::$beforeInvoiceLock)) { \Illuminate\Support\Facades\DB::$beforeInvoiceLock = null; $hook(); } if ($this->model === Service::class && ($hook = \Illuminate\Support\Facades\DB::$beforeServiceLock)) $hook(); if ($this->model === User::class && ($hook = \Illuminate\Support\Facades\DB::$beforeUserLock)) { \Illuminate\Support\Facades\DB::$beforeUserLock = null; $before = \App\Helpers\ExtensionHelper::$payments; $hook(); foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) if (($before[$key] ?? null) !== $row) \Illuminate\Support\Facades\DB::$concurrentPayments[$key] = $row; } if ($this->model === User::class) \Illuminate\Support\Facades\DB::$userLocked = true; \Illuminate\Support\Facades\DB::$locked = true; if ($this->model) \Illuminate\Support\Facades\DB::$modelLocks[] = $this->model; return $this; }
    }
    class Property {
        public static ?array $lastRawOrder = null;
        public static function where(...$args) { return new class($args[2] ?? '') {
            public function __construct(private string $pattern) {}
            private bool $filtered = false;
            private ?array $rawOrder = null;
            private int $rowLimit = PHP_INT_MAX;
            public function where(...$args) { $this->filtered = true; return $this; }
            public function orderBy($key) { return $this; }
            public function orderByRaw($sql, $bindings = []) { $this->rawOrder = Property::$lastRawOrder = [$sql, $bindings]; return $this; }
            public function limit($n) { $this->rowLimit = $n; return $this; }
            public function get() {
                $result = [];
                foreach (User::$row->props->values as $key => $value) {
                    $record = json_decode($value, true);
                    $selected = str_contains($this->pattern, 'payment_alert')
                        ? str_starts_with($key, 'ae_stripe_payment_alert_') && !($record['sent'] ?? true)
                        : str_starts_with($key, 'ae_stripe_checkout_') && (!$this->filtered || ($record['retry'] ?? false) || ($record['version'] ?? null) !== 2 || !empty($record['agreement_id']));
                    if ($selected) $result[] = (object) ['key' => $key, 'value' => $value, 'model_type' => User::class, 'model_id' => User::$row->id];
                }
                if ($this->rawOrder) {
                    // The query regression pins this expression and its bindings to the production SQL.
                    $bindings = $this->rawOrder[1];
                    $matches = fn ($row, $binding) => str_contains($row->value, trim($binding, '%'));
                    $priority = fn ($row) => $matches($row, $bindings[0]) || !$matches($row, $bindings[1])
                        || $matches($row, $bindings[2]) && !$matches($row, $bindings[3]) ? 0 : 1;
                    usort($result, fn ($a, $b) => $priority($a) <=> $priority($b));
                }
                return array_slice($result, 0, $this->rowLimit);
            }
        }; }
    }
    class Properties {
        public static $beforeWrite = null;
        public array $values = [];
        public function __construct(public string $customer) {}
        public function where($key, $value, $like = null) {
            if ($value === 'like') { // Prefix scans only, as AutoPay uses them.
                $rows = [];
                foreach ($this->values as $name => $stored) if (str_starts_with($name, rtrim($like, '%'))) $rows[] = (object) ['key' => $name, 'value' => $stored];
                return new class($rows) { public function __construct(private $rows) {} public function lockForUpdate() { return $this; } public function get() { return $this->rows; } };
            }
            $stored = $value === 'stripe_id' ? $this->customer : ($this->values[$value] ?? null);
            return new class($stored ? (object) ['key' => $value, 'value' => $stored] : null, $this, $value) extends Query {
                public function __construct($stored, private Properties $properties, private string $key) { parent::__construct($stored); }
                public function delete() { unset($this->properties->values[$this->key]); }
            };
        }
        public function updateOrCreate($key, $data) {
            if (self::$beforeWrite) (self::$beforeWrite)($key, $data);
            if ($key['key'] === 'stripe_id') $this->customer = $data['value'];
            else $this->values[$key['key']] = $data['value'];
        }
    }
    class User {
        public static $row;
        public static $staff = false, $gatewayUpdate = false;
        public static $noticeStaff = null;
        public $role_id = null;
        public $permissions = [];
        public function hasPermission($permission) { return in_array($permission, $this->permissions, true); }
        public function canAccessPanel($panel) { return self::$staff; }
        public function can($ability, $model) { return self::$gatewayUpdate; }
        public $id = 7;
        public $name = 'Test';
        public $email = 'user@example.test';
        public function __construct(public Properties $props) {}
        public function properties() { return $this->props; }
        public function getMorphClass() { return self::class; }
        public function __get($key) { return $this->props; }
        public static function where($key, $id) { return new Query(self::$row && self::$row->$key == $id ? self::$row : null, User::class); }
        public static function whereIn($key, $ids) { return new class($key, $ids) {
            public function __construct(private $key, private $ids) {}
            public function get() {
                return array_filter(array_merge(User::$row ? [User::$row] : [], User::whereNotNull('role_id')->get()),
                    fn ($user) => in_array($user->{$this->key}, $this->ids));
            }
        }; }
        public static function findOrFail($id) { return self::$row; }
        public static function whereNotNull($key) { return new class { public function get() {
            if (User::$noticeStaff !== null) return User::$noticeStaff;
            $staff = new User(new Properties('')); $staff->id = 99; $staff->role_id = 1;
            $staff->permissions = ['admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update', 'admin.gateways.viewAny', 'admin.gateways.update', 'admin.audit.viewAny'];
            return [$staff];
        } }; }
    }
    class Invoice {
        public static $row;
        public static $rows = [];
        public $id = 12;
        public $number = 'INV-12';
        public $exists = true;
        public $user_id = 7;
        public $currency_code = 'usd', $status = 'pending';
        public $total = 20;
        public $due_at = null;
        public $items = [];
        public function __construct(public ?User $user = null) {}
        public function items() { return new NativeModelQuery($this->items); }
        public function setRelation($key, $value) { $this->$key = is_array($value) ? $value : iterator_to_array($value); return $this; }
        public function getMorphClass() { return self::class; }
        public function fresh() { return $this; }
        public static function where($key, $id) { return new Query(self::$rows[$id] ?? (self::$row && self::$row->id == $id ? self::$row : null), Invoice::class); }
        public function transactions() { return new class($this->id) {
            private $id, $status, $currentRead = false;
            public function __construct(private $invoiceId) {}
            public function where($key, $value) { if ($key === 'transaction_id') $this->id = $value; if ($key === 'status') $this->status = $value; return $this; }
            public function lockForUpdate() { $this->currentRead = true; return $this; }
            public function pluck($field) { return new PluckedIds(array_map('crc32', array_keys($this->get()->rows))); }
            public function get() {
                $source = !$this->currentRead && \Illuminate\Support\Facades\DB::$trackPaymentSnapshot && !\Illuminate\Support\Facades\DB::$readCommitted ? (\Illuminate\Support\Facades\DB::$paymentSnapshot ??= \App\Helpers\ExtensionHelper::$payments) : \App\Helpers\ExtensionHelper::$payments;
                $rows = array_filter($source, fn ($row, $key) => str_starts_with($key, $this->invoiceId . ':') && (!$this->status || $row['status'] === $this->status), ARRAY_FILTER_USE_BOTH);
                return new class($rows) implements \IteratorAggregate {
                    public function __construct(public $rows) {}
                    public function getIterator(): \Traversable { foreach ($this->rows as $key => $row) yield (object) [...$row, "transaction_id" => substr($key, strpos($key, ":") + 1), "gateway" => (object) ["extension" => $row["gateway"] ?? "AeStripe"]]; }
                    public function sum($field) { return array_sum(array_column($this->rows, $field)); }
                };
            }
            public function exists() { return (\App\Helpers\ExtensionHelper::$payments[$this->invoiceId . ':' . $this->id]['status'] ?? null) === 'succeeded'; }
        }; }
        public function __get($key) {
            if ($key === 'formattedTotal') return new class { public function format($amount) { return '$' . number_format($amount, 2, '.', ''); } };
            return null;
        }
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
                return new NativeModelQuery($rows, Gateway::class);
            }
            $bundled = $value === 'Stripe';
            $present = $bundled ? self::$bundled : self::$present;
            $deleted = $bundled ? self::$bundledDeleted : self::$deleted;
            return new NativeModelQuery($present && ($withTrashed || !$deleted)
                ? [new Extension(['id' => $bundled ? 1 : 2, 'extension' => $value, 'enabled' => $bundled ? self::$bundledEnabled : self::$enabled])] : [], Gateway::class);
        }
    }
    class NativeServiceRow extends \stdClass {
        public function __construct(array $row) { foreach ($row as $key => $value) $this->$key = $value; }
        public function update(array $values) {
            foreach (\Illuminate\Support\Facades\DB::$tables['services'] as &$row) if ($row['id'] == $this->id) $row = array_replace($row, $values);
            foreach ($values as $key => $value) $this->$key = $value;
        }
    }
    class NativeModelQuery implements \IteratorAggregate {
        public function __construct(private array $rows, private ?string $model = null) {}
        public static function table($table) { return new self(array_map(fn ($r) => $table === 'services' ? new NativeServiceRow($r) : (object) $r, \Illuminate\Support\Facades\DB::$tables[$table] ?? []), $table === 'services' ? Service::class : null); }
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
        public function lockForUpdate() { \Illuminate\Support\Facades\DB::$locked = true; if ($this->model === Gateway::class) \Illuminate\Support\Facades\DB::$gatewayLocked = true; if ($this->model) \Illuminate\Support\Facades\DB::$modelLocks[] = $this->model; return $this; }
        public function get() { return $this; }
        public function first() { return reset($this->rows) ?: null; }
        public function firstOrFail() { return $this->first() ?? throw new \RuntimeException('Missing row'); }
        public function exists() { return (bool) $this->rows; }
        public function pluck($key) { return array_map(fn ($r) => $r->$key, array_values($this->rows)); }
        public function count() { return count($this->rows); }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
    }
    class Service {
        public static array $updatedCallbacks = [];
        public static function updated($callback) { self::$updatedCallbacks[] = $callback; }
        public static function updating($callback) { self::$updatedCallbacks[] = $callback; }
        public const STATUS_CANCELLED = 'cancelled';
        public static function whereIn($key, $values) { return NativeModelQuery::table('services')->whereIn($key, $values); }
        public static function whereNull($key) { return NativeModelQuery::table('services')->whereNull($key); }
        public $user_id = 7, $status = 'active';
        public $billing_agreement_id = null, $auto_pay = false;
        public $id = 41, $subscription_id = 'sub_test', $price = '19.99', $quantity = 3;
        public static $row;
        public $currency, $plan, $product, $properties;
        public $expires_at = null, $cancellation = null, $user;
        public array $pendingInvoices = [];
        public function getMorphClass() { return self::class; }
        public function invoices() { return new Query($this->pendingInvoices[0] ?? null); }
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
        public function __get($key) { if ($key === 'gateway') return (object) ['extension' => 'AeStripe']; return $this->attributes[$key] ?? null; }
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
            $rows = $this->current || !\Illuminate\Support\Facades\DB::$locked
                ? \App\Helpers\ExtensionHelper::$agreements
                : (BillingAgreement::$snapshot ??= \App\Helpers\ExtensionHelper::$agreements);
            foreach ($rows as $id => $row) {
                $row += ['external_reference' => $id, 'deleted_at' => null];
                if (!$this->trashed && $row['deleted_at'] !== null) continue;
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) != $value) continue 2;
                $result[] = new BillingAgreement($row);
            }
            return $result ?? [];
        }
        public function first() { return $this->get()[0] ?? null; }
        public function firstOrFail() { return $this->first() ?? throw new \RuntimeException('Missing row'); }
    }
    #[\AllowDynamicProperties]
    class Extension {
        public function getMorphClass() { return Extension::class; }
        public function __construct(array $values = []) { foreach ($values as $k => $v) $this->$k = $v; }
        public ?Properties $settingValues = null;
        public function settings() {
            if (!$this->settingValues) {
                $this->settingValues = new Properties('');
                $this->settingValues->values = ['stripe_secret_key' => 'rk_test_fixture', 'stripe_publishable_key' => 'pk_test_fixture', 'stripe_webhook_secret' => 'whsec_test'];
            }
            return $this->settingValues;
        }
        public function wasChanged($key) { return $this->changed ?? false; }
        public function update($values) {
            foreach ($values as $k => $v) $this->$k = $v;
            foreach (\Illuminate\Support\Facades\DB::$tables['extensions'] ?? [] as $i => $r) if ($r['id'] == $this->id) \Illuminate\Support\Facades\DB::$tables['extensions'][$i] = array_replace($r, $values);
            if (Gateway::$rows === null && ($this->extension ?? '') === 'Stripe') Gateway::$bundledEnabled = $this->enabled;
        }
    }
    class Audit extends \stdClass {
        public function __construct(array $row) { foreach ($row as $key => $value) $this->$key = $value; }
        public function update(array $values) {
            foreach (self::$rows as $index => $row) if ($row['id'] === $this->id) self::$rows[$index] = array_replace($row, $values);
            foreach ($values as $key => $value) $this->$key = $value;
        }
        public static $rows = [], $fail = false;
        public static function whereIn($key, $values) { return self::where($key, $values); }
        public static function where($key, $value) { return new class($key, $value) {
            private $filters = [], $currentRead = false, $order = [], $maximum = null;
            public function __construct($key, $value) { $this->where($key, $value); }
            public function where($key, $value) { $this->filters[$key] = $value; return $this; }
            public function whereIn($key, $values) { return $this->where($key, $values); }
            public function lockForUpdate() { $this->currentRead = true; \Illuminate\Support\Facades\DB::$modelLocks[] = isset($this->filters['id']) ? Audit::class . ':' . $this->filters['id'] : Audit::class; return $this; }
            public function orderBy($key) { $this->order[] = $key; return $this; }
            public function limit($n) { $this->maximum = $n; return $this; }
            public function first() {
                if (!$this->currentRead && \Illuminate\Support\Facades\DB::$locked && !\Illuminate\Support\Facades\DB::$userLocked && !\Illuminate\Support\Facades\DB::$gatewayLocked) throw new \RuntimeException('Review guard needs the owner lock');
                return $this->get()[0] ?? null;
            }
            public function get() {
                $rows = [];
                foreach (Audit::$rows as $row) {
                    foreach ($this->filters as $key => $value) {
                        $actual = str_starts_with($key, 'new_values->') ? ($row['new_values'][substr($key, 12)] ?? null) : ($row[$key] ?? null);
                        if (is_array($value) ? !in_array($actual, $value, true) : $actual !== $value) continue 2;
                    }
                    $rows[] = new Audit($row);
                }
                $value = fn ($row, $key) => str_starts_with($key, 'new_values->') ? ($row->new_values[substr($key, 12)] ?? null) : ($row->$key ?? null);
                usort($rows, function ($a, $b) use ($value) {
                    foreach ($this->order as $key) if (($comparison = $value($a, $key) <=> $value($b, $key)) !== 0) return $comparison;
                    return 0;
                });
                return $this->maximum === null ? $rows : array_slice($rows, 0, $this->maximum);
            }
            public function firstOrFail() { return $this->first() ?? throw new \RuntimeException('Missing audit'); }
        }; }
        public static function create($row) {
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Review record lock missing');
            if (self::$fail) { self::$fail = false; throw new \RuntimeException('Review record unavailable'); }
            $row['id'] = count(self::$rows) + 1;
            self::$rows[] = $row;
            return new self($row);
        }
    }
    class Notification {
        public static $rows = [], $fail = false;
        public static function create($row) { if (self::$fail) throw new \RuntimeException('Inbox unavailable'); self::$rows[] = $row; }
    }
    class Credit {
        public $id = 1;
        public static $rows = [];
        public static function whereKey($id) { foreach (self::$rows as $row) if ($row->id === $id) return new Query($row, Credit::class); return new Query(null, Credit::class); }
        public function __construct(public $user_id, public $currency_code, public $amount) {}
        public static function where($key, $value) { return new class($value) {
            private $currency;
            public function __construct(private $user) {}
            public function where($key, $value) { $this->currency = $value; return $this; }
            public function lockForUpdate() { return $this; }
            public function orderBy($key) { return $this; }
            public function value($key) { return $this->first()?->$key; }
            public function first() { return Credit::$rows[$this->user . ':' . $this->currency] ?? null; }
        }; }
        public static function create($row) { $credit = new self(...$row); $credit->id = count(self::$rows) + 1; return self::$rows[$row['user_id'] . ':' . $row['currency_code']] = $credit; }
        public function update($row) { $this->amount = $row['amount']; }
    }
    class InvoiceTransaction {
        public static $eventsSuppressed = false;
        public static function withoutEvents($callback) {
            $prior = self::$eventsSuppressed; self::$eventsSuppressed = true;
            try { return $callback(); } finally { self::$eventsSuppressed = $prior; }
        }
        public static function where($key, $value, $pattern = null) { return new class($pattern ?? $value, $pattern !== null) {
            private $status, $currentRead = false;
            public function __construct(private $id, private bool $prefix) {}
            private function matches($key) { $id = substr($key, strpos($key, ':') + 1); return $this->prefix ? str_starts_with($id, str_replace(['\\_', '\\%'], ['_', '%'], rtrim($this->id, '%'))) : $id === $this->id; }
            public function lockForUpdate() { $this->currentRead = true; \Illuminate\Support\Facades\DB::$modelLocks[] = InvoiceTransaction::class; return $this; }
            public function first() {
                if ($this->status && !$this->currentRead) throw new \RuntimeException('Settlement guard must use a current read');
                foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                if ($this->matches($key) && (!$this->status || $row['status'] === $this->status)) return (object) [...$row, 'id' => crc32($key), 'invoice' => Invoice::findOrFail(explode(':', $key)[0]), 'gateway' => (object) ['extension' => $row['gateway']]];
            } return null; }
            public function where($key, $value) { if ($key === 'status') $this->status = $value; return $this; }
            public function pluck($field) {
                // A plain settlement read sees every payment only under the owner lock that every payment writer holds.
                if ($this->status && !\Illuminate\Support\Facades\DB::$userLocked) throw new \RuntimeException('Settlement guard needs the owner lock');
                return new PluckedIds(array_map('crc32', array_keys(array_filter(\App\Helpers\ExtensionHelper::$payments,
                    fn ($row, $key) => $this->matches($key) && (!$this->status || $row['status'] === $this->status), ARRAY_FILTER_USE_BOTH))));
            }
            public function exists() { foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                if ($this->matches($key) && (!$this->status || $row['status'] === $this->status)) return true;
            } return false; }
            public function update(array $values) {
                $count = 0;
                foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                    if (!$this->matches($key) || $this->status && $row['status'] !== $this->status) continue;
                    unset(\App\Helpers\ExtensionHelper::$payments[$key]);
                    \App\Helpers\ExtensionHelper::$payments[explode(':', $key)[0] . ':' . ($values['transaction_id'] ?? $this->id)] = $row;
                    ++$count;
                }
                return $count;
            }
        }; }
        /** Payments by their fake ids (crc32 of invoice:transaction_id), in id order, for locking and updating by primary key. */
        public static function whereKey($ids) { return new class((array) $ids) {
            public function __construct(private $ids) {}
            public function orderBy($key) { return $this; }
            public function first() { return $this->get()[0] ?? null; }
            public function lockForUpdate() { \Illuminate\Support\Facades\DB::$modelLocks[] = InvoiceTransaction::class; return $this; }
            public function get() {
                $rows = [];
                foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) if (in_array(crc32($key), $this->ids, true)) $rows[crc32($key)] = (object) [...$row, 'id' => crc32($key),
                    'transaction_id' => substr($key, strpos($key, ':') + 1), 'invoice' => Invoice::findOrFail(explode(':', $key)[0]), 'gateway' => (object) ['extension' => $row['gateway'] ?? 'AeStripe']];
                ksort($rows);
                return array_values($rows);
            }
            public function update(array $values) {
                foreach ($this->get() as $row) InvoiceTransaction::where('transaction_id', $row->transaction_id)->update($values);
            }
        }; }
    }
    class PluckedIds { public function __construct(private array $ids) {} public function all() { return $this->ids; } }
}
namespace App\Helpers {
    class NotificationHelper {
        public static int $failures = 0;
        public static function invoicePaymentFailedNotification($user, $invoice) {
            ++self::$failures;
            \App\Models\Notification::create(['user_id' => $user->id, 'title' => 'Native payment failed', 'url' => '']);
        }
    }
    class ExtensionHelper {
        public static function charge($gateway, $invoice, $agreement): bool {
            return self::getExtension('gateway', $gateway->extension)->charge($invoice, $invoice->total, $agreement);
        }
        public static function getExtension($type, $extension, $config = []) {
            $class = '\\Paymenter\\Extensions\\' . ucfirst($type) . 's\\' . $extension . '\\' . $extension;
            return new $class($config);
        }
        public static $agreements = [];
        public static $agreementFailure = false;
        public static $payments = [];
        public static $applications = 0, $simulateNativeListeners = false;
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
        public static function addPayment($invoice, $gateway, $amount, $fee, $id, $status = 'succeeded', $isCreditTransaction = false) {
            ++self::$applications;
            self::$payments[$invoice->id . ':' . $id] = compact('gateway', 'amount', 'status') + ['is_credit_transaction' => $isCreditTransaction];
            if (self::$simulateNativeListeners && !\App\Models\InvoiceTransaction::$eventsSuppressed) {
                $net = 0; foreach (self::$payments as $key => $row) if (str_starts_with($key, $invoice->id . ':') && $row['status'] === 'succeeded') $net += $row['amount'];
                if ($invoice->total - $net <= 0 && $invoice->status !== 'paid') $invoice->status = 'paid';
            }
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
        public function validate($rules) {} // Real rules: tests/native-auto-pay-consent.php; no validation here.
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
        public function everyMinute() { return $this; }
        public function everyFiveMinutes() { return $this; }
        public $singleServer = false, $overlapMinutes = null;
        public function onOneServer() { $this->singleServer = true; return $this; }
        public function withoutOverlapping($minutes = 1440) { $this->overlapMinutes = $minutes; return $this; }
    }
    class View { public static function addNamespace($name, $path) {} }
    class Event { public static $listeners = []; public static function listen($type, $callback) { self::$listeners[$type] = $callback; } }
    class Auth { public static $id = 7; public static function id() { return self::$id; } public static function user() { return self::$id ? \App\Models\User::$row : null; } }
    class Session {
        public static array $values = [], $flashes = [];
        public static function put($key, $value) { self::$values[$key] = $value; }
        public static function get($key) { return self::$values[$key] ?? self::$flashes[$key] ?? null; }
        public static function forget($key) { unset(self::$values[$key], self::$flashes[$key]); }
        public static function flash($key, $value) { self::$flashes[$key] = $value; }
    }
    class Cache {
        public static array $locks = [];
        public static $beforeBlock = null;
        public static function lock($key, $seconds) { return new class($key) {
            public function __construct(private string $key) {}
            public function get() { if (Cache::$locks[$this->key] ?? false) return false; return Cache::$locks[$this->key] = true; }
            public function block($seconds) {
                if (Cache::$beforeBlock) { $callback = Cache::$beforeBlock; Cache::$beforeBlock = null; $callback(); }
                if (!$this->get()) throw new \Illuminate\Contracts\Cache\LockTimeoutException;
                return true;
            }
            public function release() { unset(Cache::$locks[$this->key]); }
        }; }
    }
    class DB {
        public static $tables = [], $locks = [], $updates = [];
        public static function connection() { return new class { public function getDatabaseName() { return 'paymenter'; } public function getDriverName() { return DB::$driver; } }; }
        public static $concurrentAgreements = [], $concurrentPayments = [];
        public static function table($name) { return match ($name) { 'ae_stripe_outbox' => new \OutboxQuery, 'ae_stripe_settlements' => new \SettlementQuery, 'ae_stripe_locks' => new \ExtensionLockQuery, 'invoice_transactions' => new \TransactionClassificationQuery, 'ae_stripe_receipt_returns' => new \ReceiptReturnQuery, 'ae_stripe_transaction_methods' => new \TransactionMethodQuery, 'ae_stripe_payment_fees' => new \FeeQuery, default => new \AdoptionQuery($name) }; }
        public static $beforeUserLock = null, $beforeServiceLock = null, $beforeInvoiceLock = null, $afterTransaction = null;
        public static string $driver = 'sqlite';
        public static bool $readCommitted = false;
        private static bool $nextReadCommitted = false;
        public static function statement($sql) {
            if ($sql !== 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' || self::transactionLevel() !== 0) throw new \RuntimeException('Unexpected isolation change');
            self::$nextReadCommitted = true;
        }
        public static bool $trackPaymentSnapshot = false;
        public static ?array $paymentSnapshot = null;
        private static int $level = 0;
        public static function transactionLevel() { return max(self::$level, self::$locked ? 1 : 0); }
        private static $afterCommit = [];
        public static function afterCommit($callback) { if (!self::$locked) $callback(); else self::$afterCommit[] = $callback; }
        public static $locked = false, $userLocked = false, $gatewayLocked = false;
        public static $modelLocks = [];
        public static function transaction($callback) {
            $priorUserLock = self::$userLocked;
            $priorGatewayLock = self::$gatewayLocked;
            $outerLocked = self::$locked;
            $previousIsolation = self::$readCommitted;
            if (!$outerLocked) { self::$readCommitted = self::$nextReadCommitted; self::$nextReadCommitted = false; }
            // A completed transaction's read view cannot survive into a new save transaction.
            $agreementSnapshot = \App\Models\BillingAgreement::$snapshot;
            if (!$outerLocked) \App\Models\BillingAgreement::$snapshot = null;
            if (!$outerLocked) { self::$concurrentPayments = []; self::$paymentSnapshot = null; }
            $callbacks = count(self::$afterCommit);
            $properties = \App\Models\User::$row?->props->values;
            $before = self::$tables;
            $agreements = \App\Helpers\ExtensionHelper::$agreements;
            $payments = \App\Helpers\ExtensionHelper::$payments;
            $credits = unserialize(serialize(\App\Models\Credit::$rows));
            $fees = \App\Helpers\ExtensionHelper::$fees;
            $audit = \App\Models\Audit::$rows;
            $notifications = \App\Models\Notification::$rows;
            self::$locked = true;
            ++self::$level;
            try { $result = $callback(); } catch (\Throwable $e) { self::$afterCommit = array_slice(self::$afterCommit, 0, $callbacks); if (\App\Models\User::$row && $properties !== null) \App\Models\User::$row->props->values = $properties; self::$tables = $before; \App\Helpers\ExtensionHelper::$payments = array_replace($payments, self::$concurrentPayments); \App\Models\Credit::$rows = $credits; \App\Helpers\ExtensionHelper::$agreements = array_replace($agreements, self::$concurrentAgreements); \App\Helpers\ExtensionHelper::$fees = $fees; \App\Models\Audit::$rows = $audit; \App\Models\Notification::$rows = $notifications; throw $e; }
            finally { self::$readCommitted = $previousIsolation; --self::$level; self::$locked = $outerLocked; self::$userLocked = $priorUserLock; self::$gatewayLocked = $priorGatewayLock; if (!$outerLocked) \App\Models\BillingAgreement::$snapshot = $agreementSnapshot; }
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
        private $timeoutSeconds = null;
        public static function withHeaders($headers) { $http = new self; $http->headers = $headers; return $http; }
        public function asForm() { return $this; }
        public function timeout($seconds) { $this->timeoutSeconds = $seconds; return $this; }
        public function connectTimeout($seconds) { return $this; }
        public function __call($verb, $args) {
            [$url, $data] = $args;
            $path = substr($url, strlen('https://api.stripe.com/v1'));
            self::$calls[] = [$verb, $path, $data, $this->headers, $this->timeoutSeconds];
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
    $GLOBALS['fakeConfig']['cache.default'] = 'database';
    $GLOBALS['fakeConfig']['cache.stores.database.driver'] = 'database';
    function failedPaymentRowForKey(string $key): ?array {
        [$invoice, $id] = explode(':', $key, 2);
        return failedPaymentRow((int) $invoice, $id);
    }
    function failedPaymentRow(int $invoice, string $id): ?array {
        $rows = array_filter(\App\Helpers\ExtensionHelper::$payments, function ($row, $key) use ($invoice, $id) {
            return ($row['status'] ?? null) === 'failed' && ($key === $invoice . ':' . $id || str_starts_with($key, $invoice . ':' . $id . ':'));
        }, ARRAY_FILTER_USE_BOTH);
        return count($rows) === 1 ? array_values($rows)[0] : null;
    }
    class OutboxQuery {
        private array $filters = [], $order = [];
        private int $maximum = PHP_INT_MAX;
        public function where($key, $op, $value = null) {
            if (func_num_args() === 2) { $value = $op; $op = '='; }
            $this->filters[] = [$key, $op, $value]; return $this;
        }
        public function whereIn($key, $values) { return $this->where($key, 'in', $values); }
        public function orderBy($key) { $this->order[] = $key; return $this; }
        public function limit($n) { $this->maximum = $n; return $this; }
        public function lockForUpdate() {
            $db = \Illuminate\Support\Facades\DB::class;
            if (!$db::$locked || $db::$userLocked || $db::$gatewayLocked || count($this->filters) !== 1 || $this->filters[0][0] !== 'id') {
                throw new RuntimeException('Notice delivery locks only its outbox primary key');
            }
            return $this;
        }
        public function get() {
            $rows = array_filter(\Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'] ?? [], function ($row) {
                foreach ($this->filters as [$key, $op, $value]) {
                    if (!match ($op) { '=' => $row[$key] === $value, '<=' => $row[$key] <= $value, 'in' => in_array($row[$key], $value, true) }) return false;
                }
                return true;
            });
            usort($rows, function ($a, $b) { foreach ($this->order as $key) if ($a[$key] !== $b[$key]) return $a[$key] <=> $b[$key]; return 0; });
            return array_map(fn ($row) => (object) $row, array_slice($rows, 0, $this->maximum));
        }
        public function first() { return $this->get()[0] ?? null; }
        public function insertOrIgnore($row) {
            foreach (\Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'] ?? [] as $stored) {
                if ($stored['kind'] === $row['kind'] && $stored['source'] === $row['source'] && $stored['recipient_id'] === $row['recipient_id']) return 0;
            }
            $row['id'] = count(\Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'] ?? []) + 1;
            \Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'][$row['id']] = $row;
            return 1;
        }
        public function update($values) { foreach ($this->get() as $row) \Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'][$row->id] = array_replace((array) $row, $values); }
    }
    function pendingOutbox(): array { return array_filter(\Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'] ?? [], fn ($row) => $row['state'] === 'pending'); }
    function dueOutbox(): void { foreach (\Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'] ?? [] as $id => $row) \Illuminate\Support\Facades\DB::$tables['ae_stripe_outbox'][$id]['next_at'] = 0; }
    class SettlementQuery {
        public function insertOrIgnore(array $row) {
            $key = $row['payment_intent_id'];
            if (isset(\Illuminate\Support\Facades\DB::$tables['ae_stripe_settlements'][$key])) return 0;
            \Illuminate\Support\Facades\DB::$tables['ae_stripe_settlements'][$key] = $row;
            return 1;
        }
    }
    class ExtensionLockQuery {
        private ?string $scope = null;
        public function insertOrIgnore(array $row) {
            $key = $row['scope'];
            if (isset(\Illuminate\Support\Facades\DB::$tables['ae_stripe_locks'][$key])) return 0;
            \Illuminate\Support\Facades\DB::$tables['ae_stripe_locks'][$key] = $row;
            return 1;
        }
        public function where($key, $value) {
            if ($key !== 'scope') throw new RuntimeException('Lock query needs its primary key');
            $this->scope = $value; return $this;
        }
        public function lockForUpdate() {
            $db = \Illuminate\Support\Facades\DB::class;
            if (!$db::$locked || !$this->scope || !isset($db::$tables['ae_stripe_locks'][$this->scope])) throw new RuntimeException('Lock row must exist before locking');
            if (str_starts_with($this->scope, 'user:')) {
                if ($hook = $db::$beforeUserLock) {
                    $db::$beforeUserLock = null;
                    $before = \App\Helpers\ExtensionHelper::$payments;
                    $hook();
                    foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) if (($before[$key] ?? null) !== $row) $db::$concurrentPayments[$key] = $row;
                }
                $db::$userLocked = true;
            } else $db::$gatewayLocked = true;
            $db::$modelLocks[] = 'ae_stripe_locks:' . $this->scope;
            return $this;
        }
        public function firstOrFail() { return (object) \Illuminate\Support\Facades\DB::$tables['ae_stripe_locks'][$this->scope]; }
    }
    class TransactionClassificationQuery {
        private $id;
        public function where($key, $value) {
            if ($key !== 'id') throw new \RuntimeException('Unexpected transaction classification filter');
            $this->id = $value; return $this;
        }
        public function update($values) {
            if (!\Illuminate\Support\Facades\DB::$locked || !\Illuminate\Support\Facades\DB::$userLocked) throw new \RuntimeException('Classification requires payment locks');
            foreach (\App\Helpers\ExtensionHelper::$payments as $key => $row) {
                if (crc32($key) === $this->id) {
                    \App\Helpers\ExtensionHelper::$payments[$key] = array_replace($row, $values); return 1;
                }
            }
            return 0;
        }
    }
    class TransactionMethodQuery {
        private $id;
        public function where($key, $value) { $this->id = $value; return $this; }
        public function exists() { return isset(\Illuminate\Support\Facades\DB::$tables['ae_stripe_transaction_methods'][$this->id]); }
        public function first() { $row = \Illuminate\Support\Facades\DB::$tables['ae_stripe_transaction_methods'][$this->id] ?? null; return $row ? (object) ($row + ['failure_reason' => null]) : null; }
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
    function config($key, $default = null) { return $GLOBALS['fakeConfig'][$key] ?? $default; }
    function now() { return new class { public function format($format) { return (new \DateTimeImmutable($GLOBALS['fakeToday'] ?? '2026-10-31'))->format($format); } public $timestamp = 1700000000; public function startOfDay() { return $this; } }; }
    function base_path($path) { return ($GLOBALS['paymenterBase'] ?? dirname(__DIR__)) . '/' . (isset($GLOBALS['paymenterBase']) ? $path : str_replace('extensions/Gateways/', 'extension/', $path)); }
    function storage_path($path) { return $GLOBALS['adoptionStorage'] . '/' . $path; }
    function app($class = null) { if ($class) return new $class; return new class { public function bound($class) { return class_exists($class); } public function runningInConsole() { return $GLOBALS['fakeConsole'] ?? true; } public function booted($callback) { $callback(); } }; }
    function abort($status, $message = '') { throw new \RuntimeException('HTTP ' . $status); }
    function abort_unless($condition, $status) { if (!$condition) throw new \RuntimeException('HTTP ' . $status); }
    function __($key, $values = []) { return $key; }
    function view($name, $data) { return compact('name', 'data'); }
    function url($path) { return 'https://paymenter.example.com' . $path; }
    function route($name, $model = null) { return url(\Illuminate\Support\Facades\Route::$names[$name] ?? '/invoices/' . ($model->id ?? '')); }
    function redirect() { return new class { public function to($url) { return $url; } public function route($name) { return $this; } public function with($key, $value) { return $value; } }; }
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
            // Under the owner lock a plain read finds the fee row; only a row it found is then locked.
            if (!\Illuminate\Support\Facades\DB::$locked) throw new \RuntimeException('Fee current read lock missing');
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
        public function __construct(private ?string $table = null) {}
        public function __call($method, $args) {
            if ($method === 'primary') self::$primary[] = $GLOBALS['feeColumn']; else $GLOBALS['feeColumn'] = $args[0] ?? null;
            if ($this->table && $method === 'unsignedBigInteger') \Illuminate\Support\Facades\Schema::$columns[$this->table][] = $args[0];
            return $this;
        }
    }
}
namespace Illuminate\Support\Facades {
    class Schema {
        public static $checks = 0, $beforeCheck = null, $beforeCreate = null, $afterRename = null, $afterColumnRename = null;
        public static function rename($from, $to) { if (DB::$locked) throw new \RuntimeException('MySQL DDL ends the active transaction'); DB::$tables[$to] = DB::$tables[$from]; unset(DB::$tables[$from]); if ($hook = self::$afterRename) $hook(); }
        public static function table($table, $callback) { if (DB::$locked) throw new \RuntimeException('MySQL DDL ends the active transaction'); $callback(new class($table) extends \Illuminate\Database\Schema\Blueprint {
            public function __construct(private $table) { parent::__construct($table); }
            public function renameColumn($from, $to) { foreach (DB::$tables[$this->table] as &$row) { $row[$to] = $row[$from]; unset($row[$from]); } if ($hook = Schema::$afterColumnRename) $hook(); }
        }); }
        public static $foreignTables = [], $inspectedTables = [], $columns = [];
        public static function hasColumn($table, $column) { return in_array($column, self::$columns[$table] ?? []) || in_array($column, self::getColumnListing($table)); }
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
        public static function create($name, $callback) { if ($hook = self::$beforeCreate) $hook(); if (isset(DB::$tables[$name])) throw new \RuntimeException('Table exists'); DB::$tables[$name] = []; $callback(new \Illuminate\Database\Schema\Blueprint($name)); }
        public static function dropIfExists($name) { unset(DB::$tables[$name], self::$columns[$name]); }
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

namespace {
    class ReceiptReturnQuery {
        private $filters = [], $order = [], $limit = PHP_INT_MAX;
        public function where($key, $value) { return $this->whereIn($key, [$value]); }
        public function whereIn($key, $values) { $this->filters[$key] = $values; return $this; }
        public function orderBy($key) { $this->order[] = $key; return $this; }
        public function limit($value) { $this->limit = $value; return $this; }
        public function lockForUpdate() { if (!\Illuminate\Support\Facades\DB::$locked) throw new RuntimeException('Return read needs transaction'); return $this; }
        public function get() {
            $rows = [];
            foreach (\Illuminate\Support\Facades\DB::$tables['ae_stripe_receipt_returns'] ?? [] as $row) {
                foreach ($this->filters as $key => $values) if (!in_array($row[$key], $values)) continue 2;
                $rows[] = (object) $row;
            }
            usort($rows, function ($a, $b) { foreach ($this->order as $key) if ($a->$key !== $b->$key) return $a->$key <=> $b->$key; return 0; });
            return array_slice($rows, 0, $this->limit);
        }
        public function first() { return $this->get()[0] ?? null; }
        public function updateOrInsert($identity, $values) {
            if (!\Illuminate\Support\Facades\DB::$userLocked) throw new RuntimeException('Return write needs owner lock');
            $row = $identity + $values;
            if (strlen($row['return_key']) > 74) throw new \Illuminate\Database\QueryException('Data too long for column return_key');
            \Illuminate\Support\Facades\DB::$tables['ae_stripe_receipt_returns'][$row['payment_intent_id'] . ':' . $row['allocation_key']] = $row;
        }
        public function update($values) {
            foreach ($this->get() as $row) \Illuminate\Support\Facades\DB::$tables['ae_stripe_receipt_returns'][$row->payment_intent_id . ':' . $row->allocation_key] = array_replace((array) $row, $values);
        }
    }
}

namespace Illuminate\Contracts\Cache { class LockTimeoutException extends \RuntimeException {} }
