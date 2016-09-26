<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\View\Helper;

use Application\Escaper\Escaper;
use Zend\View\Helper\AbstractHelper;

class Csrf extends AbstractHelper
{
    /**
     * Returns the CSRF token in use.
     *
     * @return string   the CSRF token
     */
    public function __invoke()
    {
        $services = $this->getView()->getHelperPluginManager()->getServiceLocator();
        $csrf     = $services->get('csrf');
        $escaper  = new Escaper;
        return $escaper->escapeHtml($csrf->getToken());
    }
}
