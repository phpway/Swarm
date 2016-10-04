<?php
/**
 * Validates string for suitability as a Perforce user name.
 * Extends key-name validator to provide a place to customize
 * validation.
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.2/1446446
 */

namespace P4\Validate;

class UserName extends KeyName
{
    protected $allowSlashes  = true;     // SLASH
    protected $allowRelative = true;     // REL
}
