<?php
/**
 * Tests for the queue module.
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace QueueTest;

use Application\Log\Writer\Mock as MockLog;
use ModuleTest\TestControllerCase;
use Zend\ServiceManager\ServiceManager;

class ModuleTest extends TestControllerCase
{
    public function setUp()
    {
        parent::setUp();

        // enable trigger disagnostics for these tests
        // @todo - remove this when trigger diagnostics is enabled by default in config
        $services = $this->getApplication()->getServiceManager();
        $services->get('queue')->getEventManager()->attach(new \Queue\Listener\Ping($services));
    }

    public function testSendPing()
    {
        $services = $this->getApplication()->getServiceManager();
        $logger   = $services->get('logger');
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();

        // subscribe to all queue events so as to eavesdrop
        $captured = array();
        $events->attach(
            '*',
            function ($event) use (&$captured) {
                $captured[] = $event;
            }
        );

        // eavesdrop on logger to catch errors
        $mock = new MockLog(array('ignore_ping_events' => false));
        $logger->addWriter($mock);

        // process queue, there are no tasks but we want to verify that listeners to 'worker.loop' did their job
        $this->processQueue();

        $this->assertSame(3, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('worker.shutdown', $captured[2]->getName());

        // ensure that ping file was created, but it is not an archive file since there is no archive trigger
        $data = $this->p4->run('fstat', array('-Oc', '//depot/swarm_storage/ping'))->getData();
        $this->assertSame(1, count($data));
        $this->assertSame('text', $data[0]['lbrType']);

        // check the log, it should contain the error
        $this->assertTrue(count($mock->events) >= 2);
        $this->assertSame(
            'Cannot send ping: Command failed: No archive trigger defined for //depot/swarm_storage/ping.',
            $mock->events[1]['message']
        );

        // verify that ping key exists and the error was set on the 'error' field
        $this->assertTrue(\Record\Key\GenericKey::exists('swarm-ping', $this->p4));
        $ping = \Record\Key\GenericKey::fetch('swarm-ping', $this->p4);
        $this->assertSame(
            'Command failed: No archive trigger defined for //depot/swarm_storage/ping.',
            $ping->get('error')
        );

        // ---- Phase 2: add archive trigger and try again ----
        // we will use our 'real' trigger script; to make it work we need to configure
        // it with swarm host and swarm token at the minimum though we can pass bogus
        // values as we don't care about creating the ping task
        $config = tempnam(DATA_PATH, 'trigger-config-');
        file_put_contents($config, "SWARM_HOST=localhost\nSWARM_TOKEN=0\n");

        $triggers = \P4\Spec\Triggers::fetch($this->superP4);
        $script   = "%quote%" . BASE_PATH . "/p4-bin/scripts/swarm-trigger.pl%quote%";
        $config   = "%quote%$config%quote%";
        $lines    = $triggers->getTriggers();
        $lines[]  = "test.archive     archive     //depot/swarm_storage/ping  \"$script -c $config -t ping -v %op% \"";
        $triggers->setTriggers($lines)->save();

        // force a reconnect as triggers seem to require it
        $this->p4->disconnect();

        // delete the key to start fresh
        $ping->delete();

        $captured     = array();
        $mock->events = array();
        $this->processQueue();

        $this->assertSame(3, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('worker.shutdown', $captured[2]->getName());

        // ensure that ping file was created and it has +X type
        $data = $this->p4->run('fstat', array('-Oc', '//depot/swarm_storage/ping'))->getData();
        $this->assertSame(1, count($data));
        $this->assertSame('text+X', $data[0]['lbrType']);
    }

    public function testReceivePing()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');

        // create ping record
        $ping = new \Record\Key\GenericKey($this->p4);
        $ping->setId('swarm-ping')->set('sendTime', $this->p4->getServerTime())->save();

        $queue->addTask('ping', '');
        $this->processQueue();

        // verify that the record has been updated
        $ping = \Record\Key\GenericKey::fetch('swarm-ping', $this->p4);
        $this->assertSame(0, strlen($ping->get('error')));
        $this->assertTrue($ping->get('receiveTime') > 0);
    }

    protected function processQueue()
    {
        // switch off the test client, in the real world workers don't run on the same client as the user
        $client = $this->p4->getClient();
        $this->p4->setClient(null);

        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $this->p4->setClient($client);
    }

    /**
     * Modify the configuration to inject test locations for depot storage, instead of using default ("//.swarm")
     *
     * This is necessary because in the test environment, creating "//.swarm" would be a bit messy.
     *
     * @param ServiceManager $services the service manager says you should wear more than the minimum 15 pieces of flair
     */
    protected function configureServiceManager(ServiceManager $services)
    {
        parent::configureServiceManager($services);
        $config                               = $services->get('config');
        $config['depot_storage']['base_path'] = '//depot/swarm_storage';
        $config['activity']['ignored_paths']  = array('//depot/swarm_storage');
        $services->setService('config', $config);
    }
}
