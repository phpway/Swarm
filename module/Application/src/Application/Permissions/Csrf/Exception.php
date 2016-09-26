<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\Permissions\Csrf;

use Application\Permissions\Exception\ForbiddenException;

/**
 * This exception indicates the CSRF token is missing or invalid
 */
class Exception extends ForbiddenException
{
}
