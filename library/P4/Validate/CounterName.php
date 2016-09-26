<?php
/**
 * Validates string for suitability as a Perforce counter name.
 * Behaves exactly as key-name validator.
 *
 * @copyright   2011 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace P4\Validate;

class CounterName extends KeyName
{
    protected $allowRelative = false;    // REL
}
