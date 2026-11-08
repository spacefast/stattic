<?php
declare(strict_types=1);
namespace Zero;

use Zero\Vendor\Rowbot\URL\URLSearchParams as WhatwgParams;

/**
 * WHATWG `URLSearchParams`, backed by the bundled rowbot/url. A URL's `searchParams` shares its
 * list with that URL, so writes update `url.search` exactly as the standard's update steps do.
 */
final class JsUrlSearchParams implements \Stringable
{
    private function __construct(private readonly WhatwgParams $params) {}

    public static function of(WhatwgParams $params): self { return new self($params); }

    /** `new URLSearchParams(init?)`: a string, a sequence of [name, value] pairs, or a record. */
    public static function construct(mixed $init = JsUndefined::Value): self
    {
        if ($init === JsUndefined::Value) { return new self(new WhatwgParams()); }
        if ($init instanceof self) { return new self(new WhatwgParams((string) $init->params)); }
        if ($init instanceof JsArray || $init instanceof JsMap || $init instanceof JsSet || $init instanceof JsArguments || $init instanceof JsIterator) {
            $pairs = [];
            foreach (Js::dynamicIterator($init) as $pair) {
                $items = iterator_to_array(Js::dynamicIterator($pair), false);
                if (count($items) !== 2) { throw new JsError("Failed to construct 'URLSearchParams': Each query pair must be an iterable [name, value] tuple", 'TypeError'); }
                $pairs[] = [Js::str($items[0]), Js::str($items[1])];
            }
            return new self(new WhatwgParams($pairs));
        }
        if ($init instanceof JsObject) {
            $params = new WhatwgParams();
            foreach ($init->p as $name => $value) { $params->append((string) $name, Js::str($value)); }
            return new self($params);
        }
        return new self(new WhatwgParams(Js::str($init)));
    }

    public function size(): int { return $this->params->size; }
    public function append(mixed $name, mixed $value): JsUndefined { $this->params->append(Js::str($name), Js::str($value)); return JsUndefined::Value; }
    public function set(mixed $name, mixed $value): JsUndefined { $this->params->set(Js::str($name), Js::str($value)); return JsUndefined::Value; }
    public function sort(): JsUndefined { $this->params->sort(); return JsUndefined::Value; }

    public function delete(mixed $name, mixed $value = JsUndefined::Value): JsUndefined
    {
        $this->params->delete(Js::str($name), $value === JsUndefined::Value ? null : Js::str($value));
        return JsUndefined::Value;
    }

    public function has(mixed $name, mixed $value = JsUndefined::Value): bool
    {
        return $this->params->has(Js::str($name), $value === JsUndefined::Value ? null : Js::str($value));
    }

    public function get(mixed $name): ?string { return $this->params->get(Js::str($name)); }
    public function getAll(mixed $name): JsArray { return new JsArray($this->params->getAll(Js::str($name))); }
    public function toString(): string { return $this->params->toString(); }
    public function __toString(): string { return $this->params->toString(); }

    /**
     * The live pairs, as the URL Standard's iterator reads them: index i against the current list on
     * each step, so pairs added during iteration are visited and removed ones shift the rest.
     * rowbot exposes one shared Iterator cursor, so each read seeks it and nested loops stay
     * independent.
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private function pairs(): \Generator
    {
        for ($i = 0; $i < $this->params->count(); $i++) {
            $this->params->rewind();
            for ($j = 0; $j < $i; $j++) { $this->params->next(); }
            $pair = $this->params->current();
            if ($pair === null) { return; }
            yield [$pair[0], $pair[1]];
        }
    }

    public function entriesIterator(): \Generator { foreach ($this->pairs() as $pair) { yield new JsArray($pair); } }
    public function keysIterator(): \Generator { foreach ($this->pairs() as [$name]) { yield $name; } }
    public function valuesIterator(): \Generator { foreach ($this->pairs() as [, $value]) { yield $value; } }

    public function forEach(\Closure $callback): JsUndefined
    {
        foreach ($this->pairs() as [$name, $value]) { $callback($value, $name, $this); }
        return JsUndefined::Value;
    }
}
