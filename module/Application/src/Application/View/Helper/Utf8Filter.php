<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\View\Helper;

use P4\Filter\Utf8;
use Zend\View\Helper\AbstractHelper;

class Utf8Filter extends AbstractHelper
{
    protected $utf8Filter = null;

    /**
     * Filter utf-8 input, invalid UTF-8 byte sequences will be replaced with
     * an inverted question mark.
     *
     * @param   string|null     $value  the utf-8 input to filter
     * @returns string          the filtered result
     */
    public function __invoke($value)
    {
        $this->utf8Filter = $this->utf8Filter ?: new Utf8;
        return $this->utf8Filter->filter($value);
    }
}
