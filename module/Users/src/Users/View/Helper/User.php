<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Users\View\Helper;

use Zend\View\Helper\AbstractHelper;

class User extends AbstractHelper
{
    /**
     * Provides access to the current user from the view.
     */
    public function __invoke()
    {
        try {
            return $this->getView()
                        ->getHelperPluginManager()
                        ->getServiceLocator()
                        ->get('user');
        } catch (\Exception $e) {
            return new \Users\Model\User;
        }
    }
}
