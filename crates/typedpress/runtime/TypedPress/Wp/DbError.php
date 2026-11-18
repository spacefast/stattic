<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/** A MySQL error from a statement TypedPress ran on the WordPress connection (errno = server error code). */
final class DbError extends \RuntimeException
{
}
