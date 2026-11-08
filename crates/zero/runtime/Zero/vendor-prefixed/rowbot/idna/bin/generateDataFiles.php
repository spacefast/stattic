<?php

declare(strict_types=1);

use Zero\Vendor\Rowbot\Idna\Bin\IdnaDataBuilder;
use Zero\Vendor\Rowbot\Idna\Bin\RegexBuilder;
use Zero\Vendor\Rowbot\Idna\Bin\ViramaDataBuilder;

const DS = DIRECTORY_SEPARATOR;
const ZERO_VENDOR_ROOT_DIR = __DIR__ . DS . '..';
const ZERO_VENDOR_OUTPUT_DIR = ZERO_VENDOR_ROOT_DIR . DS . 'resources';

require ZERO_VENDOR_ROOT_DIR . DS . 'vendor' . DS . 'autoload.php';

ViramaDataBuilder::buildHashMap(ZERO_VENDOR_OUTPUT_DIR);
RegexBuilder::buildRegexClass(ZERO_VENDOR_OUTPUT_DIR);
IdnaDataBuilder::buildHashMaps(ZERO_VENDOR_OUTPUT_DIR);
