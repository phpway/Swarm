<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace JobsTest\Controller;

use ModuleTest\TestControllerCase;
use P4\Spec\Job;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test the job action.
     */
    public function testJobAction()
    {
        // add a new job
        $job = new Job;
        $job->setDescription('job xyz')
            ->setUser('foo')
            ->save();

        // dispatch to import to add jobs to the activity list
        $this->dispatch('/activity/import');

        // dispatch to view the job
        $this->resetApplication();
        $this->dispatch('/jobs/' . $job->getId());

        $result = $this->getResult();
        $this->assertRoute('jobs');
        $this->assertRouteMatch('jobs', 'jobs\controller\indexcontroller', 'job');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Job', $result->getVariable('job'));

        $this->assertQueryContentContains('.job-info .job-status.status-open',  'Open');
        $this->assertQueryContentContains('.job-details dt.field-status',       'Status');
        $this->assertQueryContentContains('.job-details dd.field-status',       'Open');
        $this->assertQueryContentContains('.job-details dt.field-user',         'User');
        $this->assertQueryContentContains('.job-details dd.field-user',         'foo');
        $this->assertQueryContentContains('.job-details dt.field-date',         'Date');
        $this->assertNotQuery('.job-details dt.field-description');
    }

    public function testJobEdit()
    {
        $job = new Job;
        $job->setDescription('a')
            ->setUser('foo')
            ->save();

        $request = $this->getRequest();
        $request->setMethod(\Zend\Http\Request::METHOD_POST)
                ->getPost()
                ->set('format', 'json')
                ->set('Description', 'b');

        $this->dispatch('/jobs/' . $job->getId());

        $this->assertResponseStatusCode(200);
        $job = Job::fetch($job->getId());
        $this->assertSame($job->getDescription(), "b\n");
        $result = $this->getResult();
        $this->assertTrue(isset($result->isValid));
        $this->assertTrue(isset($result->job));
        $this->assertTrue(is_array($result->job));
        $this->assertSame('job000001', $result->job['__raw-Job']);
    }

    public function testJobEditBadId()
    {
        $request = $this->getRequest();
        $request->setMethod(\Zend\Http\Request::METHOD_POST)
                ->getPost()
                ->set('format', 'json')
                ->set('Description', 'a');

        $this->dispatch('/jobs/job000001');
        $this->assertResponseStatusCode(404);
    }

    public function testJobEditBadData()
    {
        $job = new Job;
        $job->setDescription('a')
            ->setUser('foo')
            ->save();

        $request = $this->getRequest();
        $request->setMethod(\Zend\Http\Request::METHOD_POST)
                ->getPost()
                ->set('format', 'json')
                ->set('Description', '');

        $this->dispatch('/jobs/job000001');
        $result = $this->getResult();
        $this->assertFalse($result->isValid);
        $this->assertTrue(isset($result->messages));
        $this->assertTrue(is_array($result->messages));
        $this->assertTrue(isset($result->messages['Description']));
    }
}
