<?php
declare(strict_types=1);

// Shared by the legacy Zero v2 prefix binding and SpacePress previews: both run
// WordPress on tables of their own and must not read the site's cached rows.

/** Namespace every cache group, including WordPress's global users/options groups. */
final class Spacefast_Zero_Cache
{
    private string $generation;

    public function __construct(private object $cache, private string $scope)
    {
        $value = $cache->get('generation', $scope);
        if (!is_string($value)) {
            $cache->add('generation', bin2hex(random_bytes(16)), $scope);
            $value = $cache->get('generation', $scope);
        }
        $this->generation = is_string($value) ? $value : 'local';
    }

    private function group(string $group): string { return $this->scope . ':' . $this->generation . ':' . $group; }
    public function get(mixed $key, string $group = '', bool $force = false, mixed &$found = null): mixed { return $this->cache->get($key, $this->group($group), $force, $found); }
    public function set(mixed $key, mixed $value, string $group = '', int $expire = 0): bool { return $this->cache->set($key, $value, $this->group($group), $expire); }
    public function add(mixed $key, mixed $value, string $group = '', int $expire = 0): bool { return $this->cache->add($key, $value, $this->group($group), $expire); }
    public function replace(mixed $key, mixed $value, string $group = '', int $expire = 0): bool { return $this->cache->replace($key, $value, $this->group($group), $expire); }
    public function delete(mixed $key, string $group = '', bool $deprecated = false): bool { return $this->cache->delete($key, $this->group($group), $deprecated); }
    public function incr(mixed $key, int $offset = 1, string $group = ''): mixed { return $this->cache->incr($key, $offset, $this->group($group)); }
    public function decr(mixed $key, int $offset = 1, string $group = ''): mixed { return $this->cache->decr($key, $offset, $this->group($group)); }
    public function get_multiple(array $keys, string $group = '', bool $force = false): array { return $this->cache->get_multiple($keys, $this->group($group), $force); }
    public function set_multiple(array $data, string $group = '', int $expire = 0): array { return $this->cache->set_multiple($data, $this->group($group), $expire); }
    public function add_multiple(array $data, string $group = '', int $expire = 0): array { return $this->cache->add_multiple($data, $this->group($group), $expire); }
    public function delete_multiple(array $keys, string $group = ''): array { return $this->cache->delete_multiple($keys, $this->group($group)); }
    public function add_global_groups(array|string $groups): void { $this->cache->add_global_groups(array_map($this->group(...), (array) $groups)); }
    public function add_non_persistent_groups(array|string $groups): void { $this->cache->add_non_persistent_groups(array_map($this->group(...), (array) $groups)); }
    public function switch_to_blog(int $blog): void { throw new RuntimeException('Managed Zero requests cannot change their database binding.'); }
    public function flush_group(string $group): bool { return $this->cache->flush_group($this->group($group)); }
    public function flush_runtime(): bool { return $this->flush(); }
    public function flush(): bool
    {
        $this->generation = bin2hex(random_bytes(16));
        return $this->cache->set('generation', $this->generation, $this->scope);
    }
    public function close(): bool { return method_exists($this->cache, 'close') ? $this->cache->close() : true; }
    public function reset(): void { $this->flush(); }
    public function stats(): void { $this->cache->stats(); }
}
