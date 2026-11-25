<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/** A MySQL error from a statement SpacePress ran on the WordPress connection (errno = server error code). */
final class DbError extends \RuntimeException
{
}
