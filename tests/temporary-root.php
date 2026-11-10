<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Temporary test trees: each process removes the directory it created when it exits, after a passing run, a
// failing run, an uncaught exception or an exit. Removal stays inside that directory and never follows a link.
if (!class_exists('TemporaryRoot', false)) {
    final class TemporaryRoot
    {
        private bool $removed = false;

        private function __construct(public readonly string $path) {}

        /** Create a private directory in the system temporary directory, owned by this process. */
        public static function create(string $prefix): string
        {
            $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
            if (!mkdir($path, 0700)) throw new RuntimeException('Could not create the temporary test directory ' . $path);
            $owned = new self($path);
            // Remove it after every other exit handler, including the framework's. If a handler exits first, the
            // remaining handlers are skipped and freeing this closure runs the destructor instead.
            register_shutdown_function(static function () use ($owned) { register_shutdown_function([$owned, 'remove']); });
            return $path;
        }

        public function remove(): void
        {
            if ($this->removed) return;
            $this->removed = true;
            self::removeInside($this->path, $this->path);
        }

        public function __destruct() { $this->remove(); }

        private static function removeInside(string $root, string $path): void
        {
            if ($path !== $root && !str_starts_with($path, $root . '/')) throw new RuntimeException('Refusing to remove a path outside ' . $root);
            // A link is removed itself, so its target (the cached release or another tree) is never entered.
            if (is_link($path) || is_file($path)) { unlink($path); return; }
            if (!is_dir($path)) return;
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') self::removeInside($root, $path . '/' . $entry);
            }
            rmdir($path);
        }
    }
}
