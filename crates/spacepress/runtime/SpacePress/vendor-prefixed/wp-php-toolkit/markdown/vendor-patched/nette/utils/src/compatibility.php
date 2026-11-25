<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace SpacePress\Vendor\Nette\Utils;

use SpacePress\Vendor\Nette;

if (false) {
	/** @deprecated use SpacePress\Vendor\Nette\HtmlStringable */
	interface IHtmlString extends \SpacePress\Vendor\Nette\HtmlStringable
	{
	}
} elseif (!interface_exists(IHtmlString::class)) {
	class_alias(\SpacePress\Vendor\Nette\HtmlStringable::class, IHtmlString::class);
}

namespace SpacePress\Vendor\Nette\Localization;

if (false) {
	/** @deprecated use SpacePress\Vendor\Nette\Localization\Translator */
	interface ITranslator extends Translator
	{
	}
} elseif (!interface_exists(ITranslator::class)) {
	class_alias(Translator::class, ITranslator::class);
}
