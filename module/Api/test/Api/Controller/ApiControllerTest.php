<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApiTest\Controller;

use Api\Controller\IndexController;
use ModuleTest\TestApiController;
use P4\Spec\Job;
use Users\Model\User;
use Zend\Http\Header\Authorization;
use Zend\Json\Json;
use Zend\ServiceManager\ServiceManager;
use Zend\View\Model\JsonModel;

class ApiControllerTest extends TestApiController
{
    public function testGetVersion()
    {
        $this->dispatch('/api/v1/version');
        $body    = $this->getResponse()->getBody();
        $version = Json::decode($body, true);

        $this->assertResponseStatusCode(200);

        $this->assertSame(
            array(
                'version' => VERSION,
                'year'    => current(explode('.', VERSION_RELEASE)),
            ),
            $version
        );

        $this->dispatch('/api/version');
        $this->assertResponseStatusCode(200);

        $this->dispatch('/api/v1.1/version');
        $body    = $this->getResponse()->getBody();
        $version = Json::decode($body, true);

        $this->assertResponseStatusCode(200);

        $this->assertSame(
            array(
                'apiVersions' => array(1, 1.1, 1.2, 2, 3),
                'version'     => VERSION,
                'year'        => current(explode('.', VERSION_RELEASE))
            ),
            $version
        );
    }

    /**
     * Test that the catch-all is working as expected.
     *
     * @param string    $route      route to test
     * @param integer   $status     expected HTTP status code
     * @param string    $fullAction expected controller and action name, FQCN\Controller::actionName
     * @param bool      $json       default: true. Confirm response contains valid JSON if true,
     *                              or invalid JSON if false.
     * @dataProvider    dailyCatch
     */
    public function testExpectedResponses(
        $route,
        $status,
        $fullAction = 'Api\Controller\Index::notFound',
        $json = true
    ) {
        $result = $this->dispatch($route);
        $this->assertResponseStatusCode($status);

        // check that the expected controller delivered the response
        $event      = $this->getApplication()->getMvcEvent();
        $routeMatch = $event->getRouteMatch();
        $this->assertSame(
            $fullAction,
            $routeMatch->getParam('controller') . '::' . $routeMatch->getParam('action')
        );

        // check that the response is in the expected format
        if ($json) {
            $this->assertTrue(is_array(json_decode($result, true)), 'Received invalid JSON output');
        } else {
            $this->assertFalse(
                is_array(json_decode($result, true)),
                'Received valid JSON output, but expected invalid'
            );
        }
    }

    public function testJobGotoFail()
    {
        $job = new Job;
        $job->setId('api/foo');
        $job->setDescription('test');
        $job->save();

        $this->testExpectedResponses('/api/foo', 404);

        $this->resetApplication();

        $job = new Job;
        $job->setId('apiarist');
        $job->setDescription('test');
        $job->save();

        $this->testExpectedResponses('/apiarist', 302, 'Application\Controller\Index::goto', false);
    }

    public function testUserGotoFail()
    {
        $user = new User;
        $user->set(
            array(
                'User'      => 'api',
                'Email'     => 'testy@example.com',
                'FullName'  => 'Testy McTesterson',
                'Password'  => '123',
            )
        )->save();

        $this->testExpectedResponses('/api', 404);

        $this->resetApplication();

        $user = new User;
        $user->set(
            array(
                'User'      => 'apiary',
                'Email'     => 'testy@example.com',
                'FullName'  => 'Testy McTesterson',
                'Password'  => '123',
            )
        )->save();

        $this->testExpectedResponses('/apiary', 302, 'Application\Controller\Index::goto', false);
    }

    public function testPrepareErrorModel()
    {
        $result = new JsonModel(
            array(
                'error' => 'Hello there!',
                'messages' => array(
                    'foo' => array('callback' => 'something bar')
                )
            )
        );

        $controller = new IndexController;
        $this->assertSame(
            $controller->prepareErrorModel($result)->getVariables(),
            array(
                'error' => 'Hello there!',
                'details' => array('foo' => 'something bar')
            )
        );

        try {
            $controller->prepareErrorModel(new JsonModel);
        } catch (\LogicException $e) {
            $this->assertTrue(true);
        }
    }

    public function testPrepareSuccessModel()
    {
        $controller = new IndexController;
        $data       = array(
            'isValid' => true,
            'foo'     => 'bar'
        );

        $this->assertSame(
            $controller->prepareSuccessModel($data)->getVariables(),
            array('foo' => 'bar')
        );

        $this->assertSame(
            $controller->prepareSuccessModel(new JsonModel($data))->getVariables(),
            array('foo' => 'bar')
        );

        try {
            $controller->prepareSuccessModel(false);
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    public function dailyCatch()
    {
        return array(
            array('/api',  404),
            array('/api/', 404),
            array('/api/v1/test/nonexistent/reallynotthere/toldya',  404),
            array('/api/v1/test/nonexistent/reallynotthere/toldya/', 404),
            array('/api/version',     200, 'Api\Controller\Index::version'),
            array('/api/v1/version',  200, 'Api\Controller\Index::version'),
            array('/api/v1/version/', 200, 'Api\Controller\Index::version'),
            array('/apiary', 404, 'Application\Controller\Index::goto', false), // testing for proper handling
            array('/',       200, 'Users\Controller\Index::index',      false), // testing for HTML output
        );
    }

    public function testBasicAuthenticationFailure()
    {
        $request = $this->getRequest();
        $headers = $request->getHeaders();

        $headers->addHeader(Authorization::fromString('Authorization: Basic ' . base64_encode('testuser:testpass')));
        $request->setHeaders($headers);

        $result = $this->dispatch('/api/v1/version');
        $result = json_decode($result, true);

        $this->assertSame(401, $this->getResponse()->getStatusCode());
        $this->assertSame(array('error' => 'Unauthorized'), $result);
    }

    /**
     * We need to reconfigure the service manager to test basic auth
     * This is because normally the tests fake out a logged in user
     * We need to undo this fakery and restore the original factories.
     *
     * @param ServiceManager $services
     */
    public function configureServiceManager(ServiceManager $services)
    {
        parent::configureServiceManager($services);

        if (strpos($this->getName(), 'testBasicAuthentication') === 0) {
            $config = $services->get('config');

            // need to copy the test p4d server's rsh port into config
            // so that the p4_user factory can connect to it
            $config['p4']['port'] = $this->p4Params['port'];
            $services->setService('config', $config);

            // restore the original 'auth' and 'p4_user' factories
            $services->setFactory('auth', $config['service_manager']['factories']['auth']);
            $services->setFactory('p4_user', $config['service_manager']['factories']['p4_user']);
        }
    }
}
