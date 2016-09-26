<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ChangesTest\Controller;

use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Job;
use P4\Spec\Change;
use P4\Spec\Label;
use Reviews\Model\Review;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test the change action.
     */
    public function testChangeAction()
    {
        // add a change with one file for testing to the depot
        $file = new File;
        $file->setFilespec('//depot/testfile')
             ->open()
             ->setLocalContents('xyz123')
             ->submit('change test');

        // dispatch and verify output
        $this->dispatch('/changes/1');

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Change', $result->getVariable('change'));

        $this->assertQueryContentContains('h1', 'Change 1');
        $this->assertQueryContentContains('ul.nav-tabs .file-count', '1');
        $this->assertQueryContentContains('.diff-header .filename', 'testfile');
        $this->assertQueryContentContains('div.change-description', 'change test');

        $this->assertQuery('a[href="/files/depot/testfile?v=1"]');
    }

    /**
     * Test changes action andensure that its not accessible by the user who has not access to the
     * associated change.
     */
    public function testChangesActionWithRestrictedAccess()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create a change
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #1');
        $id = $change->getId();

        // ensure the page is accessible by default
        $this->dispatch('/changes/' . $id);

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Change', $result->getVariable('change'));

        // test with acting as user 'foo'
        $this->resetApplication();

        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4', $p4Foo);

        $this->dispatch('/changes/' . $id);

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(403);
    }

    /**
     * Test changes action and ensure it properly shows shelved changes when requested
     */
    public function testChangesActionWithShelvedChanges()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create a change
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->setDescription('If there is no struggle, there is no progress.')
            ->addFile($file)
            ->save('shelved #1');
        $id = $change->getId();
        $this->p4->run('shelve', array('-c', $id, '//...'));

        // ensure the page is accessible by default
        $this->dispatch('/changes?status=shelved&format=partial');

        $result = $this->getResult();
        $this->assertRoute('changes');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'changes');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $changes = $result->getVariable('changes');
        $this->assertInstanceOf('P4\Model\Fielded\Iterator', $changes);
        $this->assertSame(1, $changes->count(), 'Expected one shelved change');
        $actual   = $changes->first()->getRawValues();
        $expected = array(
            'Change'      => 1,
            'Client'      => 'test-client',
            'User'        => 'admin',
            'Status'      => 'pending',
            'Type'        => 'public',
            'Description' => "If there is no struggle, there is no progress.\n",
            'JobStatus'   => null,
            'Jobs'        => null,
            'Files'       => null,
        );
        unset($actual['Date'], $actual['ImportedBy'], $actual['Identity']);
        $this->assertSame($expected, $actual);

        // test the actual HTML output just to be sure
        $this->assertQueryContentContains('tr > td.description', 'If there is no struggle, there is no progress.');
    }

    /**
     * Test changes action and ensure that it returns associated reviews when requested
     */
    public function testChangesActionWithReviews()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create a change
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->addFile($file)->submit('ready for #review');
        $id = $change->getId();

        // create a review
        $review = Review::createFromChange($id)->save();

        // ensure the page is accessible by default
        $this->dispatch('/changes?includeReviews=true&format=partial');

        $result = $this->getResult();
        $this->assertRoute('changes');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'changes');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $reviews = $result->getVariable('reviews');
        $this->assertSame(1, count($reviews), 'Expected one review');
        $actual   = $reviews[1]->getRawValues();
        $expected = array(
            'id'               => null,
            'type'             => null,
            'changes'          => array(0 => 1),
            'commits'          => array(0 => 1),
            'versions'         => null,
            'author'           => 'admin',
            'participants'     => array('admin' => array()),
            'participantsData' => null,
            'hasReviewer'      => 0,
            'description'      => "ready for #review\n",
            'projects'         => null,
            'state'            => 'needsReview',
            'stateLabel'       => null,
            'testStatus'       => null,
            'testDetails'      => null,
            'deployStatus'     => null,
            'deployDetails'    => null,
            'pending'          => 0,
            'commitStatus'     => null,
            'upgrade'          => 4,
            'groups'           => null,
        );
        unset($actual['created']);
        unset($actual['updated']);
        unset($actual['token']);
        $this->assertSame($expected, $actual);
        $this->assertSame(2, $reviews[1]->getId());

        // test the actual HTML output just to be sure
        $this->assertQueryContentContains('tr > td.review-link > a > span', 'View Review');
        $this->assertQueryCount('tr > td.review-status > i.icon-review-needsReview', 1);
    }

    /**
     * Test change action with a chage fixing a job.
     */
    public function testChangeWithJob()
    {
        // add a new job
        $job = new Job;
        $job->setDescription('job xyz')
            ->save();

        // create a file
        $file = new File;
        $file->setFilespec('//depot/testfile')
             ->open()
             ->setLocalContents('xyz123');

        // create a change
        $change = new Change;
        $jobId  = $job->getId();
        $change->addFile($file)
               ->addJob($jobId)
               ->submit('change with a job');

        // dispatch and verify output
        $this->dispatch('/changes/' . $change->getId());

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Change', $result->getVariable('change'));

        $this->assertQueryContentContains('h1', 'Change 1');
        $this->assertQueryContentContains('ul.nav-tabs .file-count', '1');
        $this->assertQueryContentContains('.diff-header .filename',  'testfile');
        $this->assertQueryContentContains('div.change-description',  'change with a job');

        $this->assertQuery('a[href="/files/depot/testfile?v=1"]');
    }

    /**
     * Test fixesAction by adding a job to a change.
     */
    public function testAddJobToChange()
    {
        // add a new job
        $job = new Job;
        $job->setDescription('job xyz')
            ->save();

        // create a file
        $file = new File;
        $file->setFilespec('//depot/testfile')
            ->open()
            ->setLocalContents('xyz123');

        // create a change
        $change = new Change;
        $jobId  = $job->getId();
        $change->addFile($file)
            ->submit('change without a job');

        // dispatch and verify output
        $this->dispatch('/changes/' . $change->getId());

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Change', $result->getVariable('change'));

        $this->assertQueryContentContains('h1', 'Change 1');
        $this->assertQueryContentContains('ul.nav-tabs .file-count', '1');
        $this->assertQueryContentContains('.diff-header .filename',  'testfile');
        $this->assertQueryContentContains('div.change-description',  'change without a job');

        $this->assertQuery('a[href="/files/depot/testfile?v=1"]');

        $postData = new Parameters(array('jobs' => $job->getId()));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/changes/' . $change->getId() . '/fixes/add');
        $result = $this->getResult();
        $this->assertSame(
            array(
                'jobs' => array(
                    array(
                        'job'         => $jobId,
                        'link'        => '/jobs/' . $jobId,
                        'status'      => 'closed',
                        'description' => '<span class="first-line">' . trim($job->getDescription()) . '</span>'
                    )
                )
            ),
            $result->getVariables()
        );
    }

    public function testAddJobToNonexistentChange()
    {
        // add a new job
        $job = new Job;
        $job->setDescription('job xyz')
                ->save();

        // create a file
        $file = new File;
        $file->setFilespec('//depot/testfile')
                ->open()
                ->setLocalContents('xyz123');

        // create a change
        $change = new Change;
        $jobId  = $job->getId();
        $change->addFile($file)
                ->submit('change without a job');

        // dispatch and verify output
        $this->dispatch('/changes/' . $change->getId());

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Change', $result->getVariable('change'));

        $this->assertQueryContentContains('h1', 'Change 1');
        $this->assertQueryContentContains('ul.nav-tabs .file-count', '1');
        $this->assertQueryContentContains('.diff-header .filename',  'testfile');
        $this->assertQueryContentContains('div.change-description',  'change without a job');

        $this->assertQuery('a[href="/files/depot/testfile?v=1"]');

        $postData = new Parameters(array('jobs' => $job->getId()));
        $this->getRequest()
                ->setMethod(\Zend\Http\Request::METHOD_POST)
                ->setPost($postData);

        // dispatch (to the incorrect change) and check output
        $this->dispatch('/changes/' . ($change->getId() + 1) . '/fixes/add');
        $result = $this->getResult();
        $this->assertResponseStatusCode(404);
        $this->assertSame(
            array('message' => 'Page not found.'),
            $result->getVariables()->getArrayCopy()
        );
    }

    public function testAddJobToRestrictedChange()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo1/...', '//depot/foo2/...'));

        // add a new job
        $job = new Job;
        $job->setDescription('job xyz')
            ->save();

        // create a file
        $file = new File;
        $file->setFilespec('//depot/testfile')
            ->open()
            ->setLocalContents('xyz123');

        // create a change
        $change = new Change;
        $jobId  = $job->getId();
        $change->addFile($file)
            ->setType(Change::RESTRICTED_CHANGE)
            ->submit('restricted change without a job');

        // dispatch and verify output
        $this->dispatch('/changes/' . $change->getId());

        $result = $this->getResult();
        $this->assertRoute('change');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'change');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertInstanceOf('P4\Spec\Change', $result->getVariable('change'));

        $this->assertQueryContentContains('h1', 'Change 1');
        $this->assertQueryContentContains('ul.nav-tabs .file-count', '1');
        $this->assertQueryContentContains('.diff-header .filename',  'testfile');
        $this->assertQueryContentContains('div.change-description',  'restricted change without a job');

        $this->assertQuery('a[href="/files/depot/testfile?v=1"]');

        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4', $p4Foo);

        $postData = new Parameters(array('jobs' => $job->getId()));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch (to the incorrect change) and check output
        $this->dispatch('/changes/' . $change->getId() . '/fixes/add');
        $result = $this->getResult();
        $this->assertResponseStatusCode(200);
        $this->assertSame(
            array(
                'jobs' => array(
                    array(
                        'job'         => 'job000001',
                        'link'        => '/jobs/job000001',
                        'status'      => 'closed',
                        'description' => '<span class="first-line">job xyz</span>',
                    )
                )
            ),
            $result->getVariables()
        );
    }

    /**
     * Test the filespec range search parameter.
     *
     * @dataProvider filespecRangeProvider
     */
    public function testFindFilespecRange($filespec, $range, $response, $description, $matched)
    {
        // set up preconditions for each test iteration
        $emptyLabel = new Label;
        $emptyLabel->setId('pristine')
            ->setView(array('//depot/...'))
            ->save();

        $file = new File;
        $file->setFilespec('//depot/main/testfile')
            ->open()
            ->setLocalContents('xyz123');

        $change = new Change;
        $change->addFile($file)
            ->submit($description);

        $change = new Change;
        $change->setDescription('this is a pending changelist')->save();

        $testLabel = new Label;
        $testLabel->setId('testlabel')
            ->setView(array('//depot/...'))
            ->save();

        $testLabel->tag(array('//depot/main/testfile'));

        // get the application ready to run the actual test
        $this->resetApplication();

        $this->dispatch('/changes/' . $filespec . '?range=' . urlencode($range) . '&format=partial');

        $result = $this->getResult();
        $this->assertRoute('changes');
        $this->assertRouteMatch('changes', 'changes\controller\indexcontroller', 'changes');
        $this->assertResponseStatusCode($response);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $body       = $this->getResponse()->getBody();
        $foundMatch = strstr($body, $description);
        $this->assertSame($matched, $foundMatch === false ? false : true, 'Failed to confirm expected match status');
    }

    public function filespecRangeProvider()
    {
        return array(
            array(
                'filespec'    => '',
                'range'       => '0,#head',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 0
            array(
                'filespec'    => '',
                'range'       => '0,1',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 1
            array(
                'filespec'    => '',
                'range'       => '@0,1',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 2
            array(
                'filespec'    => '',
                'range'       => '2,#head',
                'response'    => 200,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 3
            array(
                'filespec'    => '//depot/main/',
                'range'       => '0,1',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 4
            array(
                'filespec'    => '//depot/main/',
                'range'       => '2,#head',
                'response'    => 200,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 5
            array(
                'filespec'    => '',
                'range'       => '800,',
                'response'    => 400,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 6
            array(
                'filespec'    => '',
                'range'       => date('Y/m/d H:i:s', time() + 3600),
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 7
            array(
                'filespec'    => '',
                'range'       => date('Y/m/d H:i:s', time() - 3600) . ',' . date('Y/m/d H:i:s', time() - 1800),
                'response'    => 200,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 8
            array(
                'filespec'    => '',
                'range'       => 'testlabel',
                'response'    => 200,
                'description' => 'label for filespec testing',
                'matched'     => true
            ), // end dataset 9
            array(
                'filespec'    => '',
                'range'       => 'pristine',
                'response'    => 200,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 10
            array(
                'filespec'    => '',
                'range'       => 'pris+tine',
                'response'    => 400,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 11
            array(
                'filespec'    => '',
                'range'       => '@80,#now',
                'response'    => 400,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 12
            array(
                'filespec'    => '',
                'range'       => 'head',
                'response'    => 400,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 13
            array(
                'filespec'    => '',
                'range'       => '@0, 1',
                'response'    => 200,
                'description' => 'change for filespec whitespace testing',
                'matched'     => true
            ), // end dataset 14
            array(
                'filespec'    => '',
                'range'       => '#head',
                'response'    => 200,
                'description' => 'change for headrev testing',
                'matched'     => true
            ), // end dataset 15
            array(
                'filespec'    => '',
                'range'       => 'now',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 16
            array(
                'filespec'    => '',
                'range'       => '@now',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 17
            array(
                'filespec'    => '',
                'range'       => '#now',
                'response'    => 400,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 18
            array(
                'filespec'    => '',
                'range'       => '@#head',
                'response'    => 400,
                'description' => 'NO MATCH',
                'matched'     => false
            ), // end dataset 19
            array(
                'filespec'    => '',
                'range'       => '@=1',
                'response'    => 200,
                'description' => 'change for filespec testing',
                'matched'     => true
            ), // end dataset 20
            array(
                'filespec'    => '',
                'range'       => '@=1 ',
                'response'    => 200,
                'description' => 'change for trim testing',
                'matched'     => true
            ), // end dataset 21
            array(
                'filespec'    => '',
                'range'       => ' @=1',
                'response'    => 200,
                'description' => 'change for trim testing',
                'matched'     => true
            ), // end dataset 22
            array(
                'filespec'    => '',
                'range'       => '',
                'response'    => 200,
                'description' => 'change for trim testing',
                'matched'     => true
            ), // end dataset 23
            array(
                'filespec'    => '',
                'range'       => ' ',
                'response'    => 200,
                'description' => 'change for trim testing',
                'matched'     => true
            ), // end dataset 24
            array(
                'filespec'    => '',
                'range'       => '@=2',
                'response'    => 200,
                'description' => 'pending changelist',
                'matched'     => false
            ), // end dataset 25
        );
    }
}
