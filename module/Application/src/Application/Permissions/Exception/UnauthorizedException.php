<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */
namespace Application\Permissions\Exception;

/**
 * This exception indicates you are not logged in and the requested
 * action is only available to authenticated users.
 */
class UnauthorizedException extends Exception
{
}
