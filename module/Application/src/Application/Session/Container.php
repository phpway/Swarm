<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\Session;

use Zend\Session\Exception\InvalidArgumentException as SessionInvalidArgumentException;
use Zend\Session\Container as ZendSessionContainer;
use Zend\Stdlib\ArrayObject;

class Container extends ZendSessionContainer
{
    protected static $managerDefaultClass = 'Application\Session\SessionManager';

    /**
     * Extend parent to NOT start the session when the instance is created.
     *
     * @param   string          $name       container name
     * @param   SessionManager  $manager    optional, session manager to attach the container to
     */
    public function __construct($name = 'Default', SessionManager $manager = null)
    {
        if (!preg_match('/^[a-z][a-z0-9_\\\]+$/i', $name)) {
            throw new SessionInvalidArgumentException(
                'Name passed to container is invalid; must consist of alphanumerics, backslashes and underscores only'
            );
        }

        $this->name = $name;
        $this->setManager($manager);

        // Create namespace
        ArrayObject::__construct(array(), ArrayObject::ARRAY_AS_PROPS);
    }
}
