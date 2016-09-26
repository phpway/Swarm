<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\View\Helper;

use Application\Escaper\Escaper;
use Zend\View\Helper\Escaper\AbstractHelper;

class EscapeFullUrl extends AbstractHelper
{
    /**
     * Escape a value for current escaping strategy
     *
     * @param string $value
     * @return string
     */
    protected function escape($value)
    {
        $escaper = $this->getEscaper();
        if (!method_exists($escaper, 'escapeFullUrl')) {
            $this->setEscaper(new Escaper);
        }

        return $this->getEscaper()->escapeFullUrl($value);
    }
}
