<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace JiraTest\Controller;

use Application\Log\Writer\Mock as MockLog;
use Jira\Model\Linkage;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Definition;
use P4\Spec\Job;
use Reviews\Model\Review;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\Parameters;

class ModuleTest extends TestControllerCase
{
    public function setUp()
    {
        parent::setUp();

        // write out a cache of recognized project ids
        mkdir(DATA_PATH . '/cache/jira/', 0777, true);
        file_put_contents(DATA_PATH . '/cache/jira/projects', json_encode(array('SW', 'PB')));

        // add a DTG_DTISSUE field to the jobspec
        $jobSpec = Definition::fetch('job');
        $fields  = $jobSpec->getFields() + array(
            'DTG_DTISSUE' => array(
                'code'          => 142,
                'dataType'      => 'word',
                'displayLength' => 32,
                'fieldType'     => 'optional'
            )
        );
        $jobSpec->setFields($fields)->save();

        // add a 'job1' job to use in tests
        $job = new Job($this->p4);
        $job->setId('job1')->setDescription('test job')->set('DTG_DTISSUE', 'SW-123')->save();

        // add a mock logger to capture goings on
        $this->mockLog = new MockLog;
        $services      = $this->getApplication()->getServiceManager();
        $services->get('logger')->addWriter($this->mockLog);
    }

    public function testLinkageModel()
    {
        $linkage = new Linkage($this->p4);
        $linkage->setId('1')->setJobs('JOB-1')->setIssues('SW-1')->setTitle('title!')->setSummary('a summary')->save();

        $this->assertSame(
            array(
                'id'        => '1',
                'jobs'      => array('JOB-1'),
                'issues'    => array('SW-1'),
                'title'     => 'title!',
                'summary'   => 'a summary',
            ),
            Linkage::fetch('1', $this->p4)->get()
        );
    }

    /**
     * Do a commit, then unfix the job and verify updating the change removes the backlink
     */
    public function testChangeSubmit()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');

        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change;
        $change->addFile($file)->addJob('job1')->submit('Description with PB-123 text');

        $queue->addTask('commit', $change->getId());
        $this->processQueue();

        $this->assertSame(
            array (
                array (
                    'url' => '/rest/api/latest/project',
                    'params' => array (),
                    'method' => 'get',
                ),
                array(
                    'url' => '/rest/api/latest/issue/PB-123/remotelink',
                    'params' => array (
                        'globalId' => 'swarm-change-7860aea0bfd23feb975e4d822dc1de26',
                        'object' => array (
                            'url' => 'http://localhost/changes/1',
                            'title' => 'Commit 1',
                            'summary' => "Description with PB-123 text\n",
                            'icon' => array (
                                'url16x16' => 'http://localhost/favicon.ico',
                                'title' => 'Swarm',
                            ),
                        ),
                    ),
                    'method' => 'post',
                ),
                array (
                    'url' => '/rest/api/latest/issue/SW-123/remotelink',
                    'params' => array (
                        'globalId' => 'swarm-change-7860aea0bfd23feb975e4d822dc1de26',
                        'object' => array (
                            'url' => 'http://localhost/changes/1',
                            'title' => 'Commit 1',
                            'summary' => "Description with PB-123 text\n",
                            'icon' => array (
                                'url16x16' => 'http://localhost/favicon.ico',
                                'title' => 'Swarm',
                            ),
                        ),
                    ),
                    'method' => 'post',
                )
            ),
            $this->getJiraRequests()
        );

        // verify our work was recorded
        $this->assertSame(
            array(
                'id'        => '1',
                'jobs'      => array('job1'),
                'issues'    => array('PB-123', 'SW-123'),
                'title'     => 'Commit 1',
                'summary'   => "Description with PB-123 text\n"
            ),
            Linkage::fetch('1', $this->p4)->get()
        );

        // remove the fix from the change and force it to be re-proccessed
        $this->p4->run('fix', array('-dc', $change->getId(), 'job1'));
        $this->assertSame(
            array(),
            Change::fetch($change->getId())->getJobs()
        );
        $this->mockLog->events = array();
        $this->assertSame(array(), $this->getJiraRequests());
        $queue->addTask('job', 'job1');
        $this->processQueue();

        // verify we try to delete the now bunk JIRA reference
        $this->assertSame(
            array (
                array(
                    'url' => '/rest/api/latest/project',
                    'params' => array(),
                    'method' => 'get',
                ),
                array(
                    'url' => '/rest/api/latest/issue/SW-123/remotelink',
                    'params' => array(
                        'globalId' => 'swarm-change-7860aea0bfd23feb975e4d822dc1de26'
                    ),
                    'method' => 'delete',
                )
            ),
            $this->getJiraRequests()
        );
    }

    public function testReviewUpgrade()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');

        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change;
        $change->addFile($file)->addJob('job1')->setDescription('Description with PB-123 text #review')->save();
        $this->p4->run('shelve', array('-c', $change->getId()));

        // create a review and populate it with an 'old-style' reference to the existing job
        $review = Review::createFromChange($change, $this->p4)->save();
        $review->set(
            'jira',
            array(
                'label'  => 'Review 2 - Needs Review, Not Committed',
                'issues' => array('PB-123', 'PB-456')
            )
        )->save();

        // if the upgrade worked, one issue should get removed and the other modified to include a summary
        $queue->addTask('review', 2);
        $this->processQueue();
        $this->assertSame(
            array(
                array(
                    'url' => '/rest/api/latest/project',
                    'params' => array(),
                    'method' => 'get',
                ),
                array(
                    'url' => '/rest/api/latest/issue/PB-456/remotelink',
                    'params' => array(
                        'globalId' => 'swarm-review-34c915ad4769dbc4dc97404de986acaf',
                    ),
                    'method' => 'delete',
                ),
                array(
                    'url' => '/rest/api/latest/issue/PB-123/remotelink',
                    'params' => array(
                        'globalId' => 'swarm-review-34c915ad4769dbc4dc97404de986acaf',
                        'object' => array(
                            'url' => 'http://localhost/reviews/2/',
                            'title' => 'Review 2 - Needs Review, Not Committed',
                            'summary' => "Description with PB-123 text #review\n",
                            'icon' => array(
                                'url16x16' => 'http://localhost/favicon.ico',
                                'title' => 'Swarm',
                            ),
                        ),
                    ),
                    'method' => 'post',
                ),
            ),
            $this->getJiraRequests()
        );

        // verify our work was recorded
        $this->assertSame(
            array(
                'id'        => '2',
                'jobs'      => array(),
                'issues'    => array('PB-123'),
                'title'     => 'Review 2 - Needs Review, Not Committed',
                'summary'   => "Description with PB-123 text #review\n"
            ),
            Linkage::fetch('2', $this->p4)->get()
        );

        // verify jira field was stripped from review
        $this->assertFalse(Review::fetch(2, $this->p4)->issetRawValue('jira'));
    }

    /**
     * Start a review; modified impacted changes and then update it
     */
    public function testReview()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');

        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change;
        $change->addFile($file)->addJob('job1')->setDescription('Description with PB-123 text #review')->save();
        $this->p4->run('shelve', array('-c', $change->getId()));

        $queue->addTask('shelve', $change->getId());
        $this->processQueue();
        $this->assertSame(
            array(
                array(
                    'url' => '/rest/api/latest/project',
                    'params' => array(),
                    'method' => 'get',
                ),
                array(
                    'url' => '/rest/api/latest/issue/PB-123/remotelink',
                    'params' => array(
                        'globalId' => 'swarm-review-34c915ad4769dbc4dc97404de986acaf',
                        'object' => array(
                            'url' => 'http://localhost/reviews/2/',
                            'title' => 'Review 2 - Needs Review, Not Committed',
                            'summary' => 'Description with PB-123 text',
                            'icon' => array(
                                'url16x16' => 'http://localhost/favicon.ico',
                                'title' => 'Swarm',
                            ),
                        ),
                    ),
                    'method' => 'post',
                ),
                array(
                    'url' => '/rest/api/latest/issue/SW-123/remotelink',
                    'params' => array(
                        'globalId' => 'swarm-review-34c915ad4769dbc4dc97404de986acaf',
                        'object' => array(
                            'url' => 'http://localhost/reviews/2/',
                            'title' => 'Review 2 - Needs Review, Not Committed',
                            'summary' => 'Description with PB-123 text',
                            'icon' => array(
                                'url16x16' => 'http://localhost/favicon.ico',
                                'title' => 'Swarm',
                            ),
                        ),
                    ),
                    'method' => 'post',
                )
            ),
            $this->getJiraRequests()
        );

        // verify our work was recorded
        $this->assertSame(
            array(
                'id'        => '2',
                'jobs'      => array('job1'),
                'issues'    => array('PB-123', 'SW-123'),
                'title'     => 'Review 2 - Needs Review, Not Committed',
                'summary'   => 'Description with PB-123 text'
            ),
            Linkage::fetch('2', $this->p4)->get()
        );

        // remove the fix from the change and fake out the expected job trigger event
        $this->getRequest()
             ->setPost(new Parameters(array('jobs' => 'job1')))
             ->setMethod(\Zend\Http\Request::METHOD_POST);
        $this->dispatch('/changes/2/fixes/delete');
        $this->assertSame(
            array(),
            Change::fetch(2)->getJobs()
        );
        $this->mockLog->events = array();
        $this->assertSame(array(), $this->getJiraRequests());
        $queue->addTask('job', 'job1');
        $this->processQueue();

        // verify we try to delete the now bunk JIRA reference
        $this->assertSame(
            array (
                array(
                    'url' => '/rest/api/latest/project',
                    'params' => array(),
                    'method' => 'get',
                ),
                array(
                    'url' => '/rest/api/latest/issue/SW-123/remotelink',
                    'params' => array(
                        'globalId' => 'swarm-review-34c915ad4769dbc4dc97404de986acaf'
                    ),
                    'method' => 'delete',
                )
            ),
            $this->getJiraRequests()
        );
    }

    /**
     * @dataProvider calloutsProvider
     */
    public function testCallouts($value, $callouts)
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');

        // shelve a change with $value in description
        $file = new File;
        $file->setFilespec('//depot/test')->open()->setLocalContents('abc');
        $change = new Change;
        $change->addFile($file)->setDescription($value . ' #review')->save();
        $this->p4->run('shelve', array('-c', $change->getId()));

        $queue->addTask('shelve', $change->getId());
        $this->processQueue();

        // get the linkage (there should be only one) and verify the issues
        $linkage = Linkage::fetchAll(array(), $this->p4)->first();
        $issues  = $linkage->getIssues();

        $callouts = (array) $callouts;
        sort($callouts);
        sort($issues);

        $this->assertSame($callouts, $issues);
    }

    public function calloutsProvider()
    {
        return array(
            'single' => array(
                'simple single SW-1 callout',
                'SW-1'
            ),
            'multi' => array(
                'SW-1 SW 1, SW-2 foo SW-3',
                array('SW-1', 'SW-2', 'SW-3')
            ),
            '@' => array(
                'foo @SW-1 bar SW-2',
                array('SW-1', 'SW-2')
            ),
            'chevrons' => array(
                'foo <SW-1> bar SW-2',
                array('SW-1', 'SW-2')
            ),
            'round' => array(
                'foo (SW-1) bar SW-2',
                array('SW-1', 'SW-2')
            ),
            'square' => array(
                'foo [SW-1] bar SW-2',
                array('SW-1', 'SW-2')
            ),
            'curly' => array(
                'foo {SW-1} bar SW-2',
                array('SW-1', 'SW-2')
            ),
            'multi-brackets' => array(
                'SW-1 foo {SW-2}, [SW-3] and <SW-4> [{SW-5}]',
                array('SW-1', 'SW-2', 'SW-3', 'SW-4', 'SW-5')
            )
        );
    }

    /**
     * Ensure the jira module is configured
     *
     * @param   ServiceManager  $serviceManager     service manager instance
     */
    protected function configureServiceManager(ServiceManager $serviceManager)
    {
        parent::configureServiceManager($serviceManager);
        $serviceManager->setService(
            'config',
            array(
                'jira' => array(
                    'host'      => 'bunk!',
                    'user'      => 'bob',
                    'password'  => 'password',
                    'job_field' => 'DTG_DTISSUE'
                )
            ) + $serviceManager->get('config')
        );
    }

    protected function getJiraRequests()
    {
        $regex  = '#^JIRA making (?P<method>[^ ]+) request to resource: http://[^/]+(?P<url>.*)#';
        $events = array();
        foreach ($this->mockLog->events as $event) {
            if (preg_match($regex, $event['message'], $matches)) {
                $events[] = array(
                    'url'    => $matches['url'],
                    'params' => $event['extra'],
                    'method' => $matches['method']
                );
            }
        }

        return $events;
    }

    protected function processQueue()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
    }
}
