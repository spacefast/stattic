<?php

declare(strict_types=1);

namespace SpacePress\Render;

/**
 * `notFound()` and `redirect()` from "spacepress/app": they stop the render, and the app router answers
 * with the not-found page or the redirect (docs/spec/rendering.md §2). Thrown past the author's own
 * `try`/`catch`, since compiled code catches only `SpacePress\JsError`.
 */
final class Halt extends \RuntimeException
{
    private function __construct(public readonly int $status, public readonly ?string $location)
    {
        parent::__construct($location === null ? 'not found' : "redirect to $location");
    }

    public static function notFound(): never
    {
        throw new self(404, null);
    }

    /** The same stop, for the router's own checks (a kind URL whose item the visitor can't read). */
    public static function notFoundSignal(): self
    {
        return new self(404, null);
    }

    public static function redirect(string $url, int $status): never
    {
        if (!\SpacePress\Html::safeUrl($url)) {
            throw new \SpacePress\JsError("redirect() can't go to \"$url\": use a path or an http(s) URL", 'Error');
        }
        throw new self($status, $url);
    }
}
