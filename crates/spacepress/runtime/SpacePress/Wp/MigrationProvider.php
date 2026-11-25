<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * Contract for opt-in migration plugins (such as `spacepress/danger`) that plug
 * into {@see Migrator}. Core schema migrations remain strictly forward-only; providers that execute
 * destructive operations must declare `cliOnly() === true` so they never run on HTTP boot.
 */
interface MigrationProvider
{
    /** Stable identifier for this provider (e.g. "destructive-migrations"). */
    public function id(): string;

    /** When true, Migrator refuses to invoke this provider during HTTP or activation requests. */
    public function cliOnly(): bool;

    /** Human-readable description of what this provider enables. */
    public function describe(): string;
}
