<?php
/**
 * Validates string for suitability as a Perforce spec name.
 * Extends key-name validator to disallow positional specifiers.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.2/1446446
 */

namespace P4\Validate;

class SpecName extends KeyName
{
    protected $allowPositional = false;     // NOPERCENT
}
