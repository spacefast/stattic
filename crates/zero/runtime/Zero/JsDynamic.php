<?php
declare(strict_types=1);
namespace Zero;

/** Checked operations for imported JavaScript; authored TypeScript keeps its static contracts. */
trait JsDynamic
{
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
        if ($receiver instanceof JsObject && ($receiver->class?->hasMethod($name) ?? false)) {
            throw new JsError('Class method values are not supported by checked JavaScript', 'TypeError');
        }
        if ($receiver instanceof JsObject && $receiver->nullPrototype) { return JsUndefined::Value; }
        $owner = match (true) {
            $receiver instanceof JsNamespace => $receiver->name,
            $receiver instanceof JsArray => 'Array', is_string($receiver) => 'String',
            $receiver instanceof RegExp => 'RegExp', $receiver instanceof JsDate => 'Date',
            $receiver instanceof JsError => 'Error',
            $receiver instanceof JsSet => 'Set', $receiver instanceof JsMap => 'Map',
            $receiver instanceof \Closure => 'Function', is_bool($receiver) => 'Boolean',
            is_int($receiver), is_float($receiver) => 'Number', default => 'Object',
        };
        if ($receiver instanceof \Closure && in_array($name, ['bind', 'length', 'name', 'prototype', 'arguments', 'caller'], true)) {
            throw new JsError('Function metadata and binding are not supported', 'TypeError');
        }
        if ($name === 'valueOf' && !in_array($owner, ['Number', 'String', 'Boolean', 'Date'], true)
            || $name === 'toString' && in_array($owner, ['Math', 'Set', 'Map'], true)) { $owner = 'Object'; }
        $surface = match ($owner) { 'Math' => 'Math', 'RegExp' => 'regex', default => strtolower($owner) };
        if (!Builtins::has($surface, $name) && !in_array($name, ['toString', 'valueOf'], true)) {
            if (Builtins::has('object', $name)) { throw new JsError('Unsupported inherited method ' . $name, 'TypeError'); }
            return JsUndefined::Value;
        }
        $key = $owner . ':' . $name;
        if (!isset(self::$dynamicMethods[$key])) {
            $invoke = match (true) {
                $owner === 'Array' && $name === 'slice' => self::dynamicBorrowedSlice(...),
                $owner === 'Math' && Builtins::has('Math', $name) => static fn ($thisArg, JsArray $args) => self::dynamicStaticCall($owner, $name, $args),
                default => static fn ($thisArg, JsArray $args) => self::dynamicBorrowedMethod($owner, $thisArg, $name, $args),
            };
            $function = static fn (...$args) => $invoke(JsUndefined::Value, new JsArray($args));
            self::$dynamicMethods[$key] = $function;
            self::$dynamicMethodReceivers ??= new \WeakMap();
            self::$dynamicMethodReceivers[$function] = $invoke;
        }
        return self::$dynamicMethods[$key];
    }

    private static function dynamicBorrowedMethod(string $owner, mixed $receiver, string $name, JsArray $args): mixed
    {
        if ($owner === 'Object' && $name === 'toString') { return self::dynamicObjectTag($receiver); }
        if ($owner === 'Object' && $name === 'valueOf') {
            self::requirePresent($receiver);
            if (!is_object($receiver)) { throw new JsError('Object valueOf boxing is not supported', 'TypeError'); }
            return $receiver;
        }
        $compatible = match ($owner) {
            'Array' => $receiver instanceof JsArray, 'String' => is_string($receiver),
            'Number' => is_int($receiver) || is_float($receiver), 'Boolean' => is_bool($receiver),
            'RegExp' => $receiver instanceof RegExp, 'Date' => $receiver instanceof JsDate,
            'Error' => $receiver instanceof JsError,
            'Set' => $receiver instanceof JsSet, 'Map' => $receiver instanceof JsMap,
            'Function' => $receiver instanceof \Closure, 'Object' => $receiver instanceof JsObject,
            default => false,
        };
        if (!$compatible) { throw new JsError('Borrowing methods across builtin families is not supported', 'TypeError'); }
        return self::dynamicCallMethod($receiver, $name, $args, true);
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
        if ($value instanceof JsObject) {
            if ($key === '__proto__' && !$value->nullPrototype && !array_key_exists($key, $value->p)) { throw new JsError('Prototype access is not supported', 'TypeError'); }
            return array_key_exists($key, $value->p) ? $value->p[$key] : self::dynamicMethodValue($value, $key);
        }
        if ($value instanceof JsClass) { return $value->get($key); }
        if ($value instanceof JsNamespace && $value->name === 'Math') {
            return match ($key) {
                'PI' => M_PI, 'E' => M_E,
                default => Builtins::has('Math', $key) || in_array($key, ['toString', 'valueOf'], true)
                    ? self::dynamicMethodValue($value, $key)
                    : throw new JsError('Unsupported Math property ' . $key, 'TypeError'),
            };
        }
        if ($value instanceof JsError) { return match ($key) { 'name' => $value->jsName, 'message' => $value->getMessage(), 'stack' => throw new JsError('Error stack metadata is not supported', 'TypeError'), default => self::dynamicMethodValue($value, $key) }; }
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
                'sticky' => $value->sticky, 'lastIndex' => $value->lastIndex, 'hasIndices', 'unicodeSets' => false, default => self::dynamicMethodValue($value, $key),
            };
        }
        if ($key === 'size' && ($value instanceof JsSet || $value instanceof JsMap)) {
            return count($value instanceof JsSet ? $value->values : $value->entries);
        }
        return self::dynamicMethodValue($value, $key);
    }

    public static function dataGet(mixed $value, mixed $key): mixed
    {
        self::requirePresent($value);
        $key = self::key($key);
        if ($value instanceof JsObject && !$value->nullPrototype && !array_key_exists($key, $value->p) && Builtins::has('object', $key)) {
            throw new JsError('Inherited properties are not supported by typed data reads', 'TypeError');
        }
        return self::dynamicGet($value, $key);
    }

    public static function dynamicSet(mixed $target, mixed $key, mixed $value): mixed
    {
        if (self::isNullish($target)) { throw new JsError('Cannot set properties of null or undefined', 'TypeError'); }
        $key = self::key($key);
        if ($target instanceof JsObject || $target instanceof JsClass) {
            return self::set($target, $key, $value);
        }
        if ($target instanceof RegExp && $key === 'lastIndex') { $target->lastIndex = $value; return $value; }
        if ($target instanceof JsArray || $target instanceof JsArguments) {
            if ($key === '__proto__' && !array_key_exists($key, $target->p)) {
                throw new JsError('Prototype mutation is not supported', 'TypeError');
            }
            if ($key === 'length') {
                if ($target instanceof JsArguments) { $target->length = $value; return $value; }
                $length = self::toUint32(self::toNumber($value));
                if ((float) $length !== (float) self::toNumber($value)) { throw new JsError('Invalid array length', 'RangeError'); }
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

    public static function dynamicForIn(mixed $value): \Generator
    {
        foreach (self::dynamicKeys($value)->a as $key) {
            if (($value instanceof JsArray || $value instanceof JsArguments)
                && !array_key_exists($key, $value->a) && !array_key_exists($key, $value->p)) { continue; }
            if ($value instanceof JsObject && !array_key_exists($key, $value->p)) { continue; }
            yield $key;
        }
    }

    public static function dynamicIterable(mixed $value): JsArray
    {
        return new JsArray(iterator_to_array(self::dynamicIterator($value), false));
    }

    public static function dynamicIterator(mixed $value): \Generator
    {
        if ($value instanceof JsArray) {
            for ($i = 0; $i < count($value->a); $i++) { yield $value->a[$i]; }
        } elseif ($value instanceof JsArguments) {
            for ($i = 0; $i < max(0, self::toInteger(self::toNumber($value->length))); $i++) { yield self::dynamicGet($value, (string) $i); }
        } elseif (is_string($value)) {
            yield from self::from($value)->a;
        } elseif ($value instanceof JsSet) {
            for ($i = 0; $i < count($value->values); $i++) { yield $value->values[$i]; }
        } elseif ($value instanceof JsMap) {
            for ($i = 0; $i < count($value->entries); $i++) { yield new JsArray($value->entries[$i]); }
        } else {
            throw new JsError('Value is not iterable', 'TypeError');
        }
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

    private static function dynamicInvoke(mixed $function, mixed $receiver, JsArray $args): mixed
    {
        if ($function instanceof \Closure && self::$dynamicMethodReceivers !== null && isset(self::$dynamicMethodReceivers[$function])) {
            return (self::$dynamicMethodReceivers[$function])($receiver, $args);
        }
        return self::dynamicCall($function, $args);
    }

    public static function dataMethod(mixed $receiver, mixed $name): \Closure
    {
        $method = self::dataGet($receiver, $name);
        return static fn (...$args) => self::dynamicInvoke($method, $receiver, new JsArray($args));
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
            return static fn (...$args) => self::dynamicInvoke($method, $receiver, new JsArray($args));
        }
        if ($receiver instanceof JsObject && ($receiver->class?->hasMethod($name) ?? false)) {
            throw new JsError('Class method values are not supported by checked JavaScript', 'TypeError');
        }
        if ($receiver instanceof JsObject && $receiver->nullPrototype) {
            return static fn (...$args) => self::dynamicCall(JsUndefined::Value, new JsArray($args));
        }
        return static fn (...$args) => self::dynamicCallMethod($receiver, $name, new JsArray($args), true);
    }

    public static function dynamicCallMethod(mixed $receiver, string $name, JsArray $args, bool $builtin = false): mixed
    {
        if (self::isNullish($receiver)) { throw new JsError('Cannot read properties of null or undefined', 'TypeError'); }
        $a = $args->a;
        $get = static fn (int $index, mixed $default = JsUndefined::Value): mixed => array_key_exists($index, $a) ? $a[$index] : $default;
        if (!$builtin && $receiver instanceof JsObject && array_key_exists($name, $receiver->p)) { return self::dynamicInvoke($receiver->p[$name], $receiver, $args); }
        if (!$builtin && $receiver instanceof JsObject && $receiver->nullPrototype) { return self::dynamicCall(JsUndefined::Value, $args); }
        if (!$builtin && ($receiver instanceof JsArray || $receiver instanceof JsArguments) && array_key_exists($name, $receiver->p)) { return self::dynamicInvoke($receiver->p[$name], $receiver, $args); }
        $surface = match (true) {
            is_string($receiver) => 'string', is_int($receiver), is_float($receiver) => 'number',
            $receiver instanceof JsArray => 'array', $receiver instanceof RegExp => 'regex',
            $receiver instanceof JsDate => 'date', $receiver instanceof JsSet => 'set', $receiver instanceof JsMap => 'map',
            is_bool($receiver) => 'boolean', $receiver instanceof \Closure => 'function',
            default => 'object',
        };
        Builtins::check($surface, $name, count($a));
        if ($receiver instanceof JsNamespace && Builtins::has($receiver->name, $name)) { return self::dynamicStaticCall($receiver->name, $name, $args); }
        if ($name === 'toFixed' && (is_int($receiver) || is_float($receiver))) { return self::toFixed($receiver, $get(0, 0)); }
        if ($name === 'toString') {
            if ((is_int($receiver) || is_float($receiver)) && $get(0) !== JsUndefined::Value && self::toInteger(self::toNumber($get(0))) !== 10) { throw new JsError('Number.toString radix is not supported', 'TypeError'); }
            if ($receiver instanceof JsArray) {
                if (array_key_exists('join', $receiver->p) && !$receiver->p['join'] instanceof \Closure) { return self::dynamicObjectTag($receiver); }
                return self::dynamicCallMethod($receiver, 'join', new JsArray());
            }
            if ($receiver instanceof JsArguments || $receiver instanceof JsObject || $receiver instanceof JsSet || $receiver instanceof JsMap || $receiver instanceof JsNamespace) { return self::dynamicObjectTag($receiver); }
            return self::str($receiver);
        }
        if ($name === 'valueOf') { return $receiver instanceof JsDate ? $receiver->milliseconds : $receiver; }
        if ($receiver instanceof \Closure && ($name === 'call' || $name === 'apply')) {
            $values = $name === 'call' ? array_slice($a, 1) : self::dynamicApplyArguments($get(1));
            return self::dynamicInvoke($receiver, $get(0), new JsArray($values));
        }
        if ($receiver instanceof JsDate) {
            return match ($name) {
                'getTime' => $receiver->milliseconds, 'toISOString' => self::iso($receiver->milliseconds),
                'toJSON' => self::dateJSON($receiver->milliseconds),
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
            if ($name === 'replaceAll' && $get(0) instanceof RegExp && !$get(0)->global) { throw new JsError('replaceAll must be called with a global RegExp', 'TypeError'); }
            if (in_array($name, ['includes', 'startsWith', 'endsWith'], true) && $get(0) instanceof RegExp) { throw new JsError('Search argument must not be a regular expression', 'TypeError'); }
            return match ($name) {
                'toUpperCase' => mb_strtoupper($receiver, 'UTF-8'), 'toLowerCase' => mb_strtolower($receiver, 'UTF-8'),
                'trim' => self::trim($receiver), 'trimStart' => self::trimStart($receiver), 'trimEnd' => self::trimEnd($receiver),
                'charAt' => self::charAt($receiver, self::toNumber($get(0, 0))),
                'at' => self::strAt($receiver, self::toNumber($get(0, 0))) ?? JsUndefined::Value,
                'padStart', 'padEnd' => self::dynamicPad($receiver, $name, $get(0), $get(1)),
                'charCodeAt' => self::dynamicCharCodeAt($receiver, self::toNumber($get(0, 0))),
                'slice' => self::slice($receiver, self::toNumber($get(0, 0)), $get(1) === JsUndefined::Value ? null : self::toNumber($get(1))),
                'substring' => self::substring($receiver, self::toNumber($get(0, 0)), $get(1) === JsUndefined::Value ? null : self::toNumber($get(1))),
                'includes', 'startsWith', 'endsWith' => self::dynamicStringSearch($receiver, $name, $get(0), $get(1)),
                'indexOf' => self::indexOf($receiver, self::str($get(0)), self::toNumber($get(1, 0))),
                'lastIndexOf' => count($a) < 2 ? self::lastIndexOf($receiver, self::str($get(0))) : self::lastIndexOf($receiver, self::str($get(0)), self::toNumber($get(1))),
                'split' => self::dynamicSplit($receiver, $get(0), $get(1)),
                'replace', 'replaceAll' => self::{$name}($receiver, $get(0) instanceof RegExp ? $get(0) : self::str($get(0)), $get(1) instanceof \Closure ? $get(1) : self::str($get(1))),
                'match' => self::strMatch($receiver, $get(0) instanceof RegExp ? $get(0) : new RegExp($get(0) === JsUndefined::Value ? '' : self::str($get(0)))),
                'repeat' => self::repeat($receiver, self::toNumber($get(0, 0))),
                'concat' => $receiver . implode('', array_map(self::str(...), $a)),
                default => throw new JsError($name . ' is not a function', 'TypeError'),
            };
        }
        if ($receiver instanceof JsArray) {
            return match ($name) {
                'push', 'unshift', 'concat' => self::{$name}($receiver, ...$a),
                'pop', 'shift' => $receiver->a === [] ? JsUndefined::Value : self::{$name}($receiver),
                'reverse', 'toReversed' => self::{$name}($receiver, ...$a),
                'flat' => self::dynamicFlat($receiver, $get(0)),
                'find', 'findLast' => self::dynamicArrayFind($receiver, $name, self::dynamicCallback($get(0))),
                'forEach' => self::forEach($receiver, self::dynamicCallback($get(0))),
                'map', 'filter', 'some', 'every', 'findIndex', 'findLastIndex', 'flatMap' => self::{$name}($receiver, self::dynamicCallback($get(0))),
                'reduce' => count($a) < 2 ? self::reduce($receiver, self::dynamicCallback($get(0))) : self::reduce($receiver, self::dynamicCallback($get(0)), $a[1]),
                'sort', 'toSorted' => self::{$name}($receiver, $get(0) === JsUndefined::Value ? null : self::dynamicCallback($get(0))),
                'fill' => self::dynamicFill($receiver, $get(0), $get(1), $get(2)),
                'slice' => self::dynamicBorrowedSlice($receiver, $args),
                'at' => self::dynamicArrayAt($receiver, $get(0)),
                'indexOf', 'lastIndexOf', 'includes' => self::{$name}($receiver, $get(0), ...array_slice($a, 1, 1)),
                'join' => self::join($receiver, $get(0)),
                'splice' => self::dynamicSplice($receiver, $args),
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

    private static function dynamicPad(string $source, string $method, mixed $length, mixed $fill): string
    {
        $length = self::toInteger(self::toNumber($length));
        if ($length <= self::length($source)) { return $source; }
        return self::{$method}($source, $length, $fill === JsUndefined::Value ? ' ' : self::str($fill));
    }

    private static function dynamicArrayAt(JsArray $source, mixed $index): mixed
    {
        $length = count($source->a);
        $index = self::toInteger(self::toNumber($index));
        if ($index < 0) { $index += $length; }
        return $index >= 0 && $index < $length ? self::dynamicGet($source, (string) $index) : JsUndefined::Value;
    }

    private static function dynamicFlat(JsArray $source, mixed $depth): JsArray
    {
        $length = count($source->a);
        $depth = $depth === JsUndefined::Value ? 1 : self::toNumber($depth);
        return self::flat(new JsArray(array_slice($source->a, 0, $length)), $depth);
    }

    private static function dynamicFill(JsArray $source, mixed $value, mixed $start, mixed $end): JsArray
    {
        $length = count($source->a);
        $from = self::relIndex(self::toNumber($start), $length);
        $to = $end === JsUndefined::Value ? $length : self::relIndex(self::toNumber($end), $length);
        if ($from < $to && $from > count($source->a)) { throw new JsError('Fill cannot create a sparse array after coercion', 'TypeError'); }
        for ($i = $from; $i < $to; $i++) { $source->a[$i] = $value; }
        return $source;
    }

    private static function dynamicSplice(JsArray $source, JsArray $args): JsArray
    {
        $length = count($source->a);
        if ($args->a === []) { return new JsArray(); }
        $start = self::relIndex(self::toNumber($args->a[0]), $length);
        $count = count($args->a) < 2 ? $length - $start : self::clampIndex(self::toNumber($args->a[1]), $length - $start);
        if (count($source->a) < $length) { throw new JsError('Splice cannot read a sparse array after coercion', 'TypeError'); }
        $source->a = array_slice($source->a, 0, $length);
        return new JsArray(array_splice($source->a, $start, $count, array_slice($args->a, 2)));
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

    private static function dynamicApplyArguments(mixed $source): array
    {
        if (self::isNullish($source)) { return []; }
        if (!is_object($source) || $source instanceof \Closure) { throw new JsError('apply requires an array-like data object', 'TypeError'); }
        $length = max(0, self::toInteger(self::toNumber(self::dynamicGet($source, 'length'))));
        if ($length > 5000000) { throw new JsError('apply argument list is too large', 'RangeError'); }
        $values = [];
        for ($i = 0; $i < $length; $i++) { $values[] = self::dynamicGet($source, (string) $i); }
        return $values;
    }

    private static function dynamicStringSearch(string $source, string $method, mixed $search, mixed $position): bool
    {
        if ($search instanceof RegExp) { throw new JsError($method . ' does not accept a RegExp', 'TypeError'); }
        $search = self::str($search);
        $position = $method === 'endsWith' && $position === JsUndefined::Value
            ? self::length($source) : max(0, self::toNumber($position));
        return match ($method) {
            'includes' => str_contains(self::slice($source, $position), $search),
            'startsWith' => str_starts_with(self::slice($source, $position), $search),
            'endsWith' => str_ends_with(self::slice($source, 0, $position), $search),
        };
    }

    private static function dynamicSplit(string $source, mixed $separator, mixed $limit): JsArray
    {
        $limit = $limit === JsUndefined::Value ? null : self::toUint32(self::toNumber($limit));
        if (!$separator instanceof RegExp && $separator !== JsUndefined::Value) { $separator = self::str($separator); }
        if ($limit === 0) { return new JsArray(); }
        if ($separator === JsUndefined::Value) { return new JsArray([$source]); }
        $result = self::split($source, $separator, $limit);
        $result->a = array_map(static fn ($value) => $value ?? JsUndefined::Value, $result->a);
        return $result;
    }

    public static function dynamicStaticCall(string $owner, string $method, JsArray $args): mixed
    {
        Builtins::check($owner, $method, count($args->a));
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
                if ($source instanceof \Closure || $source instanceof JsClass) { throw new JsError('Object.hasOwn on functions is not supported', 'TypeError'); }
                if ($source instanceof JsNamespace) {
                    if (in_array($key, ['PI', 'E'], true) || Builtins::has($source->name, $key)) { return true; }
                    if (in_array($key, ['toString', 'valueOf'], true)) { return false; }
                    throw new JsError('Unsupported Math property ' . $key, 'TypeError');
                }
                if ($source instanceof JsError) {
                    if ($key === 'stack') { throw new JsError('Error stack metadata is not supported', 'TypeError'); }
                    return $key === 'message' && $source->hasMessage;
                }
                if ($key === 'length' && ($source instanceof JsArray || $source instanceof JsArguments || is_string($source))) { return true; }
                if ($key === 'callee' && $source instanceof JsArguments || $key === 'lastIndex' && $source instanceof RegExp) { return true; }
                return in_array($key, self::dynamicKeys($source)->a, true);
            }
            if ($method === 'fromEntries') {
                return self::fromEntries($source);
            }
        }
        if ($owner === 'Math') {
            $n = array_map(self::toNumber(...), $a);
            $x = $n[0] ?? NAN;
            return match ($method) {
                'random' => self::random(), 'abs' => abs($x), 'floor' => self::n(floor($x)), 'ceil' => self::n(ceil($x)), 'trunc' => self::trunc($x),
                'round' => self::round($x),
                'min' => self::min(...$n), 'max' => self::max(...$n), 'pow' => self::pow($x, $n[1] ?? NAN),
                'log', 'exp' => self::mathTranscendental($method, $x), 'sqrt' => sqrt($x), 'sign' => self::sign($x),
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
        $match = $regexp->execMatch($value);
        if ($match === null) { return null; }
        [$start, $end, $captures, $groups] = $match;
        $result = new JsArray([substr($value, $start, $end - $start), ...array_map(static fn ($v) => $v ?? JsUndefined::Value, $captures)]);
        $result->p = ['index' => self::length(substr($value, 0, $start)), 'input' => $value, 'groups' => $regexp->hasNamedGroups() ? new JsObject(array_map(static fn ($v) => $v ?? JsUndefined::Value, $groups), null, true) : JsUndefined::Value];
        return $result;
    }

    public static function dynamicConstruct(mixed $constructor, JsArray $args): mixed
    {
        $a = $args->a;
        if ($constructor instanceof JsClass) { return $constructor->construct(...$a); }
        if (is_string($constructor)) { Builtins::check('constructor', $constructor, count($a)); }
        if (in_array($constructor, ['Error', 'TypeError', 'RangeError'], true)) {
            $message = array_key_exists(0, $a) ? $a[0] : JsUndefined::Value;
            $text = $message === JsUndefined::Value ? '' : self::str($message);
            $options = $a[1] ?? JsUndefined::Value;
            if (($options instanceof JsObject || $options instanceof JsArray || $options instanceof JsArguments)
                && (array_key_exists('cause', $options->p) || $options instanceof JsObject && ($options->class?->hasMethod('cause') ?? false))) {
                throw new JsError('Error cause is not supported', 'TypeError');
            }
            return new JsError($text, $constructor, $message !== JsUndefined::Value);
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
                    elseif ($value instanceof JsArray || $value instanceof JsArguments || $value instanceof JsObject) { $result->set(self::dynamicGet($value, '0'), self::dynamicGet($value, '1')); }
                    else { throw new JsError('Iterator value is not an entry object', 'TypeError'); }
                }
            }
            return $result;
        }
        throw new JsError('Value is not a supported constructor', 'TypeError');
    }

    public static function dynamicRegExpCall(JsArray $args): RegExp
    {
        Builtins::check('global', 'RegExp', count($args->a));
        if (($args->a[0] ?? null) instanceof RegExp && (!array_key_exists(1, $args->a) || $args->a[1] === JsUndefined::Value)) { return $args->a[0]; }
        return self::dynamicConstruct('RegExp', $args);
    }

    private static function dynamicPrimitive(mixed $value, string $hint = 'default'): mixed
    {
        if ($value instanceof JsDate) {
            if ($hint !== 'number') { throw new JsError('Date string coercion is not supported', 'TypeError'); }
            return $value->milliseconds;
        }
        if ($value instanceof JsObject || $value instanceof JsArray || $value instanceof JsArguments) {
            foreach ($hint === 'string' ? ['toString', 'valueOf'] : ['valueOf', 'toString'] as $method) {
                if (array_key_exists($method, $value->p)) {
                    if (!$value->p[$method] instanceof \Closure) { continue; }
                    $result = self::dynamicInvoke($value->p[$method], $value, new JsArray());
                } elseif ($value instanceof JsObject && $value->nullPrototype) { continue; }
                else { $result = self::dynamicCallMethod($value, $method, new JsArray(), true); }
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
            $comparison = self::stringCompare($left, $right);
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
        if ($ta === 'function' && ($tb === 'number' || $tb === 'string')) { return self::looseEq(self::dynamicPrimitive($a), $b); }
        if ($tb === 'function' && ($ta === 'number' || $ta === 'string')) { return self::looseEq($a, self::dynamicPrimitive($b)); }
        if (($ta === 'number' && $tb === 'string') || ($ta === 'string' && $tb === 'number')) { return self::strictEq(self::toNumber($a), self::toNumber($b)); }
        if ($ta === 'object' && ($tb === 'string' || $tb === 'number')) { return self::looseEq(self::dynamicPrimitive($a), $b); }
        if ($tb === 'object' && ($ta === 'string' || $ta === 'number')) { return self::looseEq($a, self::dynamicPrimitive($b)); }
        return false;
    }
}
