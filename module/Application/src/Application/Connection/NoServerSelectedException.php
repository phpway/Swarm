<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */
namespace Application\Connection;

/**
 * This exception indicates we are operating in multi-p4-server
 * mode, but we don't know what server to connect to.
 */
class NoServerSelectedException extends \Exception
{
}
