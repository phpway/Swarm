<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\View\Http;

use Zend\Mvc\MvcEvent;
use Zend\View\Model\JsonModel;

class RouteNotFoundStrategy extends \Zend\Mvc\View\Http\RouteNotFoundStrategy
{
    /**
     * Extended to leave JSON models alone
     *
     * @param  MvcEvent $e
     * @return void
     */
    public function prepareNotFoundViewModel(MvcEvent $e)
    {
        if ($e->getResult() instanceof JsonModel) {
            return;
        }

        return parent::prepareNotFoundViewModel($e);
    }
}
