<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace QueueTest\Controller;

use ModuleTest\TestControllerCase;
use Zend\Json\Json;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Try pushing some items into the queue.
     */
    public function testQueue()
    {
        for ($i = 0; $i < 10; $i++) {
            $this->addToQueue("test,$i");
        }

        // verify queue contains 10 tasks.
        $this->dispatch('/queue/status');

        // verify output
        $data = Json::decode($this->getResponse()->getBody());
        $this->assertSame(10, $data->tasks);
    }

    /**
     * Verify worker runs in background (closes connection)
     */
    public function testWorker()
    {
        $this->getRequest()->getQuery()->set('retire', 1);
        $this->dispatch('/queue/worker');
        $header = $this->getResponse()->getHeaders()->get('connection');
        $this->assertSame('Connection: close', $header->toString());
    }

    /**
     * Try worker debug mode
     */
    public function testWorkerDebug()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $output = $this->dispatch('/queue/worker');
        $this->assertTrue(strpos($output, 'Worker 1 startup')  !== false);
        $this->assertTrue(strpos($output, 'Worker 1 idle')     !== false);
        $this->assertTrue(strpos($output, 'Worker 1 shutdown') !== false);
    }

    /**
     * Verify slot/locking behavior (push worker to slot 2)
     */
    public function testWorkerSlots()
    {
        // occupy slot 1
        $config = $this->getApplication()->getConfig();
        $path   = DATA_PATH . '/queue/workers';
        mkdir($path, 0700, true);
        $slot = fopen($path . '/1', 'c');
        $lock = flock($slot, LOCK_EX | LOCK_NB);

        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $output = $this->dispatch('/queue/worker');
        $this->assertTrue(strpos($output, 'Worker 2 startup')  !== false);
    }

    /**
     * Verify slot/locking behavior (use all slots)
     */
    public function testWorkerLimit()
    {
        // occupy slots 1,2,3
        $config = $this->getApplication()->getConfig();
        $path   = DATA_PATH . '/queue/workers';
        mkdir($path, 0700, true);
        $slot1 = fopen($path . '/1', 'c');
        $slot2 = fopen($path . '/2', 'c');
        $slot3 = fopen($path . '/3', 'c');
        flock($slot1, LOCK_EX | LOCK_NB);
        flock($slot2, LOCK_EX | LOCK_NB);
        flock($slot3, LOCK_EX | LOCK_NB);

        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $output = $this->dispatch('/queue/worker');
        $this->assertTrue(strpos($output, 'All worker slots (3) in use') !== false);
    }

    public function testQueuedJob()
    {
        // make a p4 job.
        $job = new \P4\Spec\Job($this->p4);
        $job->set('Description', 'this is a test');
        $job->save();

        // push task into queue.
        $this->addToQueue('job,' . $job->getId());

        // connect to job event
        $services = $this->getApplication()->getServiceManager();
        $events   = $services->get('queue')->getEventManager();
        $events->attach(
            'task.job',
            function ($event) {
                print "job event triggered";
            }
        );

        // process queue (kick off worker)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $output = $this->dispatch('/queue/worker');
        $this->assertTrue(strpos($output, 'Worker 1 event')      !== false);
        $this->assertTrue(strpos($output, 'job000001')           !== false);
        $this->assertTrue(strpos($output, 'job event triggered') !== false);
    }

    public function testTaskData()
    {
        // try basic front-door task entry
        $queue = $this->getApplication()->getServiceManager()->get('queue');
        $queue->addTask('type', 'id', array(1, 2, 3));
        $task = $queue->grabTask();
        unset($task['time'], $task['file']);
        $this->assertSame(
            array(
                'type'  => 'type',
                'id'    => 'id',
                'data'  => array(1, 2, 3)
            ),
            $task
        );

        // try adding a task with extra data
        // (ensure data looks the way we think it should)
        $this->addToQueue("foo,1\n{\"foo\":\"bar\"}");

        // make sure data comes back out again
        $task = $queue->grabTask();
        unset($task['time'], $task['file']);
        $this->assertSame(
            array(
                'type'  => 'foo',
                'id'    => '1',
                'data'  => array('foo' => 'bar')
            ),
            $task
        );

        // try with bogus data (invalid json)
        $this->addToQueue("bar,2\n{laksdjf}");
        $task = $queue->grabTask();
        $this->assertSame(array(), $task['data']);

        // valid looking, but not valid UTF-8
        $this->addToQueue("bar,2\n\"\xC1\xBF\"");
        $task = $queue->grabTask();
        $this->assertSame(defined('JSON_C_VERSION') ? array("\xC1\xBF") : array(), $task['data']);
    }

    public function testFutureTask()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $config   = $queue->getConfig();

        // queue a task to run 2 seconds in the future
        $future = time() + 2;
        $queue->addTask('test', '123', array(), $future);

        // queue should now contain 1 task, but we can't grab it yet
        $this->assertSame(1, $queue->getTaskCount());
        $this->assertFalse($queue->grabTask());

        // wait for the future!
        sleep(2);

        $this->assertSame(1, $queue->getTaskCount());
        $this->assertSame(
            array(
                'file' => $config['path'] . '/' . $future . '.0000.0',
                'time' => $future,
                'type' => 'test',
                'id'   => '123',
                'data' => array()
            ),
            $queue->grabTask()
        );
        $this->assertSame(0, $queue->getTaskCount());
    }

    protected function addToQueue($data, $time = null)
    {
        $config = $this->getApplication()->getConfig();
        $token  = reset($this->getApplication()->getServiceManager()->get('queue')->getTokens());
        $queue  = DATA_PATH;
        $script = BASE_PATH . '/public/queue.php';
        $php    = defined('PHP_BINARY') ? PHP_BINARY : PHP_BINDIR . '/php';
        $handle = popen(
            $php . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($queue) . ' ' . escapeshellarg($token),
            'w'
        );
        fwrite($handle, $data);
        pclose($handle);
    }
}
