<?php
/**
 * Exception to be thrown when a resolve error occurs.
 * Holds the associated Connection instance and result object.
 *
 * @copyright   2011 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace P4\Connection\Exception;

use P4\Spec\Change;

class ConflictException extends CommandException
{
    /**
     * Returns a Change object for the changelist the conflict files live in.
     *
     * @return  Change  object for the changelist with conflict files
     */
    public function getChange()
    {
        preg_match(
            '/submit -c ([0-9]+)/',
            implode($this->getResult()->getErrors()),
            $matches
        );

        return Change::fetch($matches[1], $this->getConnection());
    }
}
