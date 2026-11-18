<?php

declare(strict_types=1);

use TypedPress\Vendor\Rowbot\Idna\Bin\IdnaDataBuilder;
use TypedPress\Vendor\Rowbot\Idna\Bin\RegexBuilder;
use TypedPress\Vendor\Rowbot\Idna\Bin\ViramaDataBuilder;

const DS = DIRECTORY_SEPARATOR;
const TP_VENDOR_ROOT_DIR = __DIR__ . DS . '..';
const TP_VENDOR_OUTPUT_DIR = TP_VENDOR_ROOT_DIR . DS . 'resources';

require TP_VENDOR_ROOT_DIR . DS . 'vendor' . DS . 'autoload.php';

ViramaDataBuilder::buildHashMap(TP_VENDOR_OUTPUT_DIR);
RegexBuilder::buildRegexClass(TP_VENDOR_OUTPUT_DIR);
IdnaDataBuilder::buildHashMaps(TP_VENDOR_OUTPUT_DIR);
