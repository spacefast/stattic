<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace TypedPress\Vendor\Nette\Utils;

use TypedPress\Vendor\Nette;

if (false) {
	/** @deprecated use TypedPress\Vendor\Nette\HtmlStringable */
	interface IHtmlString extends \TypedPress\Vendor\Nette\HtmlStringable
	{
	}
} elseif (!interface_exists(IHtmlString::class)) {
	class_alias(\TypedPress\Vendor\Nette\HtmlStringable::class, IHtmlString::class);
}

namespace TypedPress\Vendor\Nette\Localization;

if (false) {
	/** @deprecated use TypedPress\Vendor\Nette\Localization\Translator */
	interface ITranslator extends Translator
	{
	}
} elseif (!interface_exists(ITranslator::class)) {
	class_alias(Translator::class, ITranslator::class);
}
