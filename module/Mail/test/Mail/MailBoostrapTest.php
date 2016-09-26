<?php
/**
 * Tests for the Mail module's bootstrap
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace MailTest;

use Activity\Model\Activity;
use Application\Log\Writer\Mock as MockLog;
use ModuleTest\TestControllerCase;
use P4\Log\Logger;
use Zend\EventManager\Event;
use Zend\Log\Logger as ZendLogger;
use Zend\Stdlib\SplPriorityQueue;

class MailBoostrapTest extends TestControllerCase
{
    public function setUp()
    {
        parent::setUp();

        // tweak subject prefix (because we can)
        $services                         = $this->getApplication()->getServiceManager();
        $config                           = $services->get('config');
        $config['mail']['subject_prefix'] = '[TEST]';
        $services->setService('config', $config);
    }

    public function testMailerConfig()
    {
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');
        $config   = $config['mail']['transport'];
        $mailer   = $services->get('mailer');

        $this->assertInstanceOf('Zend\Mail\Transport\File', $mailer);
        $this->assertEquals($config['path'], DATA_PATH . '/mail');
    }

    public function testActivityMailTrigger()
    {
        $mailer   = $this->getApplication()->getServiceManager()->get('mailer');
        $lastFile = $mailer->getLastFile();
        $activity = new Activity;
        $activity->set('description', 'testActivityMailTrigger');
        $this->sendMail(
            'activitymailtrigger',
            $activity,
            array(
                'to'            => 'swarmactivity@example.com',
                'subject'       => 'Example Activity',
                'fromAddress'   => 'swarmactivity@example.com',
                'fromName'      => 'Activity',
                'textTemplate'  => __DIR__ . '/../assets/textTemplate.phtml',
                'htmlTemplate'  => __DIR__ . '/../assets/htmlTemplate.phtml',
            )
        );

        $emailFile = $mailer->getLastFile();
        $this->assertNotNull($emailFile);

        if ($lastFile) {
            $this->assertFileNotEquals($lastFile, $emailFile, 'Email File was not created');
        }

        $this->assertTrue(is_readable($emailFile));

        $contents = file_get_contents($emailFile);
        $this->assertContains('To: swarmactivity@example.com', $contents);
        $this->assertContains('Subject: =?UTF-8?Q?[TEST]=20Example=20Activity?=', $contents);
        $this->assertContains('Reply-To: =?UTF-8?Q?Activity?= <swarmactivity@example.com>', $contents);
        $this->assertContains('Content-Type: multipart/alternative;', $contents);
        $this->assertContains('Content-Type: text/plain', $contents);
        $this->assertContains('Content-Type: text/html', $contents);
        $this->assertContains('testActivityMailTrigger', $contents);
    }

    public function testPlainMail()
    {
        $mailer   = $this->getApplication()->getServiceManager()->get('mailer');
        $activity = new Activity;
        $activity->set('description', 'testPlainMail');
        $this->sendMail(
            'plainmail',
            $activity,
            array(
                'textTemplate'  => __DIR__ . '/../assets/textTemplate.phtml',
            )
        );

        $emailFile = $mailer->getLastFile();
        $contents  = file_get_contents($emailFile);
        $this->assertContains('Content-Type: text/plain', $contents);
        $this->assertNotContains('Content-Type: multipart/alternative;', $contents);
        $this->assertNotContains('Content-Type: text/html', $contents);
        $this->assertContains('testPlainMail', $contents);
    }

    public function testHtmlMail()
    {
        $mailer   = $this->getApplication()->getServiceManager()->get('mailer');
        $activity = new Activity;
        $activity->set('description', 'testHtmlMail');
        $this->sendMail(
            'htmlmail',
            $activity,
            array(
                'htmlTemplate'  => __DIR__ . '/../assets/htmlTemplate.phtml',
            )
        );

        $emailFile = $mailer->getLastFile();
        $contents  = file_get_contents($emailFile);
        $this->assertContains('Content-Type: text/html', $contents);
        $this->assertNotContains('Content-Type: multipart/alternative;', $contents);
        $this->assertNotContains('Content-Type: text/plain', $contents);
        $this->assertContains('testHtmlMail', $contents);
    }

    public function testMailDebug()
    {
        $logger     = $this->getApplication()->getServiceManager()->get('logger');
        $writers    = $logger->getWriters();
        $mockWriter = new SplPriorityQueue();
        $mock       = new MockLog;
        $mockWriter->insert($mock, 1);
        $logger->setWriters($mockWriter);

        $mailer   = $this->getApplication()->getServiceManager()->get('mailer');
        $activity = new Activity;
        $activity->set('description', 'testPlainMail');
        $this->sendMail(
            'plainmail',
            $activity,
            array(
                'textTemplate'  => __DIR__ . '/../assets/textTemplate.phtml',
            )
        );

        $emailFile = $mailer->getLastFile();
        $contents  = file_get_contents($emailFile);
        $this->assertContains('testPlainMail', $contents);

        $actual   = $mock->events[0];
        $expected = array(
            'priority'     => 7,
            'priorityName' => 'DEBUG',
            'message'      => 'Email sent. Subject: [TEST] Example',
            'extra'        => array('recipients' => array('swarm@example.com')),
        );

        unset($actual['timestamp']);
        $this->assertSame($expected, $actual);

        // restore original log writers
        $logger->setWriters($writers);
    }

    protected function sendMail($id, $activity, $options = array())
    {
        $options  = $options + array(
            'to'            => 'swarm@example.com',
            'subject'       => 'Example',
            'fromAddress'   => 'swarm@example.com',
            'fromName'      => 'Swarm',
        );
        $services = $this->getApplication()->getServiceManager();
        $mailer   = $services->get('mailer');
        $events   = $services->get('queue')->getEventManager();
        $event    = new Event;
        $event->setName('task.' . 'mailtest')
              ->setParam('id',    $id)
              ->setParam('time',  0)
              ->setParam('activity', $activity)
              ->setParam('mail', $options)
              ->setTarget($this);
        $events->trigger($event);
    }
}
