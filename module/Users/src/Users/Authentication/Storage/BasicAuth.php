<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Users\Authentication\Storage;

use Zend\Authentication\Storage\NonPersistent;
use Zend\Stdlib\RequestInterface;

/**
 * BasicAuth storage provider can interpret a request and retrieve basic authentication credentials
 *
 * @package Users\Authentication\Storage
 */
class BasicAuth extends NonPersistent
{
    /**
     * Checks the request for basic-auth credentials and writes them to NonPersistent storage if found
     *
     * @param RequestInterface $request
     */
    public function __construct(RequestInterface $request)
    {
        $authHeader = $request->getHeaders('authorization');

        if ($authHeader) {
            $authValue                = $authHeader->getFieldValue();
            list($type, $credentials) = explode(' ', $authValue, 2) + array(null, null);

            if (strtolower($type) == 'basic') {
                $credentials               = base64_decode(trim($credentials), true);
                list($username, $password) = explode(':', $credentials, 2) + array(null, null);

                $this->write(array('id' => $username, 'ticket' => $password));
            }
        }
    }
}
