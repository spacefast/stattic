<?php

declare(strict_types=1);

namespace Zero\Wp;

/** A MySQL error from a statement Zero ran on the WordPress connection (errno = server error code). */
final class DbError extends \RuntimeException
{
}
