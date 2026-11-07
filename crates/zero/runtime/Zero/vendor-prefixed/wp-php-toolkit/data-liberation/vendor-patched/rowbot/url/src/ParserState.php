<?php

declare( strict_types=1 );

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

class ParserState {
	public const SCHEME_START = 'scheme_start';
	public const SCHEME = 'scheme';
	public const NO_SCHEME = 'no_scheme';
	public const SPECIAL_RELATIVE_OR_AUTHORITY = 'special_relative_or_authority';
	public const PATH_OR_AUTHORITY = 'path_or_authority';
	public const RELATIVE = 'relative';
	public const RELATIVE_SLASH = 'relative_slash';
	public const SPECIAL_AUTHORITY_SLASHES = 'special_authority_slashes';
	public const SPECIAL_AUTHORITY_IGNORE_SLASHES = 'special_authority_ignore_slashes';
	public const AUTHORITY = 'authority';
	public const HOST = 'host';
	public const HOSTNAME = 'hostname';
	public const PORT = 'port';
	public const FILE = 'file';
	public const FILE_SLASH = 'file_slash';
	public const FILE_HOST = 'file_host';
	public const PATH_START = 'path_start';
	public const PATH = 'path';
	public const OPAQUE_PATH = 'opaque_path';
	public const QUERY = 'query';
	public const FRAGMENT = 'fragment';

	public static function createHandlerFor( $state ): State {
		switch ( $state ) {
			case self::SCHEME_START:
				return new SchemeStartState();
			case self::SCHEME:
				return new SchemeState();
			case self::NO_SCHEME:
				return new NoSchemeState();
			case self::SPECIAL_RELATIVE_OR_AUTHORITY:
				return new SpecialRelativeOrAuthorityState();
			case self::PATH_OR_AUTHORITY:
				return new PathOrAuthorityState();
			case self::RELATIVE:
				return new RelativeState();
			case self::RELATIVE_SLASH:
				return new RelativeSlashState();
			case self::SPECIAL_AUTHORITY_SLASHES:
				return new SpecialAuthoritySlashesState();
			case self::SPECIAL_AUTHORITY_IGNORE_SLASHES:
				return new SpecialAuthorityIgnoreSlashesState();
			case self::AUTHORITY:
				return new AuthorityState();
			case self::HOST:
				return new HostState();
			case self::HOSTNAME:
				return new HostnameState();
			case self::PORT:
				return new PortState();
			case self::FILE:
				return new FileState();
			case self::FILE_SLASH:
				return new FileSlashState();
			case self::FILE_HOST:
				return new FileHostState();
			case self::PATH_START:
				return new PathStartState();
			case self::PATH:
				return new PathState();
			case self::OPAQUE_PATH:
				return new OpaquePathState();
			case self::QUERY:
				return new QueryState();
			case self::FRAGMENT:
				return new FragmentState();
		}
	}
}
