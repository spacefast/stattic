<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Zero\Vendor\Nette\Utils;

use Zero\Vendor\Nette;

if (false) {
	/** @deprecated use Zero\Vendor\Nette\HtmlStringable */
	interface IHtmlString extends \Zero\Vendor\Nette\HtmlStringable
	{
	}
} elseif (!interface_exists(IHtmlString::class)) {
	class_alias(\Zero\Vendor\Nette\HtmlStringable::class, IHtmlString::class);
}

namespace Zero\Vendor\Nette\Localization;

if (false) {
	/** @deprecated use Zero\Vendor\Nette\Localization\Translator */
	interface ITranslator extends Translator
	{
	}
} elseif (!interface_exists(ITranslator::class)) {
	class_alias(Translator::class, ITranslator::class);
}
