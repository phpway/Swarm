<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\Router;

class Regex extends \Zend\Mvc\Router\Http\Regex
{
    /**
     * Extend parent to preserve slashes '/'.
     *
     * @see    Route::assemble()
     * @param  array $params
     * @param  array $options
     * @return mixed
     */
    public function assemble(array $params = array(), array $options = array())
    {
        return str_ireplace('%2f', '/', parent::assemble($params, $options));
    }
}
