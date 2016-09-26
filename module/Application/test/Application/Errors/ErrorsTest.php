<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Controller;

use ModuleTest\TestControllerCase;
use Zend\ServiceManager\ServiceManager;

class ErrorsTest extends TestControllerCase
{
    /**
     * Test error responses for expected output.
     *
     * Here be dragons:
     *
     * - requireLogin and loggedIn parameters are caught and interpreted by $this->configureServiceManager()
     * - All responses are json-decoded; a null $compareTo implicitly means "HTML was returned"
     * - If $compareTo is an array, it is loosely checked one level down from the top. Not a true deep check.
     *   (this is to make it easier to do quick checks like $compareTo[0]['id'] without having to check dateAdded)
     *
     * @dataProvider errorGenerator
     */
    public function testError($route, $requireLogin, $loggedIn, $status, $compareTo, $deepCompare = false)
    {
        $result     = $this->dispatch($route);
        $response   = $this->getResponse();
        $jsonResult = json_decode($result, true);

        $this->assertSame($status, $response->getStatusCode());

        // check that the result matches the expectation
        // in the case of an array expectation with deepCompare == false, do a quick nested check for matching fields
        if (!is_array($compareTo) || $deepCompare) {
            $this->assertSame($compareTo, $jsonResult);
        } else {
            foreach ($compareTo as $key => $value) {
                if (!is_array($value)) {
                    $this->assertSame($value, $jsonResult[$key]);
                    continue;
                }

                foreach ($value as $innerKey => $innerValue) {
                    $this->assertSame($innerValue, $jsonResult[$key][$innerKey]);
                }
            }
        }
    }

    public function errorGenerator()
    {
        return array(
            array(
                '/?format=json',
                false,
                false,
                406,
                array('error' => 'Not Acceptable')
            ),
            array(
                '/404page',
                false,
                true,
                404,
                null
            ),
            array(
                '/404page?format=json',
                false,
                true,
                404,
                array('error' => 'Not Found')
            ),
            array(
                '/users',
                false,
                true,
                200,
                array(
                    array('User' => 'admin'),
                    array('User' => 'nonadmin'),
                    array('User' => 'tester')
                )
            ),
            array(
                '/users?format=json',
                false,
                false,
                401,
                array(
                    'error' => 'Unauthorized'
                ),
                true
            ),
            array(
                '/',
                true,
                true,
                200,
                null
            ),
            array(
                '/reviews?format=json',
                true,
                false,
                401,
                array('error' => 'Unauthorized'),
                true
            )
        );
    }

    public function configureServiceManager(ServiceManager $serviceManager)
    {
        parent::configureServiceManager($serviceManager);

        $name          = $this->getName(false);
        $providerIndex = $this->getName(true);

        // exit early if we're not meant to be meddling with the service manager
        if ($name != 'testError' || strpos($providerIndex, '#') === false) {
            return;
        }

        $config      = $serviceManager->get('config');
        $annotations = $this->getAnnotations();

        // override the require_login setting and simulate an unauthenticated user, as requested by data provider
        $providerIndex    = substr($providerIndex, strpos($providerIndex, '#') + 1);
        $providerName     = $annotations['method']['dataProvider'][0];
        $providerDataSets = $this->$providerName();

        $providerData = $providerDataSets[$providerIndex];

        // set the require_login flag to the specified value
        $config['security']['require_login'] = $providerData[1];
        $serviceManager->setService('config', $config);

        // If the user shouldn't be logged in, let's ensure that they aren't, by restoring the 'auth' and 'p4_user'
        // factories to the Swarm defaults (rather than the unit test bootstrap's values)
        if ($providerData[2] == false) {
            $serviceManager->setFactory('auth', $config['service_manager']['factories']['auth']);
            $serviceManager->setFactory('p4_user', $config['service_manager']['factories']['p4_user']);
        }
    }
}
