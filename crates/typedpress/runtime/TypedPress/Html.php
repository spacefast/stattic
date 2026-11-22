<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * Rendered HTML from a compiled TSX component (docs/decisions/2026-11-08-tsx-to-php.md).
 *
 * A value of this class is markup the renderer has already escaped. Everything else that reaches
 * an element (strings, numbers, request data) goes through child() or attributes(), which follow
 * react-dom/server 19's rules byte for byte, so a page renders the same HTML here as React renders
 * under Node.
 *
 * Rendering is deferred, as in React: building `<Card note={n} />` evaluates its props, but Card
 * runs only when the element is placed in output that is itself rendered. Children a component
 * never places never run, and an element placed twice renders twice.
 */
final class Html
{
    /** HTML void elements: no children and no closing tag. */
    private const VOID = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true, 'hr' => true,
        'img' => true, 'input' => true, 'keygen' => true, 'link' => true, 'meta' => true,
        'param' => true, 'source' => true, 'track' => true, 'wbr' => true, 'menuitem' => true,
    ];

    /** React's prop → attribute renames (react-dom-server `aliases`, plus its own cases). */
    private const ALIASES = [
        'className' => 'class', 'htmlFor' => 'for', 'tabIndex' => 'tabindex',
        'acceptCharset' => 'accept-charset', 'httpEquiv' => 'http-equiv', 'crossOrigin' => 'crossorigin',
        'accentHeight' => 'accent-height', 'alignmentBaseline' => 'alignment-baseline',
        'arabicForm' => 'arabic-form', 'baselineShift' => 'baseline-shift', 'capHeight' => 'cap-height',
        'clipPath' => 'clip-path', 'clipRule' => 'clip-rule', 'colorInterpolation' => 'color-interpolation',
        'colorInterpolationFilters' => 'color-interpolation-filters', 'colorProfile' => 'color-profile',
        'colorRendering' => 'color-rendering', 'dominantBaseline' => 'dominant-baseline',
        'enableBackground' => 'enable-background', 'fillOpacity' => 'fill-opacity', 'fillRule' => 'fill-rule',
        'floodColor' => 'flood-color', 'floodOpacity' => 'flood-opacity', 'fontFamily' => 'font-family',
        'fontSize' => 'font-size', 'fontSizeAdjust' => 'font-size-adjust', 'fontStretch' => 'font-stretch',
        'fontStyle' => 'font-style', 'fontVariant' => 'font-variant', 'fontWeight' => 'font-weight',
        'glyphName' => 'glyph-name', 'glyphOrientationHorizontal' => 'glyph-orientation-horizontal',
        'glyphOrientationVertical' => 'glyph-orientation-vertical', 'horizAdvX' => 'horiz-adv-x',
        'horizOriginX' => 'horiz-origin-x', 'imageRendering' => 'image-rendering',
        'letterSpacing' => 'letter-spacing', 'lightingColor' => 'lighting-color', 'markerEnd' => 'marker-end',
        'markerMid' => 'marker-mid', 'markerStart' => 'marker-start', 'overlinePosition' => 'overline-position',
        'overlineThickness' => 'overline-thickness', 'paintOrder' => 'paint-order', 'panose-1' => 'panose-1',
        'pointerEvents' => 'pointer-events', 'renderingIntent' => 'rendering-intent',
        'shapeRendering' => 'shape-rendering', 'stopColor' => 'stop-color', 'stopOpacity' => 'stop-opacity',
        'strikethroughPosition' => 'strikethrough-position', 'strikethroughThickness' => 'strikethrough-thickness',
        'strokeDasharray' => 'stroke-dasharray', 'strokeDashoffset' => 'stroke-dashoffset',
        'strokeLinecap' => 'stroke-linecap', 'strokeLinejoin' => 'stroke-linejoin',
        'strokeMiterlimit' => 'stroke-miterlimit', 'strokeOpacity' => 'stroke-opacity',
        'strokeWidth' => 'stroke-width', 'textAnchor' => 'text-anchor', 'textDecoration' => 'text-decoration',
        'textRendering' => 'text-rendering', 'transformOrigin' => 'transform-origin',
        'underlinePosition' => 'underline-position', 'underlineThickness' => 'underline-thickness',
        'unicodeBidi' => 'unicode-bidi', 'unicodeRange' => 'unicode-range', 'unitsPerEm' => 'units-per-em',
        'vAlphabetic' => 'v-alphabetic', 'vHanging' => 'v-hanging', 'vIdeographic' => 'v-ideographic',
        'vMathematical' => 'v-mathematical', 'vectorEffect' => 'vector-effect', 'vertAdvY' => 'vert-adv-y',
        'vertOriginX' => 'vert-origin-x', 'vertOriginY' => 'vert-origin-y', 'wordSpacing' => 'word-spacing',
        'writingMode' => 'writing-mode', 'xmlnsXlink' => 'xmlns:xlink', 'xHeight' => 'x-height',
        'xlinkActuate' => 'xlink:actuate', 'xlinkArcrole' => 'xlink:arcrole', 'xlinkRole' => 'xlink:role',
        'xlinkShow' => 'xlink:show', 'xlinkTitle' => 'xlink:title', 'xlinkType' => 'xlink:type',
        'xmlBase' => 'xml:base', 'xmlLang' => 'xml:lang', 'xmlSpace' => 'xml:space',
    ];

    /** Present (`name=""`) when truthy, absent otherwise. */
    private const BOOLEAN = [
        'allowFullScreen' => true, 'async' => true, 'autoPlay' => true, 'controls' => true, 'default' => true,
        'defer' => true, 'disabled' => true, 'disablePictureInPicture' => true, 'disableRemotePlayback' => true,
        'formNoValidate' => true, 'hidden' => true, 'inert' => true, 'loop' => true, 'noModule' => true,
        'noValidate' => true, 'open' => true, 'playsInline' => true, 'readOnly' => true, 'required' => true,
        'reversed' => true, 'scoped' => true, 'seamless' => true, 'itemScope' => true,
    ];

    /** Booleans React prints as the string `true`/`false`. */
    private const BOOLEANISH = [
        'contentEditable' => true, 'spellCheck' => true, 'draggable' => true, 'value' => true,
        'autoReverse' => true, 'externalResourcesRequired' => true, 'focusable' => true, 'preserveAlpha' => true,
    ];

    /**
     * Attributes a browser loads or navigates to, by lowercased name with any `xlink:` prefix
     * removed: TypedPress's URL policy decides whether a value survives.
     */
    private const URL = [
        'href' => true, 'src' => true, 'action' => true, 'formaction' => true, 'xlinkhref' => true,
        'data' => true, 'ping' => true, 'poster' => true, 'background' => true, 'cite' => true,
        'codebase' => true, 'longdesc' => true, 'manifest' => true, 'srcset' => true,
        'imagesrcset' => true, 'icon' => true, 'profile' => true, 'usemap' => true, 'lowsrc' => true,
        'dynsrc' => true, 'archive' => true, 'classid' => true,
    ];

    /** Attributes whose value is HTML or script: never written from request data. */
    private const DANGEROUS = ['srcdoc' => true];

    /** CSS properties whose numbers take no `px` (react-dom-server `unitlessNumbers`). */
    private const UNITLESS = [
        'animationIterationCount' => true, 'aspectRatio' => true, 'borderImageOutset' => true,
        'borderImageSlice' => true, 'borderImageWidth' => true, 'boxFlex' => true, 'boxFlexGroup' => true,
        'boxOrdinalGroup' => true, 'columnCount' => true, 'columns' => true, 'flex' => true, 'flexGrow' => true,
        'flexPositive' => true, 'flexShrink' => true, 'flexNegative' => true, 'flexOrder' => true,
        'gridArea' => true, 'gridRow' => true, 'gridRowEnd' => true, 'gridRowSpan' => true,
        'gridRowStart' => true, 'gridColumn' => true, 'gridColumnEnd' => true, 'gridColumnSpan' => true,
        'gridColumnStart' => true, 'fontWeight' => true, 'lineClamp' => true, 'lineHeight' => true,
        'opacity' => true, 'order' => true, 'orphans' => true, 'scale' => true, 'tabSize' => true,
        'widows' => true, 'zIndex' => true, 'zoom' => true, 'fillOpacity' => true, 'floodOpacity' => true,
        'stopOpacity' => true, 'strokeDasharray' => true, 'strokeDashoffset' => true,
        'strokeMiterlimit' => true, 'strokeOpacity' => true, 'strokeWidth' => true,
        'MozAnimationIterationCount' => true, 'MozBoxFlex' => true, 'MozBoxFlexGroup' => true,
        'MozLineClamp' => true, 'msAnimationIterationCount' => true, 'msFlex' => true, 'msZoom' => true,
        'msFlexGrow' => true, 'msFlexNegative' => true, 'msFlexOrder' => true, 'msFlexPositive' => true,
        'msFlexShrink' => true, 'msGridColumn' => true, 'msGridColumnSpan' => true, 'msGridRow' => true,
        'msGridRowSpan' => true, 'WebkitAnimationIterationCount' => true, 'WebkitBoxFlex' => true,
        'WebKitBoxFlexGroup' => true, 'WebkitBoxOrdinalGroup' => true, 'WebkitColumnCount' => true,
        'WebkitColumns' => true, 'WebkitFlex' => true, 'WebkitFlexGrow' => true, 'WebkitFlexPositive' => true,
        'WebkitFlexShrink' => true, 'WebkitLineClamp' => true,
    ];

    /** @param string|\Closure(): string $body */
    private function __construct(private readonly string|\Closure $body) {}

    /** The markup, rendering everything below it now. */
    public function html(): string
    {
        return is_string($this->body) ? $this->body : ($this->body)();
    }

    /** Markup the compiler produced from literal JSX, escaped at build time. */
    public static function raw(string $html): self
    {
        return new self($html);
    }

    /** `<Component {...props} />`: the props are already evaluated; the component runs when placed. */
    public static function component(\Closure $component, mixed $props): self
    {
        return new self(static fn (): string => self::child($component($props)));
    }

    /** `&`, `<`, `>`, `"` and `'`, spelled as react-dom/server spells them. */
    public static function escape(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#x27;']);
    }

    /**
     * One child, rendered as React renders it: null, undefined and booleans render nothing, numbers
     * print as JavaScript prints them, strings are escaped, arrays concatenate, and rendered HTML
     * (or the lazy children Closure) is placed as it is.
     */
    public static function child(mixed $value): string
    {
        return match (true) {
            $value === null, $value === JsUndefined::Value, is_bool($value) => '',
            is_string($value) => self::escape($value),
            is_int($value), is_float($value), $value instanceof JsBigInt => Js::str($value),
            $value instanceof self => $value->html(),
            $value instanceof JsArray => implode('', array_map(self::child(...), $value->a)),
            default => throw new JsError(
                'Objects are not valid as a React child (found: ' . Js::str($value) . '). If you meant to render a collection of children, use an array instead.',
                'Error',
            ),
        };
    }

    /** Children, concatenated when placed. */
    public static function children(mixed ...$values): self
    {
        return new self(static function () use ($values): string {
            $out = '';
            foreach ($values as $value) {
                $out .= self::child($value);
            }
            return $out;
        });
    }

    /**
     * Compiler-escaped literal markup around each child, with one more literal than children.
     * As with children(), only placement renders the evaluated child values.
     *
     * @param list<string> $literals
     */
    public static function template(array $literals, mixed ...$children): self
    {
        return new self(static function () use ($literals, $children): string {
            $out = $literals[0];
            foreach ($children as $i => $child) {
                $out .= self::child($child);
                $out .= $literals[$i + 1];
            }
            return $out;
        });
    }

    /**
     * An intrinsic element: `$attributes` are the props in source order (spreads already applied
     * by the caller), `$children` the rendered children. Tags with React's own rules (input,
     * textarea, select, option, pre) are handled here; anything React would hoist or preload
     * (`<title>`, `<link>`, `<meta>` outside `<head>`, image preloads) is refused at build time.
     *
     * @param array<string, mixed> $attributes
     */
    public static function element(string $tag, array $attributes, mixed ...$children): self
    {
        $custom = str_contains($tag, '-') && !in_array($tag, ['annotation-xml', 'color-profile', 'font-face', 'font-face-src', 'font-face-uri', 'font-face-format', 'font-face-name', 'missing-glyph'], true);
        $open = '<' . $tag;
        $tail = '';
        // React writes form-submission attributes after the others, in a fixed order.
        $late = match ($tag) {
            'input', 'button' => ['name', 'formAction', 'formEncType', 'formMethod', 'formTarget'],
            'form' => ['action', 'encType', 'method', 'target'],
            default => [],
        };
        if ($late !== []) {
            $after = [];
            foreach ($late as $name) {
                if (array_key_exists($name, $attributes)) {
                    $after[$name] = $attributes[$name];
                    unset($attributes[$name]);
                }
            }
            $attributes += $after;
        }
        if ($tag === 'input') {
            $checked = self::first($attributes, 'checked', 'defaultChecked');
            $value = self::first($attributes, 'value', 'defaultValue');
            unset($attributes['checked'], $attributes['defaultChecked'], $attributes['value'], $attributes['defaultValue']);
            $open .= self::attributes($attributes, false, $tag);
            if (Js::truthy($checked)) {
                $open .= ' checked=""';
            }
            if (!self::nullish($value)) {
                $open .= self::attribute('value', $value, $tag);
            }
            return new self($open . '/>');
        }
        if ($tag === 'textarea') {
            if ($children !== []) {
                throw new JsError('Use the `defaultValue` or `value` props instead of setting children on <textarea>.', 'Error');
            }
            $value = self::first($attributes, 'value', 'defaultValue');
            unset($attributes['value'], $attributes['defaultValue']);
            $open .= self::attributes($attributes, false, $tag) . '>';
            $text = self::nullish($value) ? '' : self::escape(Js::str($value));
            // The HTML parser drops a leading newline, so React doubles it.
            return new self($open . (str_starts_with($text, "\n") ? "\n" : '') . $text . '</textarea>');
        }
        if ($tag === 'select' && (!self::nullish($attributes['value'] ?? null) || !self::nullish($attributes['defaultValue'] ?? null))) {
            throw new JsError('`value` on <select> is not supported; mark the chosen <option> with `selected`.', 'Error');
        }
        if ($tag === 'option' && array_key_exists('selected', $attributes)) {
            $selected = $attributes['selected'];
            unset($attributes['selected']);
            $tail = Js::truthy($selected) ? ' selected=""' : '';
        }
        if ($tag === 'img' && is_string($attributes['src'] ?? null) && class_exists(Assets::class, false)) {
            $src = $attributes['src'];
            if (!array_key_exists('width', $attributes) && !array_key_exists('height', $attributes)) {
                $meta = Assets::metaForUrl($src);
                if ($meta !== null) {
                    $attributes['width'] = $meta['width'];
                    $attributes['height'] = $meta['height'];
                }
            }
            $fp = $attributes['fetchPriority'] ?? $attributes['fetchpriority'] ?? null;
            if (is_string($fp) && strtolower($fp) === 'high') {
                $srcSet = is_string($attributes['srcSet'] ?? $attributes['srcset'] ?? null) ? ($attributes['srcSet'] ?? $attributes['srcset']) : null;
                $sizes = is_string($attributes['sizes'] ?? null) ? $attributes['sizes'] : null;
                Assets::preloadImage($src, $srcSet, $sizes);
            }
        }
        $open .= self::attributes($attributes, $custom, $tag) . $tail;
        if (isset(self::VOID[$tag])) {
            return new self($open . '/>');
        }
        // The HTML parser drops a leading newline in <pre> and <listing>, so React doubles it, but
        // only when the element's one child is a string.
        $leading = ($tag === 'pre' || $tag === 'listing') && count($children) === 1 && is_string($children[0]) && str_starts_with($children[0], "\n");
        return new self(static function () use ($open, $tag, $children, $leading): string {
            $body = $leading ? "\n" : '';
            foreach ($children as $child) {
                $body .= self::child($child);
            }
            return $open . '>' . $body . '</' . $tag . '>';
        });
    }

    /** @param array<string, mixed> $attributes */
    private static function attributes(array $attributes, bool $custom, string $tag): string
    {
        $out = '';
        foreach ($attributes as $name => $value) {
            $name = (string) $name;
            if ($name === 'children' || $name === 'key' || $name === 'ref' || $name === 'suppressContentEditableWarning' || $name === 'suppressHydrationWarning') {
                continue;
            }
            // TypedPress's own checks run first, on the name the browser will see, whatever React does.
            $written = $custom ? ($name === 'className' ? 'class' : $name) : (self::ALIASES[$name] ?? ($name === 'xlinkHref' ? 'xlink:href' : $name));
            if (!self::safeName($written)) {
                continue;
            }
            $folded = strtolower($written);
            $folded = str_starts_with($folded, 'xlink:') ? 'xlink' . substr($folded, 6) : $folded;
            // Event handlers never reach server HTML.
            if (strlen($folded) > 2 && str_starts_with($folded, 'on')) {
                continue;
            }
            if (isset(self::DANGEROUS[$folded])) {
                continue;
            }
            $url = isset(self::URL[$folded]) || isset(self::URL[preg_replace('/^xlink/', '', $folded)]);
            if ($url && !self::nullish($value) && !is_bool($value) && !$value instanceof \Closure) {
                $urlStr = Js::str($value);
                if (!self::safeUrl($urlStr) && !self::safeDataMediaUrl($tag, $folded, $urlStr)) {
                    continue;
                }
            }
            $out .= $custom ? self::customAttribute($name, $value) : self::attribute($name, $value, $tag);
        }
        return $out;
    }

    private static function nullish(mixed $value): bool
    {
        return $value === null || $value === JsUndefined::Value;
    }

    /** The first of two attributes that isn't null or undefined, as React picks `value` over `defaultValue`. */
    private static function first(array $attributes, string $name, string $fallback): mixed
    {
        $value = $attributes[$name] ?? null;
        return self::nullish($value) ? ($attributes[$fallback] ?? null) : $value;
    }

    /** One attribute of an HTML or SVG element, by react-dom-server's `pushAttribute`. */
    private static function attribute(string $name, mixed $value, string $tag): string
    {
        if (self::nullish($value) || $value instanceof \Closure) {
            return '';
        }
        // The browser reads `STYLE` and `Style` as `style`: every spelling goes through `style()`.
        if (strtolower($name) === 'style') {
            return self::style($value);
        }
        if ($name === 'href' || $name === 'src' || $name === 'action' || $name === 'formAction' || $name === 'xlinkHref') {
            if (is_bool($value)) {
                return '';
            }
            $url = Js::str($value);
            // React keeps an empty `href` only on <a>, and drops an empty `src` and `href` elsewhere.
            if ($url === '' && ($name === 'src' || ($name === 'href' && $tag !== 'a'))) {
                return '';
            }
            return ' ' . ($name === 'xlinkHref' ? 'xlink:href' : $name) . '="' . self::escape($url) . '"';
        }
        if ($name === 'defaultValue' || $name === 'defaultChecked' || $name === 'innerHTML') {
            return '';
        }
        if ($name === 'autoFocus' || $name === 'multiple' || $name === 'muted') {
            return Js::truthy($value) ? ' ' . strtolower($name) . '=""' : '';
        }
        if (isset(self::BOOLEAN[$name])) {
            return Js::truthy($value) ? ' ' . $name . '=""' : '';
        }
        if (isset(self::BOOLEANISH[$name])) {
            return ' ' . $name . '="' . self::escape(Js::str($value)) . '"';
        }
        if ($name === 'capture' || $name === 'download') {
            return match (true) {
                $value === true => ' ' . $name . '=""',
                $value === false => '',
                default => ' ' . $name . '="' . self::escape(Js::str($value)) . '"',
            };
        }
        if ($name === 'cols' || $name === 'rows' || $name === 'size' || $name === 'span') {
            $n = Js::toNumber($value);
            return is_nan((float) $n) || $n < 1 ? '' : ' ' . $name . '="' . self::escape(Js::str($value)) . '"';
        }
        if ($name === 'rowSpan' || $name === 'start') {
            return is_nan((float) Js::toNumber($value)) ? '' : ' ' . $name . '="' . self::escape(Js::str($value)) . '"';
        }
        // React drops other props starting with `on`.
        if (strlen($name) > 2 && strtolower(substr($name, 0, 2)) === 'on') {
            return '';
        }
        $name = self::ALIASES[$name] ?? $name;
        if (is_bool($value)) {
            $prefix = strtolower(substr($name, 0, 5));
            if ($prefix !== 'data-' && $prefix !== 'aria-') {
                return '';
            }
        }
        return ' ' . $name . '="' . self::escape(Js::str($value)) . '"';
    }

    /** An attribute of a custom element (a tag with a dash): props pass through by name. */
    private static function customAttribute(string $name, mixed $value): string
    {
        if (self::nullish($value) || $value === false || $value instanceof \Closure) {
            return '';
        }
        if (strtolower($name) === 'style') {
            return self::style($value);
        }
        if ($value instanceof JsObject || $value instanceof JsArray) {
            return '';
        }
        return ' ' . ($name === 'className' ? 'class' : $name) . '="' . ($value === true ? '' : self::escape(Js::str($value))) . '"';
    }

    /**
     * `style={{ … }}` limited to the property names the compiler saw in the style's type, so data
     * that carries extra keys (decoded JSON, a row) can't add properties.
     *
     * @param list<string> $names
     */
    public static function styleOf(mixed $style, array $names): mixed
    {
        // The compiler admits only objects; anything else (a string from data) renders nothing.
        if (!$style instanceof JsObject) {
            return JsUndefined::Value;
        }
        return new JsObject(array_intersect_key($style->p, array_flip($names)));
    }

    /** `style={{ … }}`, by react-dom-server's `pushStyleAttribute`. */
    private static function style(mixed $style): string
    {
        if (!$style instanceof JsObject) {
            throw new JsError('The `style` prop expects a mapping from style properties to values, not a string.', 'Error');
        }
        $parts = [];
        foreach ($style->p as $name => $value) {
            $name = (string) $name;
            if (self::nullish($value) || is_bool($value) || $value === '') {
                continue;
            }
            // A property name is an identifier or a custom property; anything else could close the
            // declaration or the attribute.
            if (preg_match('/^(--)?[a-zA-Z][a-zA-Z0-9-]*\z/', $name) !== 1) {
                continue;
            }
            if (str_starts_with($name, '--')) {
                $raw = trim(Js::str($value));
                if (str_contains($raw, ';') || stripos($raw, 'url(') !== false || str_contains($raw, '\\')) {
                    continue;
                }
                $parts[] = self::escape($name) . ':' . self::escape($raw);
                continue;
            }
            $css = self::escape(strtolower((string) preg_replace('/([A-Z])/', '-$1', $name)));
            $css = (string) preg_replace('/^ms-/', '-ms-', $css);
            if (is_int($value) || is_float($value)) {
                $text = ($value == 0 || isset(self::UNITLESS[$name])) ? Js::str($value) : Js::str($value) . 'px';
            } else {
                $raw = trim(Js::str($value));
                // A value can't add declarations or leave the attribute: the compiler fixes the
                // property names, so a `;` (React warns about it) or `url(` from data is dropped.
                if (str_contains($raw, ';') || stripos($raw, 'url(') !== false || stripos($raw, 'expression(') !== false || str_contains($raw, '\\')) {
                    continue;
                }
                $text = self::escape($raw);
            }
            $parts[] = $css . ':' . $text;
        }
        return $parts === [] ? '' : ' style="' . implode(';', $parts) . '"';
    }

    /** React's VALID_ATTRIBUTE_NAME_REGEX. */
    private static function safeName(string $name): bool
    {
        return preg_match('/^[:A-Z_a-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0370}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}][:A-Z_a-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0370}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\-.0-9\x{00B7}\x{0300}-\x{036F}\x{203F}-\x{2040}]*\z/u', $name) === 1;
    }

    /**
     * TypedPress's URL policy: http(s) and mailto URLs, and URLs without a scheme (`/a`, `?page=2`,
     * `posts/1`, `#top`). Any other scheme, and a protocol-relative `//host`, drops the attribute.
     * The compiler refuses a literal that fails this check.
     */
    public static function safeUrl(string $url): bool
    {
        // Browsers ignore leading and trailing C0 controls and spaces, and tabs and newlines anywhere.
        $url = (string) preg_replace('/[\t\n\r]/', '', trim($url, "\x00..\x20"));
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\') || str_starts_with($url, '\\')) {
            return false;
        }
        // A scheme is what comes before the first `:`, if no `/`, `?` or `#` comes first.
        $colon = strpos($url, ':');
        if ($colon === false || strcspn($url, '/?#') < $colon) {
            return true;
        }
        return in_array(strtolower(substr($url, 0, $colon)), ['http', 'https', 'mailto'], true);
    }

    /**
     * Strictly base64-encoded media `data:` URIs (from `?inline` asset imports) on `src` of media
     * elements only. Never allowed on navigation/frame/script attributes or with non-media MIME types.
     */
    private static function safeDataMediaUrl(string $tag, string $attr, string $url): bool
    {
        if ($attr !== 'src') {
            return false;
        }
        $clean = (string) preg_replace('/[\t\n\r]/', '', trim($url, "\x00..\x20"));
        $pattern = match ($tag) {
            'img' => '#^data:image/(?:png|jpe?g|gif|webp|avif|svg\+xml|x-icon|vnd\.microsoft\.icon|bmp);base64,[A-Za-z0-9+/=]+\z#i',
            'audio' => '#^data:audio/(?:mpeg|ogg|wav|webm|aac|flac);base64,[A-Za-z0-9+/=]+\z#i',
            'video' => '#^data:video/(?:mp4|webm|ogg);base64,[A-Za-z0-9+/=]+\z#i',
            'source' => '#^data:(?:image/(?:png|jpe?g|gif|webp|avif|svg\+xml|x-icon|vnd\.microsoft\.icon|bmp)|audio/(?:mpeg|ogg|wav|webm|aac|flac)|video/(?:mp4|webm|ogg));base64,[A-Za-z0-9+/=]+\z#i',
            default => null,
        };
        return $pattern !== null && preg_match($pattern, $clean) === 1;
    }
}
