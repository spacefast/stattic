<?php

declare(strict_types=1);

namespace SpacePress;

/**
 * Checks an input value against the JSON Schema the compiler wrote from a handler's parameter type
 * (`query(options, async (input: { tags: string[] }) => ...)`), for the fields that aren't plain scalars.
 * It reads the subset the compiler emits (src/wordpress/abilities.rs `ty_schema`): `type` (one or a
 * list), `enum`, `properties`, `required`, `additionalProperties`, `items` (one schema or a tuple) and
 * `minItems`/`maxItems`. Unknown keys of an object are refused, as at the top level.
 */
final class InputShape
{
    /** @throws HttpError invalid_input at the first value that doesn't fit, with its JSON pointer */
    public static function check(array $schema, mixed $v, string $name, string $pointer): void
    {
        if (isset($schema['enum']) && !in_array($v, $schema['enum'], true)
            && !(is_int($v) && in_array((float) $v, $schema['enum'], true)) && !(is_float($v) && in_array((int) $v, $schema['enum'], true))) {
            throw HttpError::invalidInput("$name must be one of: " . implode(', ', array_map(static fn ($e) => (string) Js::jsonStringify($e), $schema['enum'])), $pointer);
        }
        $types = isset($schema['type']) ? (array) $schema['type'] : [];
        if ($types === []) {
            return;
        }
        $kind = self::kind($v);
        // An empty PHP array is `[]` or `{}`: whichever the schema asks for.
        if ($v === [] && in_array('array', $types, true)) {
            $kind = 'array';
        }
        if (!in_array($kind, $types, true) && !($kind === 'integer' && in_array('number', $types, true))) {
            throw HttpError::invalidInput("$name must be " . implode(' or ', array_map(self::noun(...), $types)), $pointer);
        }
        if ($kind === 'object') {
            $props = $schema['properties'] ?? [];
            $obj = self::entries($v);
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $obj)) {
                    throw HttpError::invalidInput("$name.$key is required", $pointer . HttpError::seg((string) $key));
                }
            }
            foreach ($obj as $key => $item) {
                $key = (string) $key;
                $sub = $props[$key] ?? ($schema['additionalProperties'] ?? null);
                if (!is_array($sub)) {
                    if ($props !== [] || isset($schema['additionalProperties'])) {
                        throw HttpError::invalidInput("Unknown field '$key'", $pointer . HttpError::seg($key));
                    }
                    continue;
                }
                self::check($sub, $item, "$name.$key", $pointer . HttpError::seg($key));
            }
        } elseif ($kind === 'array') {
            $list = self::entries($v);
            if (isset($schema['minItems']) && count($list) < $schema['minItems']) {
                throw HttpError::invalidInput("$name must have at least {$schema['minItems']} items", $pointer);
            }
            if (isset($schema['maxItems']) && count($list) > $schema['maxItems']) {
                throw HttpError::invalidInput("$name must have at most {$schema['maxItems']} items", $pointer);
            }
            $items = $schema['items'] ?? null;
            foreach (array_values($list) as $i => $item) {
                $sub = is_array($items) && array_is_list($items) ? ($items[$i] ?? null) : $items;
                if (is_array($sub)) {
                    self::check($sub, $item, "{$name}[$i]", "$pointer/$i");
                }
            }
        }
    }

    private static function kind(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => 'boolean',
            is_int($v), is_float($v) && Js::isInteger($v) => 'integer',
            is_float($v) => 'number',
            is_string($v) => 'string',
            $v instanceof JsArray, is_array($v) && array_is_list($v) && $v !== [] => 'array',
            $v instanceof JsObject, $v instanceof \stdClass, is_array($v) => 'object',
            default => 'unknown',
        };
    }

    private static function entries(mixed $v): array
    {
        return match (true) {
            $v instanceof JsArray => $v->a,
            $v instanceof JsObject => $v->p,
            $v instanceof \stdClass => get_object_vars($v),
            default => (array) $v,
        };
    }

    private static function noun(string $type): string
    {
        return match ($type) {
            'integer' => 'an integer', 'number' => 'a number', 'string' => 'a string', 'boolean' => 'a boolean',
            'null' => 'null', 'array' => 'a list', 'object' => 'an object', default => $type,
        };
    }
}
