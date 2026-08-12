<?php
declare(strict_types=1);

if (!class_exists('Atomic_Persistent_Data', false)) {
    final class Atomic_Persistent_Data implements Iterator
    {
        private array $data = [];
        private array $keys = [];
        private int $position = 0;

        public function __construct()
        {
            $root = $this->documentRoot();
            if ($root === '') {
                return;
            }
            $raw = is_file($root . '/.atomic-persistent-data.json')
                ? file_get_contents($root . '/.atomic-persistent-data.json')
                : false;
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                return;
            }
            foreach ($decoded as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $this->data[$key] = $value;
                }
            }
            $this->keys = array_keys($this->data);
        }

        public function __isset(string $key): bool
        {
            return array_key_exists($key, $this->data);
        }

        public function __get(string $key): ?string
        {
            return $this->data[$key] ?? null;
        }

        #[\ReturnTypeWillChange]
        public function current()
        {
            return $this->__get($this->keys[$this->position] ?? '');
        }

        #[\ReturnTypeWillChange]
        public function key()
        {
            return $this->keys[$this->position] ?? null;
        }

        #[\ReturnTypeWillChange]
        public function next()
        {
            ++$this->position;
        }

        #[\ReturnTypeWillChange]
        public function rewind()
        {
            $this->position = 0;
        }

        #[\ReturnTypeWillChange]
        public function valid()
        {
            return isset($this->keys[$this->position]);
        }

        private function documentRoot(): string
        {
            $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
                ? rtrim($_SERVER['DOCUMENT_ROOT'], '/')
                : '';
            if ($documentRoot !== '') {
                return $documentRoot;
            }
            $cwd = getcwd();
            return is_string($cwd) ? $cwd : '';
        }
    }
}
