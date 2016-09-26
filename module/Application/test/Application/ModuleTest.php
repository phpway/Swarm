<?php
/**
 * Tests for the application module.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest;

use Activity\Model\Activity;
use Application\Log\Writer\Mock as MockLog;
use Application\Module as ApplicationModule;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\User;
use Projects\Model\Project;
use Reviews\Model\Review;
use Zend\EventManager\Event;
use Zend\Json\Json;
use Zend\Stdlib\Parameters;

class ModuleTest extends TestControllerCase
{
    public function setUp()
    {
        parent::setUp();

        // add module-test namespace
        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'ApplicationTest' => BASE_PATH . '/module/Application/test/Application',
                    )
                )
            )
        );
    }

    public function testSwarmUrlProperties()
    {
        if (!$this->p4->isServerMinVersion('2013.1')) {
            $this->markTestSkipped('Requires P4D 2013.1+');
        }

        $services  = $this->getApplication()->getServiceManager();
        $queue     = $services->get('queue');
        $mainKey   = ApplicationModule::PROPERTY_SWARM_URL;
        $commitKey = ApplicationModule::PROPERTY_SWARM_COMMIT_URL;

        // properties should be null to start
        $mainValue   = $this->p4->run('property', array('-l', '-n', $mainKey))->getData(0, 'value');
        $commitValue = $this->p4->run('property', array('-l', '-n', $commitKey))->getData(0, 'value');
        $this->assertSame(false, $mainValue);
        $this->assertSame(false, $commitValue);

        // after the worker runs the main property should be set
        $this->runWorker();
        $mainValue   = $this->p4->run('property', array('-l', '-n', $mainKey))->getData(0, 'value');
        $commitValue = $this->p4->run('property', array('-l', '-n', $commitKey))->getData(0, 'value');
        $this->assertSame('http://localhost', $mainValue);
        $this->assertSame(false, $commitValue);

        // if we change the properties the main one should get fixed
        $this->p4->run('property', array('-a', '-n', $mainKey,   '-v', 'bad', '-s0'));
        $this->p4->run('property', array('-a', '-n', $commitKey, '-v', 'bad', '-s0'));
        $this->runWorker();
        $mainValue   = $this->p4->run('property', array('-l', '-n', $mainKey))->getData(0, 'value');
        $commitValue = $this->p4->run('property', array('-l', '-n', $commitKey))->getData(0, 'value');
        $this->assertSame('http://localhost', $mainValue);
        $this->assertSame('bad', $commitValue);

        // if we fake out an edge server, the property should not get fixed
        $reflection = new \ReflectionClass('P4\Connection\AbstractConnection');
        $property   = $reflection->getProperty('info');
        $property->setAccessible(true);
        $property->setValue($this->p4, array('serverServices' => 'edge-server') + $this->p4->getInfo());
        $this->p4->run('property', array('-a', '-n', $mainKey,   '-v', 'bad', '-s0'));
        $this->runWorker();
        $mainValue = $this->p4->run('property', array('-l', '-n', $mainKey))->getData(0, 'value');
        $this->assertSame('bad', $mainValue);

        // if we fake out a commit server, both properties should get set
        $property->setValue($this->p4, array('serverServices' => 'commit-server') + $this->p4->getInfo());
        $this->runWorker();
        $mainValue   = $this->p4->run('property', array('-l', '-n', $mainKey))->getData(0, 'value');
        $commitValue = $this->p4->run('property', array('-l', '-n', $commitKey))->getData(0, 'value');
        $this->assertSame('http://localhost', $mainValue);
        $this->assertSame('http://localhost', $commitValue);
    }

    protected function runWorker()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
    }
}
