<?php
/**
 * Validates string for suitability as a Perforce counter name.
 * Behaves exactly as key-name validator.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.2/1446446
 */

namespace P4\Validate;

class CounterName extends KeyName
{
    protected $allowRelative = false;    // REL
}
