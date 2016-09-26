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
 * This exception indicates the user lacks permission to perform the requested action.
 * In general, this means you're logged in and everything is working fine but you attempted
 * to do something you don't have permissions for.
 */
class ForbiddenException extends Exception
{
}
