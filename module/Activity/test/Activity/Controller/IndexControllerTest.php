<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ActivityTest\Controller;

use Activity\Model\Activity;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Job;
use P4\Connection\Connection;
use Projects\Model\Project;
use Zend\Json\Json;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test the index action.
     */
    public function testIndexAction()
    {
        // add some events
        $job = new Job;
        $job->setDescription('test1')
            ->setUser('foo')
            ->save();
        $job = new Job;
        $job->setDescription('test2')
            ->setUser('bar')
            ->save();
        $job = new Job;
        $job->setDescription('test3')
            ->setUser('foo')
            ->save();
        $file = new File;
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('bar');
        $file->add();
        $file->submit('test');

        // process queue (should pull in existing jobs)
        $this->processQueue();

        // dispatch to activity
        $this->dispatch('/activity');

        // verify basic output
        $result = $this->getResult();
        $body   = $this->getResponse()->getBody();
        $data   = Json::decode($body);

        $this->assertRoute('activity');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $activity = $data->activity;
        $this->assertSame(4, count($activity));
        $this->assertSame(4,  $activity[0]->id);
        $this->assertSame('change', $activity[0]->type);
        $this->assertSame('admin', $activity[0]->user);
        $this->assertSame("change 1", $activity[0]->target);
        $this->assertSame("job000001", $activity[3]->target);
        $this->assertSame(1, $data->lastSeen);

        // verify max param
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('max', 2);
        $this->dispatch('/activity');
        $data     = Json::decode($this->getResponse()->getBody());
        $activity = $data->activity;
        $this->assertSame(2, count($activity));
        $this->assertSame("change 1", $activity[0]->target);
        $this->assertSame("job000003", $activity[1]->target);
        $this->assertSame(3, $data->lastSeen);

        // verify after param
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('after', 2);
        $this->dispatch('/activity');
        $data     = Json::decode($this->getResponse()->getBody());
        $activity = $data->activity;
        $this->assertSame(1, count($activity));
        $this->assertSame("job000001", $activity[0]->target);
        $this->assertSame(1, $data->lastSeen);

        // verify after mixed with max
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('after', 4)->set('max', 2);
        $this->dispatch('/activity');
        $data     = Json::decode($this->getResponse()->getBody());
        $activity = $data->activity;
        $this->assertSame(2, count($activity));
        $this->assertSame("job000003", $activity[0]->target);
        $this->assertSame("job000002", $activity[1]->target);
        $this->assertSame(2, $data->lastSeen);

        // verify change type filter
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('type', 'change');
        $this->dispatch('/activity');
        $data = Json::decode($this->getResponse()->getBody());
        $this->assertSame(1, count($data->activity));
        $this->assertSame(4, $data->lastSeen);

        // verify job type filter
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('type', 'job');
        $this->dispatch('/activity');
        $data = Json::decode($this->getResponse()->getBody());
        $this->assertSame(3, count($data->activity));
        $this->assertSame(1, $data->lastSeen);

        // verify stream filter
        $this->resetApplication();
        $this->dispatch('/activity/streams/user-foo');
        $data = Json::decode($this->getResponse()->getBody());
        $this->assertSame(2, count($data->activity));
        $this->assertSame(1, $data->lastSeen);
    }

    /**
     * Verify that activity events related to changes user cannot access will be filtered out.
     */
    public function testIndexActionWithRestrictedChanges()
    {
        // create user with limited access
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo1/...', '//depot/foo2/...'));

        // create changes for testing
        $file = new File;
        $file->setFilespec('//depot/foo')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #1');
        $id = $change->getId();

        $file = new File;
        $file->setFilespec('//depot/foo1/bar')->open()->setLocalContents('xyz');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #2');
        $id = $change->getId();

        // create activity records to test with
        $this->processQueue();

        // create one more activity with no relation to changes
        $activity = new Activity($this->p4);
        $activity->set('user', 'foo')->save();

        // there should be 3 activity records and accessible for users with non-restricted access
        $this->dispatch('/activity');

        // verify basic output
        $result = $this->getResult();
        $body   = $this->getResponse()->getBody();
        $data   = Json::decode($body);

        $this->assertRoute('activity');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $activity = $data->activity;
        $this->assertSame(3, count($activity));
        $this->assertSame(1, $data->lastSeen);

        // do it again as user 'foo', only 2 activity events should be visible
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4', $p4Foo);

        $this->dispatch('/activity');

        $result = $this->getResult();
        $body   = $this->getResponse()->getBody();
        $data   = Json::decode($body);

        $this->assertRoute('activity');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $activity = $data->activity;
        $this->assertSame(2, count($activity));
        $this->assertSame(1, $data->lastSeen);
    }

    /**
     * Verify that activity events related to private projects user cannot access will be filtered out.
     */
    public function testIndexActionWithPrivateProjects()
    {
        // create users, projects and changes for testing
        $p4Foo = $this->connectWithAccess('foo', array('//depot/...'));
        $p4Bar = $this->connectWithAccess('bar', array('//depot/...'));

        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'public',
                'name'     => 'public project',
                'members'  => array('foo'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'paths' => '//depot/pub/...'
                    )
                ),
                'private'  => false
            )
        )->save();

        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'private',
                'name'     => 'private project',
                'members'  => array('bar'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'paths' => '//depot/...'
                    )
                ),
                'private'  => true
            )
        )->save();

        $file = new File;
        $file->setFilespec('//depot/pub/test')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->addFile($file)->submit('change 1');

        $file = new File;
        $file->setFilespec('//depot/test')->open()->setLocalContents('xyz');
        $change = new Change($this->p4);
        $change->addFile($file)->submit('change 2');

        // create activity records to test with
        $this->processQueue();

        // create one more activity with no relation to projects
        $activity = new Activity($this->p4);
        $activity->set('user', 'foo')->save();

        // there should be 3 activity records with the following accessibility:
        // user foo - should see
        //            generic and
        //            for change 1 (touching public:main)
        // user bar - should see
        //            generic,
        //            for change 1 (touching public:main, private:main) and
        //            for change 2 (touching private:main)

        // try as foo
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Foo);
        $this->dispatch('/activity');

        $result = $this->getResult();
        $body   = $this->getResponse()->getBody();
        $data   = Json::decode($body, true);

        $this->assertRoute('activity');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $activity = $data['activity'];
        $this->assertSame(2, count($activity));
        $this->assertSame(1, $data['lastSeen']);
        $this->assertSame(array('public' => array('main')), $activity[1]['projects']);

        // try as bar
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Bar);
        $this->dispatch('/activity');

        $result = $this->getResult();
        $body   = $this->getResponse()->getBody();
        $data   = Json::decode($body, true);

        $this->assertRoute('activity');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $activity = $data['activity'];
        $this->assertSame(3, count($activity));
        $this->assertSame(1, $data['lastSeen']);

        $this->assertSame(array('private' => array('main')), $activity[1]['projects']);

        $projects = $activity[2]['projects'];
        ksort($projects);
        $this->assertSame(
            array(
                'private' => array('main'),
                'public'  => array('main')
            ),
            $projects
        );
    }

    /**
     * Test generating rss feed for the activity.
     */
    public function testRss()
    {
        // verify blank output
        $this->dispatch('/activity/rss');

        // verify output
        $result = $this->getResult();
        $this->assertRoute('activity-rss');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\FeedModel', $result);

        $variables = $result->getVariables();
        $this->assertSame(0, count($variables));

        // add a few jobs and verify again
        $job = new Job;
        $job->setDescription('job 1')
            ->setUser('foo')
            ->save();
        $job = new Job;
        $job->setDescription('job 2')
            ->setUser('bar')
            ->save();
        $job = new Job;
        $job->setDescription('job 3')
            ->setUser('foo')
            ->save();
        $this->resetApplication();

        // process queue (should pull in existing jobs)
        $this->processQueue();

        $this->resetApplication();
        $this->dispatch('/activity/rss');

        $result = $this->getResult();
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\FeedModel', $result);

        $feed = $result->getFeed();
        $this->assertSame(3, $feed->count());

        // ensure that stream filter works
        $this->resetApplication();
        $this->dispatch('/activity/streams/user-foo/rss');

        $result = $this->getResult();
        $this->assertRoute('activity-stream-rss');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\FeedModel', $result);

        $feed = $result->getFeed();
        $this->assertSame(2, $feed->count());
    }

    /**
     * Test bad method add action
     */
    public function testAddGet()
    {
        // switch to an admin user to get past ACL and fail on our own merritt
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        $result = $this->dispatch('/activity/add');
        $data   = json_decode($result, true);
        $this->assertFalse($data['isValid']);
        $this->assertTrue((bool) strpos($data['error'], 'HTTP POST'));
    }

    /**
     * Test bad data add action
     */
    public function testAddInvalidData()
    {
        // switch to an admin user to get past ACL and fail on our own merritt
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        $this->getRequest()->setMethod('post');
        $result = $this->dispatch('/activity/add');
        $data   = json_decode($result, true);

        $this->assertFalse($data['isValid']);
        $this->assertSame(
            4,
            count($data['messages'])
        );

        $this->resetApplication();

        // switch to an admin user to get past ACL and fail on our own merritt
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        $post = new Parameters(
            array(
                'time'      => 'not a valid time',
                'streams'   => array('user-joe', 12345)
            )
        );
        $this->getRequest()->setMethod('post')->setPost($post);

        $result = $this->dispatch('/activity/add');
        $data   = json_decode($result, true);
        $this->assertSame(
            'The input must contain only digits',
            $data['messages']['time']['notDigits']
        );
        $this->assertSame(
            'Only string values are permitted in the streams array.',
            $data['messages']['streams']['callbackValue']
        );
    }

    /**
     * Test good add action
     */
    public function testAddSuccess()
    {
        $time = time();
        $data = array(
            'type'        => 'test',
            'link'        => 'http://domain.com/path',
            'user'        => 'some-user',
            'action'      => 'tested',
            'target'      => 'the activity add action',
            'preposition' => 'for',
            'description' => 'testing to see if add activity works',
            'details'     => array(),
            'topic'       => 'a/b/c',
            'depotFile'   => null,
            'time'        => $time,
            'behalfOf'    => null,
            'projects'    => array(),
            'followers'   => array(),
            'streams'     => array('user-some-user'),
            'change'      => '123'
        );
        $post = new Parameters($data);

        // switch to an admin user to get past ACL
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));

        $this->getRequest()->setMethod('post')->setPost($post);
        $result = $this->dispatch('/activity/add');
        $result = json_decode($result, true);

        $this->assertTrue($result['isValid']);

        $activity = Activity::fetch(1, $this->p4);
        $this->assertSame(
            array('id' => 1) + $data,
            $activity->get()
        );
    }

    protected function processQueue()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
    }
}
