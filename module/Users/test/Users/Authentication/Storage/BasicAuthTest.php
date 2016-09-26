<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace UsersTest\Controller;

use P4Test\TestCase;
use Users\Authentication\Storage\BasicAuth;
use Zend\Http\Header\Authorization;
use Zend\Http\PhpEnvironment\Request;

class BasicAuthTest extends TestCase
{
    /**
     * Extend parent to additionally init modules we will use.
     */
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Users'  => BASE_PATH . '/module/Users/src/Users'
                    )
                )
            )
        );
    }

    /**
     * @dataProvider basicAuthStorageUsers
     */
    public function testBasicAuthenticationStorage($type, $user, $password, $result)
    {
        $auth    = $type . ' ' . base64_encode($user . ':' . $password);
        $request = new Request;
        $headers = $request->getHeaders();
        $headers->addHeader(Authorization::fromString('Authorization: ' . $auth));
        $request->setHeaders($headers);

        $basicAuthStorage = new BasicAuth($request);

        $this->assertSame($result, $basicAuthStorage->read());
    }

    public function basicAuthStorageUsers()
    {
        return array(
            array(
                'type'     => 'Basic',
                'user'     => 'testuser',
                'password' => 'testpass',
                array('id' => 'testuser', 'ticket' => 'testpass')
            ),
            array('type' => 'Digest', 'user' => '', 'password' => '', null)
        );
    }
}
