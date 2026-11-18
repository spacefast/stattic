<?php

declare(strict_types=1);

namespace TypedPress\Content;

use TypedPress\JsArray;
use TypedPress\JsObject;
use TypedPress\OpenApi;
use TypedPress\Wp\Plugin;

/**
 * `apiReference(source?)` from "typedpress/content": an OpenAPI 3.1 document as the data an API
 * reference page renders, flat enough for TSX to map over.
 *
 * The type system has no recursive types, so a page can't walk a JSON Schema itself. This reads
 * the document once per request and returns tags, each with its operations, each with its
 * parameters, request body and responses as lists of fields. A field's `depth` says how far it
 * is nested (a property of an object inside an array is depth 1), so a page indents it.
 *
 * The document is the app's own (`typedpress build` writes it from the schema, and the site serves it
 * at `/__tp/api/v1/openapi.json`), or a JSON file in the app's `public/` folder when a page
 * names one: `apiReference("partner-api.json")`. Descriptions are Markdown in OpenAPI; they come
 * back as HTML, for `<Html value>`.
 */
final class ApiReference
{
    /** How deep fields are listed below a parameter, body or response. */
    private const DEPTH = 4;
    /** The blocks Markdown makes, rendered from their saved HTML alone. */
    private const STATIC_BLOCKS = ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/code', 'core/table', 'core/separator', 'core/preformatted'];
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @var array<string, JsObject> per source, per request */
    private static array $read = [];

    public static function build(?string $source = null): JsObject
    {
        $key = $source ?? '';
        if (isset(self::$read[$key])) {
            return self::$read[$key];
        }
        $document = ($source === null ? self::own() : self::file($source)) ?? [];
        // Converting every description's Markdown is the costly part. The result depends on the
        // document and on this code only, so it is kept until either changes.
        static $code = null;
        $code ??= (string) md5_file(__FILE__);
        $cache = 'tp_api_reference_' . md5((string) json_encode($document) . "\0" . $code);
        $reference = get_transient($cache);
        if (!is_array($reference)) {
            $reference = self::reference($document);
            set_transient($cache, $reference, DAY_IN_SECONDS);
        }

        return self::$read[$key] = self::js($reference);
    }

    /** The app's own document, with this site as its server. */
    private static function own(): ?array
    {
        $document = OpenApi::document();

        return $document === null ? null : json_decode((string) json_encode($document), true);
    }

    /** A JSON document in the app's `public/` folder, by its path there. */
    private static function file(string $source): ?array
    {
        $plugin = Plugin::instance();
        $relative = ltrim(str_replace('\\', '/', $source), '/');
        if ($plugin === null || $relative === '' || str_contains('/' . $relative . '/', '/../')) {
            return null;
        }
        $file = $plugin->dir . '/public/' . $relative;
        if (!is_file($file)) {
            return null;
        }
        $document = json_decode((string) file_get_contents($file), true);

        return is_array($document) ? $document : null;
    }

    /** @param array<string, mixed> $doc */
    private static function reference(array $doc): array
    {
        $info = is_array($doc['info'] ?? null) ? $doc['info'] : [];
        $server = is_string($doc['servers'][0]['url'] ?? null) ? rtrim($doc['servers'][0]['url'], '/') : '';
        $tags = [];
        foreach (is_array($doc['tags'] ?? null) ? $doc['tags'] : [] as $tag) {
            if (is_string($tag['name'] ?? null)) {
                $tags[$tag['name']] = ['name' => $tag['name'], 'slug' => self::slug($tag['name']), 'description' => self::markdown($tag['description'] ?? null), 'operations' => []];
            }
        }
        foreach (is_array($doc['paths'] ?? null) ? $doc['paths'] : [] as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            $shared = is_array($item['parameters'] ?? null) ? $item['parameters'] : [];
            foreach (self::METHODS as $method) {
                $op = $item[$method] ?? null;
                if (!is_array($op)) {
                    continue;
                }
                $tag = is_string($op['tags'][0] ?? null) ? $op['tags'][0] : 'Other';
                $tags[$tag] ??= ['name' => $tag, 'slug' => self::slug($tag), 'description' => '', 'operations' => []];
                $tags[$tag]['operations'][] = self::operation($doc, (string) $path, $method, $op, $shared, $server);
            }
        }

        // Slugs are URL segments: two tags, or two operations of one tag, never share one. A later
        // one gets `-2`, `-3`, … in the document's order, so a page per slug reaches each.
        $taken = [];
        foreach ($tags as $name => $tag) {
            $tags[$name]['slug'] = self::unique($tag['slug'], $taken);
            $ops = [];
            foreach ($tag['operations'] as $i => $op) {
                $tags[$name]['operations'][$i]['slug'] = self::unique($op['slug'], $ops);
            }
        }

        return [
            'title' => is_string($info['title'] ?? null) ? $info['title'] : 'API reference',
            'version' => is_string($info['version'] ?? null) ? $info['version'] : '',
            'description' => self::markdown($info['description'] ?? null),
            'server' => $server,
            'tags' => array_values(array_filter($tags, static fn (array $t): bool => $t['operations'] !== [])),
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $op
     * @param list<mixed> $shared
     */
    private static function operation(array $doc, string $path, string $method, array $op, array $shared, string $server): array
    {
        $id = is_string($op['operationId'] ?? null) ? $op['operationId'] : $method . ' ' . $path;
        // An operation's parameter replaces the path's with the same name and place (OpenAPI 3.1 §4.8.9).
        $byKey = [];
        foreach (array_merge($shared, is_array($op['parameters'] ?? null) ? $op['parameters'] : []) as $param) {
            $param = self::deref($doc, $param);
            if (is_string($param['name'] ?? null)) {
                $byKey[($param['in'] ?? '') . "\0" . $param['name']] = $param;
            }
        }
        $params = [];
        foreach ($byKey as $param) {
            $schema = $param['schema'] ?? (is_array($param['content'] ?? null) ? (reset($param['content'])['schema'] ?? null) : null);
            $field = self::field($doc, $param['name'], is_array($schema) ? $schema : [], (bool) ($param['required'] ?? false), 0, $param['description'] ?? null);
            $field['in'] = is_string($param['in'] ?? null) ? $param['in'] : '';
            $params[] = $field;
        }
        $body = [];
        $bodyType = '';
        $example = null;
        $request = self::deref($doc, $op['requestBody'] ?? null);
        if (is_array($request['content'] ?? null) && $request['content'] !== []) {
            $bodyType = isset($request['content']['application/json']) ? 'application/json' : (string) array_key_first($request['content']);
            $schema = is_array($request['content'][$bodyType]['schema'] ?? null) ? $request['content'][$bodyType]['schema'] : [];
            $body = self::fields($doc, $schema, 0, []);
            $example = $bodyType === 'application/json' ? self::example($doc, $schema, 0) : null;
        }
        $responses = [];
        foreach (is_array($op['responses'] ?? null) ? $op['responses'] : [] as $status => $response) {
            $response = self::deref($doc, $response);
            $content = is_array($response['content'] ?? null) ? $response['content'] : [];
            $type = $content === [] ? '' : (isset($content['application/json']) ? 'application/json' : (string) array_key_first($content));
            $schema = $type === '' || !is_array($content[$type]['schema'] ?? null) ? [] : $content[$type]['schema'];
            $responses[] = [
                'status' => (string) $status,
                'description' => self::markdown($response['description'] ?? null),
                'type' => $type,
                'fields' => $type === '' ? [] : self::fields($doc, $schema, 0, []),
            ];
        }
        $url = $server . $path;

        return [
            'id' => $id,
            'slug' => self::slug(str_replace('.', '-', $id)),
            'method' => strtoupper($method),
            'path' => $path,
            'summary' => is_string($op['summary'] ?? null) ? $op['summary'] : $id,
            'description' => self::markdown($op['description'] ?? null),
            'access' => is_string($op['x-typedpress-access'] ?? null) ? $op['x-typedpress-access'] : '',
            'params' => $params,
            'bodyType' => $bodyType,
            'body' => $body,
            'responses' => $responses,
            'curl' => self::curl(strtoupper($method), $url, $bodyType, $example),
            'fetch' => self::fetch(strtoupper($method), $url, $bodyType, $example),
        ];
    }

    /**
     * The properties of an object schema (or of the objects an array holds), as fields, each
     * followed by its own properties one level deeper.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $schema
     * @param array<string, true> $seen component names already open above, so a cycle stops
     * @return list<array>
     */
    private static function fields(array $doc, array $schema, int $depth, array $seen): array
    {
        if ($depth >= self::DEPTH) {
            return [];
        }
        [$object, $seen] = self::objectOf($doc, $schema, $seen);
        if ($object === null) {
            return [];
        }
        $required = array_map('strval', is_array($object['required'] ?? null) ? $object['required'] : []);
        $out = [];
        foreach ($object['properties'] as $name => $property) {
            $property = is_array($property) ? $property : [];
            // A name like "1" is an integer key in PHP: compare names as strings.
            $out[] = self::field($doc, (string) $name, $property, in_array((string) $name, $required, true), $depth, $property['description'] ?? null);
            array_push($out, ...self::below($doc, $property, $depth + 1, $seen));
        }

        return $out;
    }

    /**
     * The object schema whose properties to list: the schema itself, the item schema of an array,
     * or the one object variant of a nullable `anyOf`. Null when there is none, or when it is a
     * component already open above (a cycle).
     *
     * @return array{?array, array<string, true>}
     */
    private static function objectOf(array $doc, array $schema, array $seen): array
    {
        for ($i = 0; $i < 8; $i++) {
            if (is_string($schema['$ref'] ?? null)) {
                if (isset($seen[$schema['$ref']])) {
                    return [null, $seen];
                }
                $seen[$schema['$ref']] = true;
                $schema = self::deref($doc, $schema);
                continue;
            }
            if (is_array($schema['allOf'] ?? null)) {
                $schema = self::merged($doc, $schema, $seen);
            }
            if (is_array($schema['properties'] ?? null) && $schema['properties'] !== []) {
                return [$schema, $seen];
            }
            if (is_array($schema['items'] ?? null)) {
                $schema = $schema['items'];
                continue;
            }
            $variants = array_values(array_filter(
                array_merge(is_array($schema['anyOf'] ?? null) ? $schema['anyOf'] : [], is_array($schema['oneOf'] ?? null) ? $schema['oneOf'] : []),
                static fn (mixed $v): bool => is_array($v) && ($v['type'] ?? null) !== 'null',
            ));
            if ($variants === []) {
                return [null, $seen];
            }
            if (count($variants) > 1) {
                // Several shapes: every property any of them has, required only where all require it.
                $merged = ['type' => 'object', 'properties' => [], 'required' => null];
                foreach ($variants as $variant) {
                    [$object] = self::objectOf($doc, $variant, $seen);
                    if ($object === null) {
                        continue;
                    }
                    $merged['properties'] += $object['properties'];
                    $req = is_array($object['required'] ?? null) ? $object['required'] : [];
                    $merged['required'] = $merged['required'] === null ? $req : array_values(array_intersect($merged['required'], $req));
                }
                $merged['required'] ??= [];

                return [$merged['properties'] === [] ? null : $merged, $seen];
            }
            $schema = $variants[0];
        }

        return [null, $seen];
    }

    /**
     * The fields below a property: its object's properties; or, when it is a choice between
     * shapes (`integer | Author`, `Post | object`, or an array of one), a `one of` row per object
     * shape with that shape's fields under it. A scalar choice (the id beside a related row) is in
     * the type already.
     *
     * @param array<string, true> $seen
     * @return list<array>
     */
    private static function below(array $doc, array $schema, int $depth, array $seen): array
    {
        $variants = self::variants($doc, $schema);
        if (count($variants) < 2) {
            return self::fields($doc, $schema, $depth, $seen);
        }
        $out = [];
        foreach ($variants as $variant) {
            $fields = self::fields($doc, $variant, $depth + 1, $seen);
            if ($fields === []) {
                continue;
            }
            $out[] = ['name' => 'one of', 'depth' => $depth, 'type' => self::type($doc, $variant), 'required' => false, 'description' => '', 'constraints' => [], 'in' => ''];
            array_push($out, ...$fields);
        }

        return $out;
    }

    /** @return list<array> the shapes other than null a schema chooses between (through an array's items), or [] */
    private static function variants(array $doc, array $schema): array
    {
        for ($i = 0; $i < 4; $i++) {
            if (is_string($schema['$ref'] ?? null) || (is_array($schema['properties'] ?? null) && $schema['properties'] !== [])) {
                return [];
            }
            foreach (['anyOf', 'oneOf'] as $key) {
                if (is_array($schema[$key] ?? null)) {
                    return array_values(array_filter($schema[$key], static fn (mixed $v): bool => is_array($v) && ($v['type'] ?? null) !== 'null'));
                }
            }
            if (!is_array($schema['items'] ?? null)) {
                return [];
            }
            $schema = $schema['items'];
        }

        return [];
    }

    /**
     * An `allOf` schema as one object: the properties and `required` of every member, `$ref`s
     * followed (a member already open above is skipped).
     *
     * @param array<string, true> $seen
     */
    private static function merged(array $doc, array $schema, array $seen): array
    {
        $out = $schema;
        unset($out['allOf']);
        $out['properties'] = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $out['required'] = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($schema['allOf'] as $member) {
            if (!is_array($member)) {
                continue;
            }
            if (is_string($member['$ref'] ?? null)) {
                if (isset($seen[$member['$ref']])) {
                    continue;
                }
                $seen[$member['$ref']] = true;
            }
            $member = self::deref($doc, $member);
            if (is_array($member['allOf'] ?? null)) {
                $member = self::merged($doc, $member, $seen);
            }
            $out['properties'] += is_array($member['properties'] ?? null) ? $member['properties'] : [];
            $out['required'] = array_merge($out['required'], is_array($member['required'] ?? null) ? $member['required'] : []);
            $out['type'] ??= $member['type'] ?? null;
        }
        if ($out['properties'] !== []) {
            $out['type'] ??= 'object';
        }

        return $out;
    }

    /** @param array<string, mixed> $schema */
    private static function field(array $doc, string $name, array $schema, bool $required, int $depth, mixed $description): array
    {
        $type = self::type($doc, $schema);
        // The constraints and description of a field that names another schema are that schema's.
        if (is_string($schema['$ref'] ?? null)) {
            $schema = self::deref($doc, $schema) + array_diff_key($schema, ['$ref' => true]);
        }
        $constraints = [];
        foreach (['minimum' => 'min', 'maximum' => 'max', 'minLength' => 'min length', 'maxLength' => 'max length', 'minItems' => 'min items', 'maxItems' => 'max items', 'pattern' => 'matches', 'format' => 'format'] as $key => $label) {
            if (is_scalar($schema[$key] ?? null)) {
                $constraints[] = $label . ' ' . $schema[$key];
            }
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $constraints[] = 'unique items';
        }
        if (array_key_exists('default', $schema)) {
            $constraints[] = 'default: ' . json_encode($schema['default'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('const', $schema)) {
            $constraints[] = 'always ' . json_encode($schema['const'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $enum = $schema['enum'] ?? ($schema['items']['enum'] ?? null);
        if (is_array($enum) && $enum !== []) {
            $constraints[] = 'one of: ' . implode(', ', array_map(static fn (mixed $v): string => is_string($v) ? $v : (string) json_encode($v), $enum));
        }

        return [
            'name' => $name,
            'depth' => $depth,
            'type' => $type,
            'required' => $required,
            'description' => self::markdown(is_string($description) ? $description : ($schema['description'] ?? null)),
            'constraints' => $constraints,
            'in' => '',
        ];
    }

    /** A schema's type as a reader says it: `string | null`, `Post[]`, `integer | Author`. */
    private static function type(array $doc, array $schema, int $level = 0): string
    {
        if ($level > 4) {
            return 'object';
        }
        if (is_string($schema['$ref'] ?? null)) {
            return (string) preg_replace('#^.*/#', '', $schema['$ref']);
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (is_array($schema[$key] ?? null) && $schema[$key] !== []) {
                $parts = array_map(static fn (mixed $v): string => self::type($doc, is_array($v) ? $v : [], $level + 1), $schema[$key]);
                return implode(' | ', array_values(array_unique(explode(' | ', implode(' | ', $parts)))));
            }
        }
        if (is_array($schema['allOf'] ?? null) && $schema['allOf'] !== []) {
            $named = array_values(array_filter(array_map(static fn (mixed $v): ?string => is_array($v) && is_string($v['$ref'] ?? null) ? (string) preg_replace('#^.*/#', '', $v['$ref']) : null, $schema['allOf'])));
            return $named !== [] ? implode(' & ', $named) : 'object';
        }
        if (array_key_exists('const', $schema) && !isset($schema['type'])) {
            return (string) json_encode($schema['const'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $types = (array) ($schema['type'] ?? (is_array($schema['properties'] ?? null) ? 'object' : ''));
        $out = [];
        foreach ($types as $type) {
            if ($type === 'array') {
                $item = self::type($doc, is_array($schema['items'] ?? null) ? $schema['items'] : [], $level + 1);
                $out[] = (str_contains($item, ' ') ? "($item)" : $item) . '[]';
            } else {
                $out[] = (string) $type;
            }
        }
        $out = array_values(array_filter($out, static fn (string $t): bool => $t !== '' && $t !== '[]'));

        return $out === [] ? 'any' : implode(' | ', $out);
    }

    /** A JSON body a reader can start from: each required property (or every one, when none is required) with a placeholder of its type. */
    private static function example(array $doc, array $schema, int $level): mixed
    {
        if ($level > 3) {
            return null;
        }
        $schema = self::deref($doc, $schema);
        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        if (is_array($schema['allOf'] ?? null)) {
            $schema = self::merged($doc, $schema, []);
        }
        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                foreach ($schema[$key] as $variant) {
                    if (is_array($variant) && ($variant['type'] ?? null) !== 'null') {
                        return self::example($doc, $variant, $level + 1);
                    }
                }
            }
        }
        $type = ((array) ($schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string')))[0] ?? 'string';
        if ($type === 'object') {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            $names = $required !== [] ? $required : array_keys($properties);
            $out = new \stdClass();
            foreach ($names as $name) {
                if (isset($properties[$name]) && is_array($properties[$name]) && ($properties[$name]['readOnly'] ?? false) !== true) {
                    $out->{$name} = self::example($doc, $properties[$name], $level + 1);
                }
            }
            return $out;
        }

        return match ($type) {
            'array' => [],
            'integer', 'number' => 0,
            'boolean' => false,
            'null' => null,
            default => 'string',
        };
    }

    private static function curl(string $method, string $url, string $type, mixed $example): string
    {
        // -g: braces and brackets in the URL (`/notes/{id}`, `?where[a]=1`) are the path, not curl globs.
        $lines = ["curl -g -X $method " . self::shell($url), "  -H 'Accept: application/json'"];
        if ($type === 'application/json') {
            $lines[] = "  -H 'Content-Type: application/json'";
            $lines[] = '  -d ' . self::shell((string) json_encode($example ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($type === 'multipart/form-data') {
            $lines[] = "  -F 'file=@./file'";
        }

        return implode(" \\\n", $lines);
    }

    /** A shell word in single quotes, whatever it holds. */
    private static function shell(string $word): string
    {
        return "'" . str_replace("'", "'\\''", $word) . "'";
    }

    private static function fetch(string $method, string $url, string $type, mixed $example): string
    {
        $headers = ['"Accept": "application/json"'];
        $body = '';
        if ($type === 'application/json') {
            $headers[] = '"Content-Type": "application/json"';
            $body = "\n  body: JSON.stringify(" . json_encode($example ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '),';
        } elseif ($type === 'multipart/form-data') {
            $body = "\n  body: form, // a FormData with the file under \"file\"";
        }

        return "const response = await fetch(" . json_encode($url, JSON_UNESCAPED_SLASHES) . ", {\n  method: \"$method\",\n  headers: { " . implode(', ', $headers) . " },$body\n});\nconst data = await response.json();";
    }

    /** A `$ref` to `#/components/...`, followed; anything else as it is. */
    private static function deref(array $doc, mixed $value): array
    {
        for ($i = 0; $i < 8 && is_array($value) && is_string($value['$ref'] ?? null); $i++) {
            $target = $doc;
            foreach (explode('/', ltrim(substr($value['$ref'], 1), '/')) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                $target = is_array($target) && array_key_exists($part, $target) ? $target[$part] : null;
            }
            $value = $target;
        }

        return is_array($value) ? $value : [];
    }

    /** @param array<string, true> $taken */
    private static function unique(string $slug, array &$taken): string
    {
        $out = $slug;
        for ($n = 2; isset($taken[$out]); $n++) {
            $out = "$slug-$n";
        }
        $taken[$out] = true;

        return $out;
    }

    /** `WordPress` → `word-press`, `actions-approveComment` → `actions-approve-comment`. */
    public static function slug(string $name): string
    {
        $kebab = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', remove_accents($name));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($kebab)), '-') ?: 'api';
    }

    /** OpenAPI's Markdown, as HTML. */
    private static function markdown(mixed $text): string
    {
        if (!is_string($text) || trim($text) === '') {
            return '';
        }
        static $cache = [];
        if (isset($cache[$text])) {
            return $cache[$text];
        }
        try {
            $blocks = parse_blocks(\TypedPress\Sync\Markdown::toBlocks($text));
        } catch (\Throwable) {
            return $cache[$text] = '<p>' . esc_html($text) . '</p>';
        }

        return $cache[$text] = trim(self::staticHtml($blocks));
    }

    /**
     * The saved HTML of the blocks plain Markdown makes (paragraphs, headings, lists, quotes, code,
     * tables, rules), joined. No block is rendered: a document's description can't run the site's
     * dynamic blocks (latest posts, a login form, a plugin's block), and any other block is dropped.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private static function staticHtml(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name === null) {
                $out .= (string) ($block['innerHTML'] ?? '');
                continue;
            }
            if (!in_array($name, self::STATIC_BLOCKS, true)) {
                continue;
            }
            $inner = $block['innerBlocks'] ?? [];
            $i = 0;
            foreach ($block['innerContent'] ?? [] as $chunk) {
                $out .= is_string($chunk) ? $chunk : self::staticHtml([$inner[$i++] ?? []]);
            }
        }

        return $out;
    }

    /** PHP arrays to the values compiled code reads: a list is an array, a map an object. */
    private static function js(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return new JsArray(array_map(self::js(...), $value));
        }

        return new JsObject(array_map(self::js(...), $value));
    }
}
