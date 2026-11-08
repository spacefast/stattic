<?php

declare(strict_types=1);

namespace Zero\Vendor\Rowbot\URL;

use Zero\Vendor\Rowbot\URL\State\AuthorityState;
use Zero\Vendor\Rowbot\URL\State\FileHostState;
use Zero\Vendor\Rowbot\URL\State\FileSlashState;
use Zero\Vendor\Rowbot\URL\State\FileState;
use Zero\Vendor\Rowbot\URL\State\FragmentState;
use Zero\Vendor\Rowbot\URL\State\HostnameState;
use Zero\Vendor\Rowbot\URL\State\HostState;
use Zero\Vendor\Rowbot\URL\State\NoSchemeState;
use Zero\Vendor\Rowbot\URL\State\OpaquePathState;
use Zero\Vendor\Rowbot\URL\State\PathOrAuthorityState;
use Zero\Vendor\Rowbot\URL\State\PathStartState;
use Zero\Vendor\Rowbot\URL\State\PathState;
use Zero\Vendor\Rowbot\URL\State\PortState;
use Zero\Vendor\Rowbot\URL\State\QueryState;
use Zero\Vendor\Rowbot\URL\State\RelativeSlashState;
use Zero\Vendor\Rowbot\URL\State\RelativeState;
use Zero\Vendor\Rowbot\URL\State\SchemeStartState;
use Zero\Vendor\Rowbot\URL\State\SchemeState;
use Zero\Vendor\Rowbot\URL\State\SpecialAuthorityIgnoreSlashesState;
use Zero\Vendor\Rowbot\URL\State\SpecialAuthoritySlashesState;
use Zero\Vendor\Rowbot\URL\State\SpecialRelativeOrAuthorityState;
use Zero\Vendor\Rowbot\URL\State\State;

enum ParserState
{
    case SCHEME_START;
    case SCHEME;
    case NO_SCHEME;
    case SPECIAL_RELATIVE_OR_AUTHORITY;
    case PATH_OR_AUTHORITY;
    case RELATIVE;
    case RELATIVE_SLASH;
    case SPECIAL_AUTHORITY_SLASHES;
    case SPECIAL_AUTHORITY_IGNORE_SLASHES;
    case AUTHORITY;
    case HOST;
    case HOSTNAME;
    case PORT;
    case FILE;
    case FILE_SLASH;
    case FILE_HOST;
    case PATH_START;
    case PATH;
    case OPAQUE_PATH;
    case QUERY;
    case FRAGMENT;

    public static function createHandlerFor(self $state): State
    {
        return match ($state) {
            self::SCHEME_START                     => new SchemeStartState(),
            self::SCHEME                           => new SchemeState(),
            self::NO_SCHEME                        => new NoSchemeState(),
            self::SPECIAL_RELATIVE_OR_AUTHORITY    => new SpecialRelativeOrAuthorityState(),
            self::PATH_OR_AUTHORITY                => new PathOrAuthorityState(),
            self::RELATIVE                         => new RelativeState(),
            self::RELATIVE_SLASH                   => new RelativeSlashState(),
            self::SPECIAL_AUTHORITY_SLASHES        => new SpecialAuthoritySlashesState(),
            self::SPECIAL_AUTHORITY_IGNORE_SLASHES => new SpecialAuthorityIgnoreSlashesState(),
            self::AUTHORITY                        => new AuthorityState(),
            self::HOST                             => new HostState(),
            self::HOSTNAME                         => new HostnameState(),
            self::PORT                             => new PortState(),
            self::FILE                             => new FileState(),
            self::FILE_SLASH                       => new FileSlashState(),
            self::FILE_HOST                        => new FileHostState(),
            self::PATH_START                       => new PathStartState(),
            self::PATH                             => new PathState(),
            self::OPAQUE_PATH                      => new OpaquePathState(),
            self::QUERY                            => new QueryState(),
            self::FRAGMENT                         => new FragmentState(),
        };
    }
}
