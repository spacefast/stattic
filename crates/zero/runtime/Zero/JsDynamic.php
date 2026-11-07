<?php
declare(strict_types=1);
namespace Zero;

/** Checked operations for imported JavaScript; authored TypeScript keeps its static contracts. */
trait JsDynamic
{
    private const DYNAMIC_ARRAY_METHODS = ['push', 'unshift', 'concat', 'pop', 'shift', 'reverse', 'toReversed', 'flat', 'map', 'filter', 'forEach', 'some', 'every', 'find', 'findIndex', 'findLast', 'findLastIndex', 'flatMap', 'reduce', 'sort', 'toSorted', 'slice', 'at', 'indexOf', 'lastIndexOf', 'includes', 'join', 'splice'];
    private const DYNAMIC_STRING_METHODS = ['toUpperCase', 'toLowerCase', 'trim', 'trimStart', 'trimEnd', 'charAt', 'charCodeAt', 'slice', 'substring', 'includes', 'startsWith', 'endsWith', 'indexOf', 'lastIndexOf', 'split', 'replace', 'replaceAll', 'match', 'repeat', 'concat'];
    private const DYNAMIC_MATH_METHODS = ['abs', 'floor', 'ceil', 'trunc', 'round', 'min', 'max', 'pow', 'sqrt', 'sign'];
    private static array $dynamicNamespaces = [];
    private static array $dynamicMethods = [];
    private static ?\WeakMap $dynamicMethodReceivers = null;

    public static function dynamicNamespace(string $name): JsNamespace
    {
        if ($name !== 'Math') { throw new JsError('Unsupported JavaScript namespace ' . $name, 'TypeError'); }
        return self::$dynamicNamespaces[$name] ??= new JsNamespace($name);
    }

    private static function dynamicMethodValue(mixed $receiver, string $name): mixed
    {
        $owner = match (true) {
            $receiver instanceof JsNamespace => $receiver->name,
            $receiver instanceof JsArray => 'Array', is_string($receiver) => 'String',
            $receiver instanceof RegExp => 'RegExp', $receiver instanceof JsDate => 'Date',
            $receiver instanceof JsSet => 'Set', $receiver instanceof JsMap => 'Map',
            is_int($receiver), is_float($receiver) => 'Number', default => 'Object',
        };
        $methods = match ($owner) {
            'Math' => self::DYNAMIC_MATH_METHODS, 'Array' => self::DYNAMIC_ARRAY_METHODS, 'String' => self::DYNAMIC_STRING_METHODS,
            'RegExp' => ['exec', 'test'], 'Date' => ['getTime', 'toISOString', 'toJSON'], 'Set' => ['add', 'has'], 'Map' => ['set', 'get', 'has'], default => [],
        };
        if (!in_array($name, [...$methods, 'toString', 'valueOf'], true)) { return JsUndefined::Value; }
        $key = $owner . ':' . $name;
        if (!isset(self::$dynamicMethods[$key])) {
            $invoke = match (true) {
                $owner === 'Array' && $name === 'slice' => self::dynamicBorrowedSlice(...),
                $owner === 'Math' && in_array($name, $methods, true) => static fn ($thisArg, JsArray $args) => self::dynamicStaticCall($owner, $name, $args),
                default => static fn ($thisArg, JsArray $args) => self::dynamicCallMethod($thisArg, $name, $args, true),
            };
            $function = static fn (...$args) => $invoke(JsUndefined::Value, new JsArray($args));
            self::$dynamicMethods[$key] = $function;
            self::$dynamicMethodReceivers ??= new \WeakMap();
            self::$dynamicMethodReceivers[$function] = $invoke;
        }
        return self::$dynamicMethods[$key];
    }

    /** Array.prototype.slice borrowed by dense arrays or ordinary arguments. */
    private static function dynamicBorrowedSlice(mixed $receiver, JsArray $args): JsArray
    {
        self::requirePresent($receiver);
        if (!$receiver instanceof JsArray && !$receiver instanceof JsArguments) {
            throw new JsError('Borrowed Array.slice supports arrays and arguments only', 'TypeError');
        }
        $length = $receiver instanceof JsArray ? count($receiver->a)
            : (int) min(9007199254740991, max(0, self::toInteger(self::toNumber($receiver->length))));
        $start = array_key_exists(0, $args->a) ? $args->a[0] : JsUndefined::Value;
        $end = array_key_exists(1, $args->a) ? $args->a[1] : JsUndefined::Value;
        $from = self::relIndex(self::toNumber($start), $length);
        $to = $end === JsUndefined::Value ? $length : self::relIndex(self::toNumber($end), $length);
        $result = new JsArray();
        for ($i = $from; $i < $to; $i++) {
            if (!array_key_exists($i, $receiver->a)) {
                throw new JsError('Sparse borrowed Array.slice is not supported', 'TypeError');
            }
            $result->a[] = self::dynamicGet($receiver, (string) $i);
        }
        return $result;
    }

    public static function undefined(): JsUndefined { return JsUndefined::Value; }
    public static function isNullish(mixed $value): bool { return $value === null || $value === JsUndefined::Value; }
    public static function requirePresent(mixed $value): mixed
    {
        if (self::isNullish($value)) { throw new JsError('Cannot read properties of null or undefined', 'TypeError'); }
        return $value;
    }
    public static function arguments(array $values): JsArguments { return new JsArguments($values); }

    public static function dynamicGet(mixed $value, mixed $key): mixed
    {
        if (self::isNullish($value)) { throw new JsError('Cannot read properties of null or undefined', 'TypeError'); }
        $key = self::key($key);
        if ($value instanceof JsObject) { return array_key_exists($key, $value->p) ? $value->p[$key] : self::dynamicMethodValue($value, $key); }
        if ($value instanceof JsClass) { return $value->get($key); }
        if ($value instanceof JsNamespace && $value->name === 'Math') {
            return match ($key) { 'PI' => M_PI, 'E' => M_E, default => self::dynamicMethodValue($value, $key) };
        }
        if ($value instanceof JsError) { return match ($key) { 'name' => $value->jsName, 'message' => $value->getMessage(), default => self::dynamicMethodValue($value, $key) }; }
        if ($value instanceof JsArray || $value instanceof JsArguments) {
            if ($value instanceof JsArguments && $key === 'callee') { throw new JsError('Restricted arguments property', 'TypeError'); }
            if ($key === 'length') { return $value instanceof JsArguments ? $value->length : count($value->a); }
            if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $key) && (float) $key < 4294967295) {
                return array_key_exists((int) $key, $value->a) ? $value->a[(int) $key] : JsUndefined::Value;
            }
            return array_key_exists($key, $value->p) ? $value->p[$key] : self::dynamicMethodValue($value, $key);
        }
        if (is_string($value)) {
            if ($key === 'length') { return self::length($value); }
            if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $key)) { return self::strIndex($value, (float) $key) ?? JsUndefined::Value; }
        }
        if ($value instanceof RegExp) {
            return match ($key) {
                'source' => $value->source, 'flags' => $value->flags, 'global' => $value->global,
                'ignoreCase' => str_contains($value->flags, 'i'), 'multiline' => str_contains($value->flags, 'm'),
                'unicode' => str_contains($value->flags, 'u'), 'dotAll' => str_contains($value->flags, 's'),
                'sticky' => false, 'lastIndex' => $value->lastIndex, default => self::dynamicMethodValue($value, $key),
            };
        }
        if ($key === 'size' && ($value instanceof JsSet || $value instanceof JsMap)) {
            return count($value instanceof JsSet ? $value->values : $value->entries);
        }
        return self::dynamicMethodValue($value, $key);
    }

    public static function dynamicSet(mixed $target, mixed $key, mixed $value): mixed
    {
        if (self::isNullish($target)) { throw new JsError('Cannot set properties of null or undefined', 'TypeError'); }
        $key = self::key($key);
        if ($target instanceof JsObject || $target instanceof JsClass) { return self::set($target, $key, $value); }
        if ($target instanceof RegExp && $key === 'lastIndex') { $target->lastIndex = $value; return $value; }
        if ($target instanceof JsArray || $target instanceof JsArguments) {
            if ($key === 'length') {
                if ($target instanceof JsArguments) { $target->length = $value; return $value; }
                $length = self::toNumber($value);
                if (!is_finite((float) $length) || floor($length) !== $length || $length < 0 || $length > 4294967295) { throw new JsError('Invalid array length', 'RangeError'); }
                if ($length > count($target->a)) { throw new JsError('Zero arrays are dense: length cannot create holes', 'RangeError'); }
                $target->a = array_slice($target->a, 0, (int) $length);
                return $value;
            }
            if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $key) && (float) $key < 4294967295) {
                $index = (int) $key;
                if ($target instanceof JsArray) { return self::setAt($target, $index, $value); }
                $target->a[$index] = $value;
            } else { $target->p[$key] = $value; }
            return $value;
        }
        throw new JsError('Cannot create property on a primitive value', 'TypeError');
    }

    public static function dynamicKeys(mixed $value): JsArray
    {
        if (self::isNullish($value)) { return new JsArray(); }
        if ($value instanceof JsObject) { return new JsArray(array_map('strval', self::ownKeys($value->p))); }
        if ($value instanceof JsArray || $value instanceof JsArguments) {
            $properties = $value->a;
            foreach ($value->p as $key => $item) { $properties[$key] = $item; }
            return new JsArray(array_map('strval', self::ownKeys($properties)));
        }
        if (is_string($value)) { $length = self::length($value); return new JsArray($length === 0 ? [] : array_map('strval', range(0, $length - 1))); }
        if ($value instanceof RegExp || $value instanceof JsSet || $value instanceof JsMap || $value instanceof JsDate || $value instanceof JsNamespace || $value instanceof JsError || is_scalar($value) || $value instanceof \Closure) { return new JsArray(); }
        throw new JsError('Unsupported object enumeration', 'TypeError');
    }

    public static function dynamicIterable(mixed $value): JsArray
    {
        if ($value instanceof JsArray) { return new JsArray($value->a); }
        if ($value instanceof JsArguments) {
            $result = new JsArray();
            $length = max(0, self::toInteger(self::toNumber($value->length)));
            for ($i = 0; $i < $length; $i++) { $result->a[] = self::dynamicGet($value, (string) $i); }
            return $result;
        }
        if (is_string($value)) { return self::from($value); }
        if ($value instanceof JsSet) { return new JsArray($value->values); }
        if ($value instanceof JsMap) { return new JsArray(array_map(static fn ($entry) => new JsArray($entry), $value->entries)); }
        throw new JsError('Value is not iterable', 'TypeError');
    }

    public static function dynamicObjectTag(mixed $value): string
    {
        $tag = match (true) {
            $value === JsUndefined::Value => 'Undefined', $value === null => 'Null',
            is_bool($value) => 'Boolean', is_int($value), is_float($value) => 'Number', is_string($value) => 'String',
            $value instanceof JsArray => 'Array', $value instanceof JsArguments => 'Arguments', $value instanceof JsSet => 'Set',
            $value instanceof JsMap => 'Map', $value instanceof JsDate => 'Date', $value instanceof RegExp => 'RegExp',
            $value instanceof JsNamespace => $value->name,
            $value instanceof JsError => 'Error',
            $value instanceof \Closure, $value instanceof JsClass => 'Function', default => 'Object',
        };
        return '[object ' . $tag . ']';
    }

    public static function dynamicCall(mixed $function, JsArray $args): mixed
    {
        if (!$function instanceof \Closure) { throw new JsError('Value is not a function', 'TypeError'); }
        return $function(...$args->a);
    }

    /** Resolve the callee before evaluating arguments, including an own method override. */
    public static function dynamicMethod(mixed $receiver, mixed $name): \Closure
    {
        self::requirePresent($receiver);
        $name = self::key($name);
        $properties = match (true) {
            $receiver instanceof JsObject, $receiver instanceof JsArray, $receiver instanceof JsArguments => $receiver->p,
            default => [],
        };
        if (array_key_exists($name, $properties)) {
            $method = $properties[$name];
            return static fn (...$args) => self::dynamicCall($method, new JsArray($args));
        }
        return static fn (...$args) => self::dynamicCallMethod($receiver, $name, new JsArray($args), true);
    }

    public static function dynamicCallMethod(mixed $receiver, string $name, JsArray $args, bool $builtin = false): mixed
    {
        if (self::isNullish($receiver)) { throw new JsError('Cannot read properties of null or undefined', 'TypeError'); }
        $a = $args->a;
        $get = static fn (int $index, mixed $default = JsUndefined::Value): mixed => array_key_exists($index, $a) ? $a[$index] : $default;
        if (!$builtin && $receiver instanceof JsObject && array_key_exists($name, $receiver->p)) { return self::dynamicCall($receiver->p[$name], $args); }
        if (!$builtin && ($receiver instanceof JsArray || $receiver instanceof JsArguments) && array_key_exists($name, $receiver->p)) { return self::dynamicCall($receiver->p[$name], $args); }
        if ($receiver instanceof JsNamespace && in_array($name, self::DYNAMIC_MATH_METHODS, true)) { return self::dynamicStaticCall($receiver->name, $name, $args); }
        if ($name === 'toString') {
            if ((is_int($receiver) || is_float($receiver)) && $get(0) !== JsUndefined::Value && self::toInteger(self::toNumber($get(0))) !== 10) { throw new JsError('Number.toString radix is not supported', 'TypeError'); }
            if ($receiver instanceof JsArray) { return self::join($receiver, ','); }
            if ($receiver instanceof JsArguments || $receiver instanceof JsObject || $receiver instanceof JsSet || $receiver instanceof JsMap || $receiver instanceof JsNamespace) { return self::dynamicObjectTag($receiver); }
            return self::str($receiver);
        }
        if ($name === 'valueOf') { return $receiver instanceof JsDate ? $receiver->milliseconds : $receiver; }
        if ($receiver instanceof \Closure && ($name === 'call' || $name === 'apply')) {
            $values = $name === 'call' ? array_slice($a, 1) : (self::isNullish($get(1)) ? [] : self::dynamicArrayFrom($get(1))->a);
            if (self::$dynamicMethodReceivers !== null && isset(self::$dynamicMethodReceivers[$receiver])) { return (self::$dynamicMethodReceivers[$receiver])($get(0), new JsArray($values)); }
            return self::dynamicCall($receiver, new JsArray($values));
        }
        if ($receiver instanceof JsDate) {
            return match ($name) {
                'getTime' => $receiver->milliseconds, 'toISOString' => self::iso($receiver->milliseconds),
                'toJSON' => is_finite((float) $receiver->milliseconds) ? self::iso($receiver->milliseconds) : null,
                default => throw new JsError($name . ' is not a function', 'TypeError'),
            };
        }
        if ($receiver instanceof JsSet) {
            return match ($name) { 'add' => $receiver->add($get(0)), 'has' => $receiver->has($get(0)), default => throw new JsError($name . ' is not a function', 'TypeError') };
        }
        if ($receiver instanceof JsMap) {
            return match ($name) { 'set' => $receiver->set($get(0), $get(1)), 'get' => $receiver->get($get(0)), 'has' => $receiver->has($get(0)), default => throw new JsError($name . ' is not a function', 'TypeError') };
        }
        if ($receiver instanceof RegExp) {
            return match ($name) { 'exec' => self::dynamicRegExpExec($receiver, self::str($get(0))), 'test' => self::dynamicRegExpExec($receiver, self::str($get(0))) !== null, default => throw new JsError($name . ' is not a function', 'TypeError') };
        }
        if (is_string($receiver)) {
            if (in_array($name, ['includes', 'startsWith', 'endsWith'], true) && $get(0) instanceof RegExp) { throw new JsError('Search argument must not be a regular expression', 'TypeError'); }
            return match ($name) {
                'toUpperCase' => mb_strtoupper($receiver, 'UTF-8'), 'toLowerCase' => mb_strtolower($receiver, 'UTF-8'),
                'trim' => self::trim($receiver), 'trimStart' => self::trimStart($receiver), 'trimEnd' => self::trimEnd($receiver),
                'charAt' => self::charAt($receiver, self::toNumber($get(0, 0))),
                'charCodeAt' => self::dynamicCharCodeAt($receiver, self::toNumber($get(0, 0))),
                'slice' => self::slice($receiver, self::toNumber($get(0, 0)), $get(1) === JsUndefined::Value ? null : self::toNumber($get(1))),
                'substring' => self::substring($receiver, self::toNumber($get(0, 0)), $get(1) === JsUndefined::Value ? null : self::toNumber($get(1))),
                'includes' => str_contains(self::slice($receiver, max(0, self::toNumber($get(1, 0)))), self::str($get(0))),
                'startsWith' => str_starts_with(self::slice($receiver, max(0, self::toNumber($get(1, 0)))), self::str($get(0))),
                'endsWith' => str_ends_with($get(1) === JsUndefined::Value ? $receiver : self::slice($receiver, 0, max(0, self::toNumber($get(1)))), self::str($get(0))),
                'indexOf' => self::indexOf($receiver, self::str($get(0)), self::toNumber($get(1, 0))),
                'lastIndexOf' => count($a) < 2 ? self::lastIndexOf($receiver, self::str($get(0))) : self::lastIndexOf($receiver, self::str($get(0)), self::toNumber($get(1))),
                'split' => self::dynamicSplit($receiver, $get(0), $get(1)),
                'replace', 'replaceAll' => self::{$name}($receiver, $get(0) instanceof RegExp ? $get(0) : self::str($get(0)), $get(1) instanceof \Closure ? $get(1) : self::str($get(1)), true),
                'match' => self::dynamicStringMatch($receiver, $get(0) instanceof RegExp ? $get(0) : new RegExp($get(0) === JsUndefined::Value ? '' : self::str($get(0)))),
                'repeat' => self::repeat($receiver, self::toNumber($get(0, 0))),
                'concat' => $receiver . implode('', array_map(self::str(...), $a)),
                default => throw new JsError($name . ' is not a function', 'TypeError'),
            };
        }
        if ($receiver instanceof JsArray) {
            return match ($name) {
                'push', 'unshift', 'concat' => self::{$name}($receiver, ...$a),
                'pop', 'shift' => $receiver->a === [] ? JsUndefined::Value : self::{$name}($receiver),
                'reverse', 'toReversed', 'flat' => self::{$name}($receiver, ...$a),
                'find', 'findLast' => self::dynamicArrayFind($receiver, $name, self::dynamicCallback($get(0))),
                'forEach' => self::dynamicForEach($receiver, self::dynamicCallback($get(0))),
                'map', 'filter', 'some', 'every', 'findIndex', 'findLastIndex', 'flatMap' => self::{$name}($receiver, self::dynamicCallback($get(0))),
                'reduce' => count($a) < 2 ? self::reduce($receiver, self::dynamicCallback($get(0))) : self::reduce($receiver, self::dynamicCallback($get(0)), $a[1]),
                'sort', 'toSorted' => self::{$name}($receiver, $get(0) === JsUndefined::Value ? null : self::dynamicCallback($get(0))),
                'slice' => self::arrSlice($receiver, self::toNumber($get(0, 0)), $get(1) === JsUndefined::Value ? null : self::toNumber($get(1))),
                'at' => self::dynamicArrayAt($receiver, self::toNumber($get(0, 0))),
                'indexOf', 'lastIndexOf', 'includes' => self::{$name}($receiver, $get(0), ...array_slice($a, 1, 1)),
                'join' => self::join($receiver, $get(0) === JsUndefined::Value ? ',' : self::str($get(0))),
                'splice' => count($a) < 2 ? self::splice($receiver, self::toNumber($get(0, 0))) : self::splice($receiver, self::toNumber($a[0]), self::toNumber($a[1]), ...array_slice($a, 2)),
                default => throw new JsError($name . ' is not a function', 'TypeError'),
            };
        }
        throw new JsError($name . ' is not a function', 'TypeError');
    }

    private static function dynamicCallback(mixed $callback): \Closure
    {
        if (!$callback instanceof \Closure) { throw new JsError('Callback is not a function', 'TypeError'); }
        return $callback;
    }

    private static function dynamicArrayAt(JsArray $source, int|float $index): mixed
    {
        $index = self::toInteger($index);
        if ($index < 0) { $index += count($source->a); }
        return $index >= 0 && $index < count($source->a) ? $source->a[(int) $index] : JsUndefined::Value;
    }

    private static function dynamicArrayFind(JsArray $source, string $method, \Closure $callback): mixed
    {
        $matched = JsUndefined::Value;
        $visit = static function ($value, $index, $array) use ($callback, &$matched): bool {
            if (!self::truthy($callback($value, $index, $array))) { return false; }
            $matched = $value;
            return true;
        };
        if ($method === 'find') { self::findIndex($source, $visit); } else { self::findLastIndex($source, $visit); }
        return $matched;
    }

    private static function dynamicForEach(JsArray $source, \Closure $callback): JsUndefined
    {
        self::forEach($source, $callback);
        return JsUndefined::Value;
    }

    private static function dynamicArrayFrom(mixed $source, mixed $mapper = JsUndefined::Value): JsArray
    {
        self::requirePresent($source);
        $callback = $mapper === JsUndefined::Value ? null : self::dynamicCallback($mapper);
        $result = new JsArray();
        if ($source instanceof JsArray) {
            for ($i = 0; $i < count($source->a); $i++) { $value = $source->a[$i]; $result->a[] = $callback === null ? $value : $callback($value, $i); }
        } elseif ($source instanceof JsSet || $source instanceof JsMap) {
            for ($i = 0; $i < count($source instanceof JsSet ? $source->values : $source->entries); $i++) {
                $value = $source instanceof JsSet ? $source->values[$i] : new JsArray($source->entries[$i]);
                $result->a[] = $callback === null ? $value : $callback($value, $i);
            }
        } elseif ($source instanceof JsArguments) {
            for ($i = 0; $i < max(0, self::toInteger(self::toNumber($source->length))); $i++) {
                $value = self::dynamicGet($source, (string) $i); $result->a[] = $callback === null ? $value : $callback($value, $i);
            }
        } elseif (is_string($source)) {
            foreach (self::from($source)->a as $i => $value) { $result->a[] = $callback === null ? $value : $callback($value, $i); }
        } else {
            $length = min(9007199254740991, max(0, self::toInteger(self::toNumber(self::dynamicGet($source, 'length')))));
            for ($i = 0; $i < $length; $i++) { $value = self::dynamicGet($source, (string) $i); $result->a[] = $callback === null ? $value : $callback($value, $i); }
        }
        return $result;
    }

    private static function dynamicSplit(string $source, mixed $separator, mixed $limit): JsArray
    {
        if ($limit !== JsUndefined::Value && self::toUint32(self::toNumber($limit)) === 0) { return new JsArray(); }
        if ($separator === JsUndefined::Value) { return new JsArray([$source]); }
        $result = self::split($source, $separator instanceof RegExp ? $separator : self::str($separator), $limit === JsUndefined::Value ? null : self::toNumber($limit));
        $result->a = array_map(static fn ($value) => $value ?? JsUndefined::Value, $result->a);
        return $result;
    }

    private static function dynamicStringMatch(string $source, RegExp $regexp): ?JsArray
    {
        if (!$regexp->global) { return self::dynamicRegExpExec($regexp, $source); }
        $regexp->lastIndex = 0;
        return self::strMatch($source, $regexp);
    }

    private static function dynamicRound(int|float $value): int|float
    {
        if (!is_finite((float) $value) || $value == 0) { return $value; }
        if ($value < 0 && $value >= -0.5) { return -0.0; }
        $floor = floor($value);
        return self::n($value - $floor < 0.5 ? $floor : $floor + 1);
    }

    public static function dynamicStaticCall(string $owner, string $method, JsArray $args): mixed
    {
        $a = $args->a;
        $get = static fn (int $i, mixed $default = JsUndefined::Value): mixed => array_key_exists($i, $a) ? $a[$i] : $default;
        if ($owner === 'Array') {
            if ($method === 'isArray') { return $get(0) instanceof JsArray; }
            if ($method === 'from') {
                if ($get(2) !== JsUndefined::Value) { throw new JsError('Array.from thisArg is not supported', 'TypeError'); }
                return self::dynamicArrayFrom($get(0), $get(1));
            }
            if ($method === 'of') { return new JsArray($a); }
        }
        if ($owner === 'Object') {
            $source = $get(0);
            if (in_array($method, ['keys', 'values', 'entries'], true)) {
                if (self::isNullish($source)) { throw new JsError('Cannot convert undefined or null to object', 'TypeError'); }
                $keys = self::dynamicKeys($source);
                if ($method === 'keys') { return $keys; }
                return new JsArray(array_map(static fn ($key) => $method === 'values' ? self::dynamicGet($source, $key) : new JsArray([$key, self::dynamicGet($source, $key)]), $keys->a));
            }
            if ($method === 'hasOwn') {
                self::requirePresent($source); $key = self::key($get(1));
                if ($key === 'length' && ($source instanceof JsArray || $source instanceof JsArguments || is_string($source))) { return true; }
                if ($key === 'callee' && $source instanceof JsArguments || $key === 'lastIndex' && $source instanceof RegExp) { return true; }
                return in_array($key, self::dynamicKeys($source)->a, true);
            }
            if ($method === 'fromEntries') {
                $result = new JsObject();
                foreach (self::dynamicIterable($source)->a as $entry) {
                    if (!$entry instanceof JsArray && !$entry instanceof JsObject) { throw new JsError('Iterator value is not an entry object', 'TypeError'); }
                    $result->p[self::key(self::dynamicGet($entry, '0'))] = self::dynamicGet($entry, '1');
                }
                return $result;
            }
        }
        if ($owner === 'Math') {
            $n = array_map(self::toNumber(...), $a);
            $x = $n[0] ?? NAN;
            return match ($method) {
                'abs' => abs($x), 'floor' => self::n(floor($x)), 'ceil' => self::n(ceil($x)), 'trunc' => self::n($x < 0 ? ceil($x) : floor($x)),
                'round' => self::dynamicRound($x),
                'min' => self::min(...$n), 'max' => self::max(...$n), 'pow' => self::pow($x, $n[1] ?? NAN),
                'sqrt' => sqrt($x), 'sign' => is_nan((float) $x) || $x == 0 ? $x : ($x < 0 ? -1 : 1),
                default => throw new JsError('Unsupported Math method ' . $method, 'TypeError'),
            };
        }
        if ($owner === 'Number') {
            return match ($method) {
                'isFinite' => self::isFinite($get(0)), 'isInteger' => self::isInteger($get(0)),
                'isNaN' => is_float($get(0)) && is_nan($get(0)),
                'parseFloat' => self::parseFloat(self::str($get(0))), 'parseInt' => self::parseInt(self::str($get(0)), $get(1) === JsUndefined::Value ? null : self::toNumber($get(1))),
                default => throw new JsError('Unsupported Number method ' . $method, 'TypeError'),
            };
        }
        if ($owner === 'JSON') {
            if ($get(1) !== JsUndefined::Value && $get(1) !== null) { throw new JsError('JSON replacer and reviver are not supported', 'TypeError'); }
            return match ($method) {
                'parse' => self::jsonParse(self::str($get(0))),
                'stringify' => self::jsonStringify($get(0), is_string($get(2)) || is_int($get(2)) || is_float($get(2)) ? $get(2) : null) ?? JsUndefined::Value,
                default => throw new JsError('Unsupported JSON method ' . $method, 'TypeError'),
            };
        }
        throw new JsError('Unsupported static method ' . $owner . '.' . $method, 'TypeError');
    }

    private static function dynamicCharCodeAt(string $value, int|float $index): int|float
    {
        $index = self::toInteger($index);
        if ($index < 0 || $index >= self::length($value)) { return NAN; }
        return unpack('v', substr(self::units($value), 2 * (int) $index, 2))[1];
    }

    public static function dynamicRegExpExec(RegExp $regexp, string $value): ?JsArray
    {
        $offset = $regexp->global ? max(0, self::toInteger(self::toNumber($regexp->lastIndex))) : 0;
        if ($offset > self::length($value)) { $regexp->lastIndex = 0; return null; }
        $byteOffset = strlen(self::slice($value, 0, $offset));
        $match = $regexp->matchAt($value, $byteOffset);
        if ($match === null) { if ($regexp->global) { $regexp->lastIndex = 0; } return null; }
        [$start, $end, $captures, $groups] = $match;
        if ($regexp->global) { $regexp->lastIndex = self::length(substr($value, 0, $end)); }
        $result = new JsArray([substr($value, $start, $end - $start), ...array_map(static fn ($v) => $v ?? JsUndefined::Value, $captures)]);
        $result->p = ['index' => self::length(substr($value, 0, $start)), 'input' => $value, 'groups' => $regexp->hasNamedGroups() ? new JsObject(array_map(static fn ($v) => $v ?? JsUndefined::Value, $groups)) : JsUndefined::Value];
        return $result;
    }

    public static function dynamicConstruct(mixed $constructor, JsArray $args): mixed
    {
        $a = $args->a;
        if ($constructor instanceof JsClass) { return $constructor->construct(...$a); }
        if (in_array($constructor, ['Error', 'TypeError', 'RangeError'], true)) {
            $options = $a[1] ?? JsUndefined::Value;
            if ($options instanceof JsObject && array_key_exists('cause', $options->p)) {
                throw new JsError('Error cause is not supported', 'TypeError');
            }
            $message = array_key_exists(0, $a) ? $a[0] : JsUndefined::Value;
            return new JsError($message === JsUndefined::Value ? '' : self::str($message), $constructor);
        }
        if ($constructor === 'RegExp') {
            $source = array_key_exists(0, $a) ? $a[0] : JsUndefined::Value;
            $flags = array_key_exists(1, $a) ? $a[1] : JsUndefined::Value;
            return new RegExp($source instanceof RegExp ? $source->source : ($source === JsUndefined::Value ? '' : self::str($source)), $flags === JsUndefined::Value ? ($source instanceof RegExp ? $source->flags : '') : self::str($flags));
        }
        if ($constructor === 'Date') {
            if (count($a) > 1) { throw new JsError('Date component constructor is not supported', 'TypeError'); }
            $value = array_key_exists(0, $a) ? $a[0] : JsUndefined::Value;
            return new JsDate($a === [] ? self::now() : ($value instanceof JsDate ? $value->milliseconds : self::date($value)));
        }
        if ($constructor === 'Set' || $constructor === 'Map') {
            $result = $constructor === 'Set' ? new JsSet() : new JsMap();
            if (!self::isNullish($a[0] ?? JsUndefined::Value)) {
                foreach (self::dynamicIterable($a[0])->a as $value) {
                    if ($result instanceof JsSet) { $result->add($value); }
                    elseif ($value instanceof JsArray || $value instanceof JsObject) { $result->set(self::dynamicGet($value, '0'), self::dynamicGet($value, '1')); }
                    else { throw new JsError('Iterator value is not an entry object', 'TypeError'); }
                }
            }
            return $result;
        }
        throw new JsError('Value is not a supported constructor', 'TypeError');
    }

    public static function dynamicRegExpCall(JsArray $args): RegExp
    {
        if (($args->a[0] ?? null) instanceof RegExp && (!array_key_exists(1, $args->a) || $args->a[1] === JsUndefined::Value)) { return $args->a[0]; }
        return self::dynamicConstruct('RegExp', $args);
    }

    private static function dynamicPrimitive(mixed $value, string $hint = 'default'): mixed
    {
        if ($value instanceof JsDate) {
            if ($hint !== 'number') { throw new JsError('Date string coercion is not supported', 'TypeError'); }
            return $value->milliseconds;
        }
        if ($value instanceof JsObject) {
            foreach ($hint === 'string' ? ['toString', 'valueOf'] : ['valueOf', 'toString'] as $method) {
                if (array_key_exists($method, $value->p)) {
                    if (!$value->p[$method] instanceof \Closure) { continue; }
                    $result = self::dynamicCall($value->p[$method], new JsArray());
                } else { $result = $method === 'toString' ? '[object Object]' : $value; }
                if ($result === JsUndefined::Value || !is_object($result)) { return $result; }
            }
            throw new JsError('Cannot convert object to primitive value', 'TypeError');
        }
        if (is_object($value) && !$value instanceof JsUndefined) { return self::str($value); }
        return $value;
    }

    public static function dynamicBinary(string $operator, mixed $left, mixed $right): mixed
    {
        if ($operator === '===' || $operator === '!==') { $equal = self::strictEq($left, $right); return $operator === '===' ? $equal : !$equal; }
        if ($operator === '==' || $operator === '!=') { $equal = self::looseEq($left, $right); return $operator === '==' ? $equal : !$equal; }
        $left = self::dynamicPrimitive($left); $right = self::dynamicPrimitive($right);
        if ($operator === '+') {
            return is_string($left) || is_string($right) ? self::str($left) . self::str($right) : self::add(self::toNumber($left), self::toNumber($right));
        }
        if (in_array($operator, ['<', '<=', '>', '>='], true) && is_string($left) && is_string($right)) {
            // UTF-16LE byte order is not code-unit order.
            $a = unpack('v*', self::units($left)); $b = unpack('v*', self::units($right));
            $comparison = 0;
            for ($i = 1, $n = min(count($a), count($b)); $i <= $n; $i++) { if ($a[$i] !== $b[$i]) { $comparison = $a[$i] <=> $b[$i]; break; } }
            if ($comparison === 0) { $comparison = count($a) <=> count($b); }
            return match ($operator) { '<' => $comparison < 0, '<=' => $comparison <= 0, '>' => $comparison > 0, '>=' => $comparison >= 0 };
        }
        $a = self::toNumber($left); $b = self::toNumber($right);
        return match ($operator) {
            '-' => self::sub($a, $b), '*' => self::mul($a, $b), '/' => self::n(fdiv($a, $b)), '%' => self::n(fmod($a, $b)), '**' => self::pow($a, $b),
            '<' => $a < $b, '<=' => $a <= $b, '>' => $a > $b, '>=' => $a >= $b,
            default => throw new JsError('Unsupported JavaScript operator ' . $operator, 'TypeError'),
        };
    }

    public static function looseEq(mixed $a, mixed $b): bool
    {
        if (self::isNullish($a) || self::isNullish($b)) { return self::isNullish($a) && self::isNullish($b); }
        $ta = self::typeOf($a); $tb = self::typeOf($b);
        if ($ta === $tb) { return self::strictEq($a, $b); }
        if (is_bool($a)) { return self::looseEq((int) $a, $b); }
        if (is_bool($b)) { return self::looseEq($a, (int) $b); }
        if (($ta === 'number' && $tb === 'string') || ($ta === 'string' && $tb === 'number')) { return self::strictEq(self::toNumber($a), self::toNumber($b)); }
        if ($ta === 'object' && ($tb === 'string' || $tb === 'number')) { return self::looseEq(self::dynamicPrimitive($a), $b); }
        if ($tb === 'object' && ($ta === 'string' || $ta === 'number')) { return self::looseEq($a, self::dynamicPrimitive($b)); }
        return false;
    }
}
