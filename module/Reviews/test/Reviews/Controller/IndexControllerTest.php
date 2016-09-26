<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ReviewsTest\Controller;

use Activity\Model\Activity;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;
use Projects\Model\Project;
use Reviews\Model\GitReview;
use Reviews\Model\FileInfo;
use Reviews\Model\Review;
use Users\Model\User;
use Zend\Http\Client\Adapter\Test as HttpTestAdapter;
use Zend\Http\Response as HttpResponse;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test index action with no context.
     */
    public function testIndexActionNoContext()
    {
        $this->dispatch('/reviews');

        $result = $this->getResult();
        $this->assertRoute('reviews');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $this->assertQuery('.reviews .nav-tabs .opened-counter');
        $this->assertQuery('.reviews .nav-tabs .closed-counter');
        $this->assertQuery('.reviews .tab-pane .toolbar');
        $this->assertQuery('.reviews .tab-pane .reviews-table');
    }

    /**
     * Test index action in json context.
     */
    public function testIndexActionJson()
    {
        // verify empty result if there are no records
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $result = $this->dispatch('/reviews');

        $result = $this->getResult();
        $this->assertRoute('reviews');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertSame(array(), $data['reviews']);

        $createdTimestampOne = 1359936000;
        $createdTimestampTwo = 1359950400;

        // insert several review records and try again
        $model = new Review($this->p4);
        $model->set(
            array(
                'author'        => 'super',
                'description'   => 'desc',
                'created'       => $createdTimestampOne,
                'projects'      => array(
                    'p1'    => 'rav',
                    'p2'    => '49e'
                ),
                'state'         => 'needsReview',
                'testStatus'    => 'pass'
            )
        )->save();
        $model = new Review($this->p4);
        $model->set(
            array(
                'author'           => 'foo',
                'participantsData' => array('bar' => array('vote' => array('value' => 1, 'version' => 1))),
                'description'      => 'test',
                'created'          => $createdTimestampTwo,
                'projects'         => array(
                    'p1'    => '34',
                    'p2'    => '31'
                ),
                'state'            => 'approved',
                'pending'          => 0,
                'testStatus'       => 'pass',
                'versions'         => array(
                    array(
                        'change'     => 7,
                        'user'       => 'foo',
                        'time'       => 123,
                        'pending'    => true,
                        'difference' => 1
                    )
                )
            )
        )->save();

        $this->resetApplication();
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $result = $this->dispatch('/reviews');

        $result = $this->getResult();
        $this->assertRoute('reviews');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $this->assertSame(2, count($data['reviews']));

        // check data for the first record
        $record = $data['reviews'][0];
        $this->assertSame('foo',                                    $record['author']);
        $this->assertSame(array('bar'),                             $record['upVotes']);
        $this->assertSame(array(),                                  $record['downVotes']);
        $this->assertSame('<span class="first-line">test</span>',   $record['description']);
        $this->assertSame('approved',                               $record['state']);
        $this->assertSame('pass',                                   $record['testStatus']);
        $this->assertSame(date('c', $createdTimestampTwo),          $record['createDate']);
        $this->assertSame(array(0, 0),                              $record['comments']);
        $this->assertSame(0, strpos($record['authorAvatar'],        '<div class="avatar-wrapper'));

        // check data for the second record
        $record = $data['reviews'][1];
        $this->assertSame('super',                                  $record['author']);
        $this->assertSame(array(),                                  $record['upVotes']);
        $this->assertSame(array(),                                  $record['downVotes']);
        $this->assertSame('<span class="first-line">desc</span>',   $record['description']);
        $this->assertSame('needsReview',                            $record['state']);
        $this->assertSame('pass',                                   $record['testStatus']);
        $this->assertSame(date('c', $createdTimestampOne),          $record['createDate']);
        $this->assertSame(array(0, 0),                              $record['comments']);
        $this->assertSame(0, strpos($record['authorAvatar'],        '<div class="avatar-wrapper'));
    }

    /**
     * Test review queue filtering of restricted changes
     */
    public function testIndexWithRestrictedReviews()
    {
        // need reviews with files in different paths
        $files = array('foo', 'bar', 'baz', 'bof');
        foreach ($files as $key => $name) {
            $change = new Change($this->p4);
            $change->setDescription((string) $key)->setType('restricted')->save();
            $file = new File($this->p4);
            $file->setFilespec($this->p4->getClientRoot() . '/' . $name)->touchLocalFile()->add($change->getId());

            // shelve first 2, submit second 2
            $this->p4->run($key < 2 ? 'shelve' : 'submit', array('-c', $change->getId()));

            $model = new Review($this->p4);
            $model->set(array('changes' => array($change->getId())))->save();
        }

        // should see all as standard user
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $this->dispatch('/reviews');
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertSame(4, count($data['reviews']));

        // do it again as user 'foo' with limited access, only 2 reviews should be visible
        $this->resetApplication();
        $p4Foo    = $this->connectWithAccess('foo', array('//depot/foo', '//depot/bof'));
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4', $p4Foo);
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $this->dispatch('/reviews');
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertSame(2, count($data['reviews']));
    }

    /**
     * Test review queue filtering of restricted changes
     */
    public function testIndexWithPrivateProjects()
    {
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'       => 'public',
                'name'     => 'public project',
                'members'  => array('foo'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'paths' => '//depot/public/...'
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
                        'paths' => '//depot/private/...'
                    )
                ),
                'private'  => true
            )
        )->save();

        // create 4 reviews touching only private / only public / both / none projects
        $change = new Change($this->p4);
        $change->setDescription('public')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/public/foo')->touchLocalFile()->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));
        $model = new Review($this->p4);
        $model->setProjects(Project::getAffectedByChange($change, $this->p4))->save();

        $change = new Change($this->p4);
        $change->setDescription('private')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/private/bar')->touchLocalFile()->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));
        $model = new Review($this->p4);
        $model->setProjects(Project::getAffectedByChange($change, $this->p4))->save();

        $change = new Change($this->p4);
        $change->setDescription('mix')->save();
        $file->setFilespec('//depot/public/a')->touchLocalFile()->add($change->getId());
        $file = new File($this->p4);
        $file->setFilespec('//depot/private/a')->touchLocalFile()->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));
        $model = new Review($this->p4);
        $model->setProjects(Project::getAffectedByChange($change, $this->p4))->save();

        $change = new Change($this->p4);
        $change->setDescription('free')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/foo')->touchLocalFile()->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));
        $model = new Review($this->p4);
        $model->setProjects(Project::getAffectedByChange($change, $this->p4))->save();

        // standard user should not see private projects
        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $this->dispatch('/reviews');
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $reviews = $data['reviews'];
        $this->assertSame(3, count($reviews));
        $this->assertSame('', $reviews[0]['projects']);
        $this->assertTrue(strpos($reviews[1]['projects'], 'public:main')  !== false);
        $this->assertTrue(strpos($reviews[1]['projects'], 'private:main') === false);
        $this->assertTrue(strpos($reviews[2]['projects'], 'public:main')  !== false);
        $this->assertTrue(strpos($reviews[2]['projects'], 'private:main') === false);

        // try again as bar, should see all
        $this->resetApplication();
        $p4Bar    = $this->connectWithAccess('bar', array('//depot/...'));
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Bar);

        $this->getRequest()->setQuery(new Parameters(array('format' => 'json')));
        $this->dispatch('/reviews');
        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);

        $reviews = $data['reviews'];
        $this->assertSame(4, count($reviews));
        $this->assertSame('', $reviews[0]['projects']);
        $this->assertTrue(strpos($reviews[1]['projects'], 'public:main')  !== false);
        $this->assertTrue(strpos($reviews[1]['projects'], 'private:main') !== false);
        $this->assertTrue(strpos($reviews[2]['projects'], 'private:main') !== false);
        $this->assertTrue(strpos($reviews[3]['projects'], 'public:main')  !== false);
    }

    /**
     * Test review add action using a GET request.
     */
    public function testAddActionGetNoParams()
    {
        $this->dispatch('/reviews/add');

        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);

        $body = $this->getResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertFalse($data['isValid']);
        $this->assertSame(
            $data['error'],
            'Invalid request method. HTTP POST required.'
        );
    }

    /**
     * Ensure review action respects reviews.disable_commit config setting
     */
    public function testReviewActionDisableSwarmCommit()
    {
        $this->createChange();

        $review = Review::createFromChange('1');
        $review->setPending(true);
        $review->save();

        $this->dispatch('/reviews/2');
        $result = $this->getResult();
        $this->assertRoute('review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'review');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $transitions    = $result->getVariable('transitions');
        $transitionKeys = array_keys($transitions);

        $filteredTransitions = array_filter(
            $transitionKeys,
            function ($val) {
                return false !== strpos($val, ':commit');
            }
        );
        $filteredTransitions = array_values($filteredTransitions);

        $this->assertSame(
            array('approved:commit'),
            $filteredTransitions,
            'disable_commit=false (default) setting failed to show approved:commit transition'
        );

        $services = $this->getApplication()->getServiceManager();

        // Now override the service container to disable commits, so we can confirm the expected behaviour.
        $config                              = $services->get('config');
        $config['reviews']['disable_commit'] = true;
        $services->setService('config', $config);

        $this->dispatch('/reviews/2');
        $result = $this->getResult();
        $this->assertRoute('review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'review');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        $transitions    = $result->getVariable('transitions');
        $transitionKeys = array_keys($transitions);

        $filteredTransitions = array_filter(
            $transitionKeys,
            function ($val) {
                return false !== strpos($val, ':commit');
            }
        );

        $filteredTransitions = array_values($filteredTransitions);

        $this->assertSame(
            array(),
            $filteredTransitions,
            'disable_commit=true setting failed to hide approved:commit transition'
        );
    }

    /**
     * Test review add action with minimum valid posted data.
     */
    public function testAddActionPostValidMinParams()
    {
        // create some records we need for testing
        $this->createChange();

        // test with minimum required data
        $postData = new Parameters(array('change' => 1));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/review/add');

        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame(2, $result->getVariable('id'));
        $this->assertTrue(Review::exists(2, $this->p4));
    }

    /**
     * Test review add action with valid posted review Id, and change to add.
     */
    public function testAddActionPostValidIdParam()
    {
        // create some records we need for testing
        $change1 = $this->createChange();
        $review  = Review::createFromChange($change1->getId(), $this->p4)->save();
        $change2 = $this->createChange();

        // test adding a new change
        $postData = new Parameters(array('id' => $review->getId(), 'change' => $change2->getId()));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/review/add');

        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame($review->getId(), $result->getVariable('id'));

        // process queue (so we can check activity, etc.)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $changeReview = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $change2->getId()), $this->p4);
        $this->assertSame(1, $changeReview->count());

        $changeReview = $changeReview->first();
        $this->assertSame($review->getId(), $changeReview->getId());
    }

    /**
     * Test review add action with valid change, description, and reviewers.
     */
    public function testAddActionPostDescriptionAndReviewersParams()
    {
        // create some records we need for testing
        $change = $this->createChange();
        $user   = new User;
        $user->setId('user1')->setEmail('user1@host.com')->setFullName('user1')->save();

        // test adding a new change
        $postData = new Parameters(
            array(
                'change'      => $change->getId(),
                'description' => "Give it a lick, \r\n it tastes just like raisins. ",
                'reviewers'   => array('nonadmin', 'user1'),
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/review/add');

        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));

        // process queue (so we can check activity, etc.)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $changeReview = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $change->getId()), $this->p4);
        $this->assertSame(1, $changeReview->count());

        $changeReview = $changeReview->first();
        $this->assertSame(2, $changeReview->getId());
        $this->assertSame(array('nonadmin', 'user1'), $changeReview->getReviewers());
        $this->assertSame("Give it a lick, \n it tastes just like raisins.", $changeReview->get('description'));
    }

    /**
     * Test review add action with valid change, description, and reviewers.
     */
    public function testAddActionPostReviewersParams()
    {
        // create some records we need for testing
        $change = $this->createChange();
        $user   = new User;
        $user->setId('user1')->setEmail('user1@host.com')->setFullName('user1')->save();

        // test adding a new change WITHOUT a description
        $postData = new Parameters(
            array(
                'change'      => $change->getId(),
                'reviewers'   => array('nonadmin', 'user1'),
            )
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/review/add');

        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));

        // process queue (so we can check activity, etc.)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $changeReview = Review::fetchAll(array(Review::FETCH_BY_CHANGE => $change->getId()), $this->p4);
        $this->assertSame(1, $changeReview->count());

        $changeReview = $changeReview->first();
        $this->assertSame(2, $changeReview->getId());
        $this->assertSame(array('nonadmin', 'user1'), $changeReview->getReviewers());
        $this->assertSame("change description\n", $changeReview->get('description'));
    }

    /**
     * Test review add action with valid change, description, and reviewers.
     */
    public function testAddActionPostBadReviewParam()
    {
        // create some records we need for testing
        $change = $this->createChange();

        // test adding a new change WITHOUT a description
        $postData = new Parameters(
            array(
                'change'      => $change->getId(),
                'reviewers'   => 'user1',
            )
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/review/add');

        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(false, $result->getVariable('isValid'));

        $messages = $result->getVariable('messages');
        $this->assertSame('Unknown user id(s): user1', $messages['reviewers']['unknownIds']);
    }

    /**
     * Test review add action with posting data for existing change record that already has a review.
     */
    public function testAddActionPostExistingChangeReview()
    {
        // create some records we need for testing
        $this->createChange();

        // create review record for change 1
        Review::createFromChange('1')->save();

        $postData = new Parameters(array('change' => '1'));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/review/add');
        $result = $this->getResult();
        $this->assertRoute('add-review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(false, $result->getVariable('isValid'));
        $this->assertNotEmpty($result->getVariable('error'));
    }

    /**
     * Add a review and verify task is processed correctly
     */
    public function testQueueProcessing()
    {
        // create some records we need for testing
        $this->createChange();
        $this->createProjects();

        // new review via add action
        $post = new Parameters(array('change' => 1));
        $this->getRequest()->setMethod('POST')->setPost($post);
        $this->dispatch('/review/add');

        // verify basic behavior
        $result = $this->getResult();
        $this->assertTrue(Review::exists(2, $this->p4));

        // attach a listener so we can capture mail details
        $queue  = $this->getApplication()->getServiceManager()->get('queue');
        $events = $queue->getEventManager();
        $mail   = array();
        $events->attach(
            'task.review',
            function ($event) use (&$mail) {
                $mail = $event->getParam('mail', $mail);
            },
            -199
        );

        // process queue (so we can check activity, etc.)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // ensure the expected users are notified via mail
        $this->assertSame(array('admin', 'nonadmin', 'jdoe', 'bob'), $mail['toUsers']);

        // we should have an activity record at this point.
        $activity = Activity::fetchAll(array(), $this->p4);
        $this->assertSame(2, $activity->count());

        $activity = $activity->first();
        $this->assertSame(
            array(
                'type'          => 'review',
                'link'          => array('review', array('review' => '2')),
                'action'        => 'requested',
                'target'        => 'review 2',
                'description'   => "change description\n",
                'topic'         => 'reviews/2',
                'streams'       => array(
                    'review-2',
                    'user-nonadmin',
                    'personal-nonadmin',
                    'project-project1',
                    'personal-admin',   // the change is created as this user so they show up
                    'personal-jdoe',
                    'personal-bob'
                )
            ),
            array(
                'type'          => $activity->get('type'),
                'link'          => $activity->get('link'),
                'action'        => $activity->get('action'),
                'target'        => $activity->get('target'),
                'description'   => $activity->get('description'),
                'topic'         => $activity->get('topic'),
                'streams'       => $activity->get('streams')
            )
        );

        // verify review has been updated with affected projects/branches
        $review = Review::fetch('2', $this->p4);
        $this->assertSame(
            array('project1' => array('main')),
            $review->getProjects()
        );
    }

    /**
     * Test editing a review record by posting to it.
     */
    public function testEditAction()
    {
        // create review record for change 1
        $this->createChange();
        Review::createFromChange('1')->save();

        $postData = new Parameters(array('state' => 'approved', 'testStatus' => 'pass'));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/reviews/2');
        $result = $this->getResult();
        $review = $result->getVariable('review');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame('approved', $review['state']);
        $this->assertSame('pass',     $review['testStatus']);
    }

    /**
     * Test editing a review description by posting to it.
     * We are particularly trying to verify line endings are normalized to \n
     */
    public function testEditDescription()
    {
        // create review record for change 1
        $this->createChange();
        Review::createFromChange('1')->save();

        $postData = new Parameters(
            array('description' => " I am a\r\nMultiline\nDescription\rThat's pretty cool\r\r\neh\r\n  ")
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch
        $this->dispatch('/reviews/2');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);

        // check resulting change description
        $change = Change::fetch(2, $this->p4);
        $this->assertSame(
            "I am a\nMultiline\nDescription\nThat's pretty cool\n\neh\n",
            $change->getDescription()
        );
    }

    /**
     * Test editing a review description @mention handling to verify it will
     * ignore unchanged @mentions on update.
     * This is important as we don't want to drag back in deleted reviewers.
     */
    public function testEditDescriptionAtMentionHandling()
    {
        // create review record for change 1
        $this->createChange();
        Review::createFromChange('1')->save()->updateFromChange('1')->save();

        $user = new User;
        $user->setId('user1')->setEmail('user1@host.com')->setFullName('user1')->save();
        $user->setId('user2')->setEmail('user2@host.com')->setFullName('user2')->save();
        $user->setId('required1')->setEmail('required1@host.com')->setFullName('required1')->save();

        $this->assertSame(
            array('admin'),
            Review::fetch('2', $this->p4)->getParticipants()
        );

        // drag in user 1 via @mention
        $postData = new Parameters(
            array('description' => "I want to drag in @user1 and @*required1 with an edit")
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch
        $this->dispatch('/reviews/2');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);

        // process the queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify the @mention worked
        $this->assertSame(
            array('admin', 'nonadmin', 'required1', 'user1'),
            Review::fetch('2', $this->p4)->getParticipants()
        );

        // verify the @*mention worked
        $this->assertSame(
            array('required1' => true),
            Review::fetch('2', $this->p4)->getParticipantsData('required')
        );


        // delete user 1 and verify editing in user 2 doesn't bring them back
        Review::fetch('2', $this->p4)->setParticipants(array('admin', 'required1', 'nonadmin'))->save();

        // drag in user 2 via @mention
        $postData = new Parameters(
            array('description' => "I want to drag in @user1 and @user2 with an edit")
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch
        $this->dispatch('/reviews/2');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);

        // process the queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify the @mention worked
        $this->assertSame(
            array('admin', 'nonadmin', 'required1', 'user2'),
            Review::fetch('2', $this->p4)->getParticipants()
        );


        // and now verify removing/re-adding the @mention gets it to apply again
        $postData = new Parameters(
            array('description' => "I want to drag in @user2 with an edit")
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch
        $this->dispatch('/reviews/2');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);

        // process the queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $postData = new Parameters(
            array('description' => "I want to drag in @user1 and @user2 with an edit")
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch
        $this->dispatch('/reviews/2');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);

        // process the queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify the @mention worked
        $this->assertSame(
            array('admin', 'nonadmin', 'required1', 'user1', 'user2'),
            Review::fetch('2', $this->p4)->getParticipants()
        );
    }

    /**
     * Test editing a review description by posting to it.
     * We are particularly trying to verify line endings are normalized to \n
     */
    public function testEditGitDescription()
    {
        // create fusion style change and make it a review
        $gitInfo = "\n\nImported from Git\n"
            . " Author: Bob Bobertson <bbobertson@perforce.com> 1381432565 -0700\n"
            . " Committer: Git Fusion Machinery <nobody@example.com> 1381432572 +0000\n"
            . " sha1: 6a96f259deb6d8567a4d85dce09ae2e707ca7286\n"
            . " push-state: complete\n"
            . " review-status: create\n"
            . " review-id: 1\n"
            . " review-repo: Talkhouse\n";
        $shelf   = new Change;
        $shelf->setDescription("Test git review!" . $gitInfo)->save();
        $review = GitReview::createFromChange($shelf);
        $review->save()->updateFromChange($shelf)->save();

        $postData = new Parameters(
            array('description' => " I am a\r\nMultiline\nDescription\rThat's pretty cool\r\r\neh\r\n\n\n  ")
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch
        $this->dispatch('/reviews/1');
        $this->assertRoute('review');
        $this->assertResponseStatusCode(200);

        // check resulting change description
        $change = Change::fetch(1, $this->p4);
        $this->assertSame(
            "I am a\nMultiline\nDescription\nThat's pretty cool\n\neh" . $gitInfo,
            $change->getDescription()
        );
    }

    public function testShelfAtMentionIgnoring()
    {
        $user = new User;
        $user->setId('user1')->setEmail('user1@host.com')->setFullName('user1')->save();
        $user->setId('user2')->setEmail('user2@host.com')->setFullName('user2')->save();

        $queue   = $this->getApplication()->getServiceManager()->get('queue');
        $shelfId = $this->createDiffChange(array('just a test' => 'edit'), false);
        Change::fetch($shelfId)->setDescription('now I have @user1 dragged in #review')->save();

        // pretend we shelved and proccess
        $queue->addTask('shelve', $shelfId);
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $reviewId = Review::fetchAll(array(), $this->p4)->current()->getId();
        $this->assertTrue($reviewId !== false);
        $this->assertSame(
            array('admin', 'user1'),
            Review::fetch($reviewId, $this->p4)->getParticipants()
        );


        // remove user1, mention in user2 and verify no ressurection
        Review::fetch($reviewId, $this->p4)->setParticipants('admin')->save();
        Change::fetch($shelfId)->setDescription('now I have @user1 and @user2 in #review-' . $reviewId)->save();

        // pretend we re-shelved and proccess
        $queue->addTask('shelve', $shelfId);
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $this->assertSame(
            array('admin', 'user2'),
            Review::fetch($reviewId, $this->p4)->getParticipants()
        );
    }

    /**
     * Test adding the active user as a reviewer.
     */
    public function testJoin()
    {
        // create new user for testing
        $user = new User($this->p4);
        $user->setId('jdoe')
             ->setEmail('jdoe@domain.com')
             ->setFullName('J Doe')
             ->save();

        // create review record for change 1
        $this->createChange();
        Review::createFromChange('1')->save();

        // verify no reviewers are present
        $this->assertSame(array(), Review::fetch(2, $this->p4)->getReviewers());

        // add reviewer via posting data
        $postData = new Parameters(array('join' => 'nonadmin'));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/reviews/2');
        $this->assertSame('admin', Review::fetch(2, $this->p4)->get('author'));
        $this->assertSame(array('nonadmin'), Review::fetch(2, $this->p4)->getReviewers());

        // try again with different user and verify that fails to add them
        $this->resetApplication();
        $postData = new Parameters(array('join' => 'admin'));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/reviews/2');
        $this->assertSame(array('nonadmin'), Review::fetch(2, $this->p4)->getReviewers());
    }

    /**
     * Test updating a review record's pass/fail test status.
     */
    public function testTestStatus()
    {
        // create review record for change 1
        $this->createChange();
        $review = Review::createFromChange('1')->save();

        // ensure starting test status of null.
        $this->assertSame($review->get('testStatus'), null);

        // dispatch and check output
        $this->dispatch('/reviews/2/tests/fail/' . $review->getToken());
        $result = $this->getResult();
        $review = Review::fetch(2, $this->p4);
        $this->assertRoute('review-tests');
        $this->assertResponseStatusCode(200);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame('fail', $review->get('testStatus'));
    }

    /**
     * Tests updating test details.
     */
    public function testTestDetails()
    {
        // setup a test http client adapter that simply returns 200 ok
        $adapter  = new HttpTestAdapter();
        $response = new HttpResponse();
        $response->setStatusCode(200);
        $adapter->setResponse($response);
        $services                                 = $this->getApplication()->getServiceManager();
        $config                                   = $services->get('config');
        $config['http_client_options']['adapter'] = $adapter;
        $services->setService('config', $config);

        $this->createProjects();
        $project = Project::fetch('project1', $this->p4);
        $project->set('tests', array('url' => 'http://aksldjf', 'enabled' => true))->save();

        // create review record for change 1
        $this->createChange();
        $review = Review::createFromChange('1')->save();

        // verify starting test details
        $this->assertSame(
            $review->getTestDetails(true),
            array('version' => null, 'startTimes' => array(), 'endTimes' => array(), 'averageLapse' => null)
        );

        // fake a review update event
        $queue = $this->getApplication()->getServiceManager()->get('queue');
        $queue->addTask('review', 2, array('updateFromChange' => 1));

        // process update in queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $review = Review::fetch(2, $this->p4);
        $token  = $review->getToken();

        // dispatch and check output
        $this->getRequest()->setQuery(new Parameters(array('url' => 'http://test.com')));
        $this->dispatch('/reviews/2/tests/pass/' . $token);
        $result  = $this->getResult();
        $review  = Review::fetch(2, $this->p4);
        $details = $review->get('testDetails');
        $this->assertRoute('review-tests');
        $this->assertResponseStatusCode(200);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertTrue(isset($details['endTimes']));
        $this->assertTrue(isset($details['startTimes']));
        $this->assertSame(max($details['endTimes']) - min($details['startTimes']), $details['averageLapse']);
        unset($details['endTimes'], $details['startTimes'], $details['averageLapse']);
        $this->assertSame(array('url' => 'http://test.com', 'version' => 1), $details);

        // ensure that url is cleared if test status is passed with no additional details
        $this->resetApplication();
        $this->dispatch('/reviews/2/tests/pass/' . $token);
        $result  = $this->getResult();
        $review  = Review::fetch(2, $this->p4);
        $details = $review->get('testDetails');
        unset($details['endTimes'], $details['startTimes'], $details['averageLapse']);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame(array('version' => 1), $details);

        // ensure both post and get params are captured in details
        $this->resetApplication();
        $this->getRequest()
            ->setQuery(new Parameters(array('x' => 'foo')))
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('y' => 'bar')));

        $this->dispatch('/reviews/2/tests/pass/' . $token, false);
        $result  = $this->getResult();
        $review  = Review::fetch(2, $this->p4);
        $details = $review->get('testDetails');
        unset($details['endTimes'], $details['startTimes'], $details['averageLapse']);
        ksort($details);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame(array('version' => 1, 'x' => 'foo', 'y' => 'bar'), $details);
    }

    /**
     * Test updating a review record's pass/fail test status with missing and invalid token.
     */
    public function testTestStatusMissingBadToken()
    {
        // create review record for change 1
        $this->createChange();
        $review = Review::createFromChange('1')->save();

        // ensure starting test status of null.
        $this->assertSame($review->get('testStatus'), null);

        // dispatch and check output with no token
        $this->dispatch('/reviews/2/tests/fail');
        $result = $this->getResult();
        $this->assertRoute('review-tests');
        $this->assertResponseStatusCode(403);

        // dispatch and check output with bad token
        $this->dispatch('/reviews/2/tests/fail/FFFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF');
        $result = $this->getResult();
        $this->assertRoute('review-tests');
        $this->assertResponseStatusCode(403);
    }

    /**
     * Tests updating deploy details.
     */
    public function testDeployDetails()
    {
        // create review record for change 1
        $this->createChange();
        $review = Review::createFromChange('1')->save();
        $token  = $review->getToken();

        // ensure starting deploy details of empty array.
        $this->assertSame($review->get('deployDetails'), array());

        // dispatch and check output
        $this->getRequest()->setQuery(new Parameters(array('url' => 'http://test.com')));
        $this->dispatch('/reviews/2/deploy/success/' . $token);
        $result = $this->getResult();
        $review = Review::fetch(2, $this->p4);
        $this->assertRoute('review-deploy');
        $this->assertResponseStatusCode(200);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame(array('url' => 'http://test.com'), $review->get('deployDetails'));

        // ensure that deployDetails is reset if deploy status is passed with no additional details
        $this->resetApplication();
        $this->dispatch('/reviews/2/deploy/success/' . $token);
        $result = $this->getResult();
        $review = Review::fetch(2, $this->p4);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame(array(), $review->get('deployDetails'));

        // ensure both post and get params are captured in details
        $this->resetApplication();
        $this->getRequest()
             ->setQuery(new Parameters(array('x' => 'foo')))
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('y' => 'bar')));

        $this->dispatch('/reviews/2/deploy/success/' . $token, false);
        $result  = $this->getResult();
        $review  = Review::fetch(2, $this->p4);
        $details = $review->get('deployDetails');
        asort($details);
        $this->assertSame(true, $result->getVariable('isValid'));
        $this->assertSame(array('y' => 'bar', 'x' => 'foo'), $details);
    }

    /**
     * Test updating a review record's success/fail deploy status with missing and invalid token.
     */
    public function testDeployStatusMissingBadToken()
    {
        // create review record for change 1
        $this->createChange();
        $review = Review::createFromChange('1')->save();

        // ensure starting test status of null.
        $this->assertSame($review->get('deployStatus'), null);

        // dispatch and check output with no token
        $this->dispatch('/reviews/2/deploy/fail');
        $result = $this->getResult();
        $this->assertRoute('review-deploy');
        $this->assertResponseStatusCode(403);

        // dispatch and check output with bad token
        $this->dispatch('/reviews/2/deploy/fail/FFFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF');
        $result = $this->getResult();
        $this->assertRoute('review-deploy');
        $this->assertResponseStatusCode(403);
    }

    public function testDeleteVersion()
    {
        $this->createProjects();

        // make first version
        $change = new Change($this->p4);
        $change->setDescription('v1')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/main/foo')->setLocalContents('test')->add($change->getId());
        $this->p4->run('shelve', array('-c', $change->getId()));

        // invoke review add action
        $post = new Parameters(array('change' => 1));
        $this->getRequest()->setMethod('POST')->setPost($post);
        $this->dispatch('/review/add');

        // process review in queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // should have 1 version/project, 2 changes and 0 commits
        $review = Review::fetch(2, $this->p4);
        $this->assertSame(1, count($review->getVersions()));
        $this->assertSame(1, count($review->getProjects()));
        $this->assertSame(2, count($review->getChanges()));
        $this->assertSame(0, count($review->getCommits()));

        // make second version
        $change = new Change($this->p4);
        $change->setDescription('[review-2]')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/dev/foo')->setLocalContents('test')->add($change->getId());
        $change->submit();

        // fake a review update event
        $queue = $this->getApplication()->getServiceManager()->get('queue');
        $queue->addTask('review', 2, array('updateFromChange' => $change->getId()));

        // process update in queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // should have 2 versions, 1 project, 3 changes and 1 commit
        $review = Review::fetch(2, $this->p4);
        $this->assertSame(2, count($review->getVersions()));
        $this->assertSame(1, count($review->getProjects()));
        $this->assertSame(3, count($review->getChanges()));
        $this->assertSame(1, count($review->getCommits()));

        // now nuke the second version (switch to an admin)
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $services->get('p4_admin'));
        $this->dispatch('/reviews/2/v2/delete');

        // should have 1 version/project, 2 changes and 0 commits
        $review = Review::fetch(2, $this->p4);
        $this->assertSame(1, count($review->getVersions()));
        $this->assertSame(1, count($review->getProjects()));
        $this->assertSame(2, count($review->getChanges()));
        $this->assertSame(0, count($review->getCommits()));
    }

    /**
     * Test dropping a reviewer
     */
    public function testRemoveReviewer()
    {
        $user = new User;
        $user->setId('joe')->setEmail('joe')->setFullName('joe')->save();
        $user->setId('jane')->setEmail('jane')->setFullName('jane')->save();

        $review = new Review($this->p4);
        $review->setParticipants(array('joe', 'nonadmin', 'jane'))
               ->set('author', 'jane')
               ->save();

        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setQuery(new Parameters(array('_method' => 'delete')));

        $this->dispatch('/reviews/1/reviewers/nonadmin');

        $this->assertResponseStatusCode(200);

        $review = Review::fetch(1, $this->p4);
        $this->assertSame(array('joe'), $review->getReviewers());
    }

    /**
     * Test review action with IP-based protections.
     */
    public function testReviewActionWithIpProtections()
    {
        // create a change with multiple files
        $change = new Change($this->p4);
        $change->setDescription('test')->save();
        $file = new File($this->p4);
        $file->setFilespec('//depot/foo')->setLocalContents('test foo')->add($change->getId());
        $file = new File($this->p4);
        $file->setFilespec('//depot/a/bar')->setLocalContents('test a/bar')->add($change->getId());
        $file = new File($this->p4);
        $file->setFilespec('//depot/a/baz')->setLocalContents('test a/baz')->add($change->getId());

        // shelve the change and invoke review add action
        $this->p4->run('shelve', array('-c', 1));
        $post = new Parameters(array('change' => 1));
        $this->getRequest()->setMethod('POST')->setPost($post);
        $this->dispatch('/review/add');

        // process review in queue
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // ensure that with no protection emulation, user can see all files (test both review and change pages)
        $pages = array('/changes/1', '/reviews/2');
        foreach ($pages as $url) {
            $this->resetApplication();

            // disable ip protections
            $this->getApplication()->getServiceManager()->get('ip_protects')->setEnabled(false);

            $this->dispatch($url);
            $this->assertQueryCount('.change-wrapper .change-files .diff-wrapper', 3);
            $this->assertQueryContentContains('.change-wrapper .change-files .diff-wrapper .filename', 'foo');
            $this->assertQueryContentContains('.change-wrapper .change-files .diff-wrapper .filename', 'a/bar');
            $this->assertQueryContentContains('.change-wrapper .change-files .diff-wrapper .filename', 'a/baz');
        }

        // dispatch the review page again, but with limited access and ensure the user can see only files
        // he has read access to
        foreach ($pages as $url) {
            $this->resetApplication();

            // enable IP protections emulation
            $protections = array(
                array(
                    'depotFile' => '//depot/...',
                    'perm'      => 'list'
                ),
                array(
                    'depotFile' => '//depot/a/...',
                    'perm'      => 'list',
                    'unmap'     => true
                )
            );
            $this->getApplication()->getServiceManager()->get('ip_protects')
                ->setEnabled(true)
                ->setProtections($protections);

            $this->dispatch($url);

            $this->assertQueryCount('.change-wrapper .change-files .diff-wrapper', 1, $url);
            $this->assertQueryContentContains('.change-wrapper .change-files .diff-wrapper .filename', 'foo');
        }
    }

    /**
     * Test review action with restricted changes.
     */
    public function testReviewActionWithProtectedChanges()
    {
        // create user with limited access
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create several changes
        $files   = array('//depot/test1', '//depot/foo/bar', '//depot/test2');
        $changes = array();
        foreach ($files as $key => $path) {
            $file = new File;
            $file->setFilespec($path)->open()->setLocalContents('abc ' . $key);
            $change = new Change($this->p4);
            $change->setType('restricted')->addFile($file)->submit('change ' . $key);
            $changes[$key + 1] = $change->getId();
        }

        // prepare tests
        $tests = array(
            array(
                'changes'      => array($changes[1], $changes[2]),
                'canFooAccess' => 1
            ),
            array(
                'changes'      => array($changes[1], $changes[2], $changes[3]),
                'canFooAccess' => 0
            ),
            array(
                'changes'      => array($changes[1]),
                'canFooAccess' => 0
            ),
            array(
                'changes'      => array(max($changes) + 1, max($changes) + 2),
                'canFooAccess' => 1
            ),
            array(
                'changes'      => array($changes[1], $changes[3], max($changes) + 1),
                'canFooAccess' => 1
            )
        );

        foreach ($tests as $test) {
            $review = new Review($this->p4);
            $review->setChanges($test['changes'])->save();

            // standard user should have access
            $this->resetApplication();
            $this->dispatch('/reviews/' . $review->getId());

            $result = $this->getResult();
            $this->assertRoute('review');
            $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'review');
            $this->assertResponseStatusCode(200);

            // verify access for 'foo'
            $this->resetApplication();
            $this->getApplication()->getServiceManager()->setService('p4', $p4Foo);
            $this->dispatch('/reviews/' . $review->getId());

            $result = $this->getResult();
            $this->assertRoute('review');
            $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'review');
            $this->assertResponseStatusCode($test['canFooAccess'] ? 200 : 403);
        }
    }

    /**
     * @dataProvider transitionsProvider
     */
    public function testReviewActionWithAcl($projects, $tests)
    {
        // create projects
        foreach ($projects as $data) {
            $project = new Project($this->p4);
            $project->set($data)->save();
        }

        $userConnections = array();
        foreach ($tests as $testId => $test) {
            $services = $this->getApplication()->getServiceManager();

            // create change containing given files
            $change = new Change($this->p4);
            $change->setDescription('test')->save();
            foreach ($test['reviewFiles'] as $fileSpec) {
                $fileExists = File::exists($fileSpec, $this->p4);

                $file = new File($this->p4);
                $file->setFilespec($fileSpec)->setLocalContents('123');
                if ($fileExists) {
                    $file->open($change->getId());
                } else {
                    $file->add($change->getId());
                }
            }
            $change->submit();

            // create review from the change
            $review = Review::createFromChange($change->getId());
            $review->setPending(true);
            $review->addProjects(Project::getAffectedByChange($change, $this->p4));
            $review->set('author', isset($test['reviewAuthor']) ? $test['reviewAuthor'] : $review->get('author'));
            $review->save();

            // temporarily tweak config if required
            $configOld = $services->get('config');
            if (isset($test['config'])) {
                $services->setService(
                    'config',
                    array_replace_recursive($configOld, $test['config'])
                );
            }

            // runs tests, each test contains user to connect as in key and array
            // with [review state => expected list of transitions] in value
            foreach ($test['asserts'] as $user => $cases) {
                // emulate connecting as given user
                if (!isset($userConnections[$user])) {
                    $userConnections[$user] = $this->connectWithAccess($user, array('//...'));
                }
                $services->get('p4_admin')->getService('cache')->invalidateItem('users');
                $services->setService('user',    User::fetch($user, $this->p4));
                $services->setService('p4_user', $userConnections[$user]);

                foreach ($cases as $state => $expectedTransitions) {
                    // set review state
                    $review->setState($state)->save();

                    // dispatch and verify transitions
                    $this->dispatch('/reviews/' . $review->getId());
                    $this->assertResponseStatusCode(200);
                    $this->assertRoute('review');
                    $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'review');

                    $transitions = $this->getResult()->getVariable('transitions');
                    $transitions = is_array($transitions) ? array_keys($transitions) : $transitions;

                    // sort transitions (if array) before comparing (order is not significant)
                    is_array($transitions)         && sort($transitions);
                    is_array($expectedTransitions) && sort($expectedTransitions);

                    $this->assertSame(
                        $expectedTransitions,
                        $transitions,
                        "Unexpected transitions: [Test Id: $testId, User: '$user', State: '$state']."
                    );
                }
            }

            // restore config
            $services->setService('config', $configOld);
        }
    }

    public function transitionsProvider()
    {
        // prepare transition state names
        $needsReview    = 'needsReview';
        $needsRevision  = 'needsRevision';
        $approved       = 'approved';
        $approvedCommit = 'approved:commit';
        $rejected       = 'rejected';
        $archived       = 'archived';

        // prepare some transition sets
        $allStates                 = array(
            $needsReview,
            $needsRevision,
            $approved,
            $approvedCommit,
            $rejected,
            $archived
        );
        $allTransitions            = array(
            $needsReview   => array_diff($allStates, array($needsReview)),
            $needsRevision => array_diff($allStates, array($needsRevision)),
            $approved      => array_diff($allStates, array($approved)),
            $rejected      => array_diff($allStates, array($rejected)),
            $archived      => array_diff($allStates, array($archived))
        );
        $allButApprovedTransitions = array(
            $needsReview   => array_diff($allStates, array($needsReview,   $approved, $approvedCommit)),
            $needsRevision => array_diff($allStates, array($needsRevision, $approved, $approvedCommit)),
            $approved      => array_diff($allStates, array($approved)),
            $rejected      => array_diff($allStates, array($rejected,      $approved, $approvedCommit)),
            $archived      => array_diff($allStates, array($archived,      $approved, $approvedCommit))
        );
        $noTransitions             = array(
            $needsReview   => false,
            $needsRevision => false,
            $approved      => false,
            $rejected      => false,
            $archived      => false
        );
        $memberOnlyTransitions     = array(
            $needsReview   => array($needsRevision),
            $needsRevision => array($needsReview),
            $approved      => array($needsReview, $needsRevision, $approvedCommit),
            $rejected      => array(),
            $archived      => array(),
        );

        // tests sub-array 'asserts' contains user to authenticate as in key and array
        // of [<reviewState> => <list of expected transitions>] in value
        return array(
            'no-projects' => array(
                'projects' => array(),
                'tests'    => array(
                    array(
                        'reviewFiles'  => array('//depot/a/file'),
                        'reviewAuthor' => 'a',
                        'asserts'      => array(
                            'a' => $allTransitions,
                            'b' => $allTransitions
                        ),
                    ),
                    // verify that author cannot approve if self-approve is disabled
                    array(
                        'reviewFiles'  => array('//depot/a/file'),
                        'config'       => array('reviews' => array('disable_self_approve' => true)),
                        'reviewAuthor' => 'a',
                        'asserts'      => array(
                            'a' => $allButApprovedTransitions,
                            'b' => $allTransitions
                        ),
                    ),
                )
            ),
            'no-mods-single' => array(
                'projects' => array(
                    array(
                        'id'       => 'prj1',
                        'members'  => array('a', 'b'),
                        'branches' => array(
                            array(
                                'id'            => 'a',
                                'name'          => 'A',
                                'paths'         => '//depot/a/...',
                                'moderators'    => array()
                            ),
                        ),
                    ),
                ),
                'tests' => array(
                    array(
                        'reviewFiles'  => array('//depot/a/file'),
                        'asserts'      => array(
                            'foo' => $noTransitions,
                            'a'   => $allTransitions
                        ),
                    ),
                    // verify that author cannot approve if self-approve is disabled
                    array(
                        'reviewAuthor' => 'a',
                        'config'       => array('reviews' => array('disable_self_approve' => true)),
                        'reviewFiles'  => array('//depot/a/file'),
                        'asserts'      => array(
                            'foo' => $noTransitions,
                            'b'   => $allTransitions,
                            'a'   => $allButApprovedTransitions
                        ),
                    ),
                ),
            ),
            'no-mods-multi' => array(
                'projects' => array(
                    array(
                        'id'       => 'prj1',
                        'members'  => array('a', 'b'),
                        'branches' => array(
                            array(
                                'id'            => 'a',
                                'name'          => 'A',
                                'paths'         => '//depot/a/...',
                                'moderators'    => array()
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'prj2',
                        'members'  => array('a', 'c'),
                        'branches' => array(
                            array(
                                'id'            => 'b',
                                'name'          => 'B',
                                'paths'         => '//depot/b/...',
                                'moderators'    => array()
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'prj3',
                        'members'  => array('x', 'y', 'z'),
                        'branches' => array(
                            array(
                                'id'            => 'c',
                                'name'          => 'C',
                                'paths'         => '//depot/c/...',
                                'moderators'    => array()
                            ),
                        ),
                    ),
                ),
                'tests' => array(
                    // test review touching projects 1 & 3
                    // members:    [a, b, x, y, z]
                    // moderators: []
                    array(
                        'reviewFiles'  => array('//depot/a/file', '//depot/c/file'),
                        'asserts'      => array(
                            'foo' => $noTransitions,
                            'c'   => $noTransitions,
                            'a'   => array(
                                $needsReview => array_diff($allStates, array($needsReview))
                            ),
                            'y'   => array(
                                $needsReview => array_diff($allStates, array($needsReview))
                            ),
                        ),
                    ),
                ),
            ),
            'with-mods-single' => array(
                'projects' => array(
                    array(
                        'id'       => 'prj1',
                        'members'  => array('a', 'b', 'c'),
                        'branches' => array(
                            array(
                                'id'            => 'a',
                                'name'          => 'A',
                                'paths'         => '//depot/a/...',
                                'moderators'    => array('m1', 'm2')
                            )
                        )
                    ),
                ),
                'tests' => array(
                    array(
                        'reviewFiles'  => array('//depot/a/file', '//depot/x/foo'),
                        'asserts'      => array(
                            'foo' => $noTransitions,
                            'a'   => $memberOnlyTransitions,
                            'm1'  => $allTransitions
                        ),
                    ),
                ),
            ),
            'with-mods-multi' => array(
                'projects' => array(
                    array(
                        'id'       => 'prj1',
                        'members'  => array('a', 'b', 'c'),
                        'branches' => array(
                            array(
                                'id'            => 'a',
                                'name'          => 'A',
                                'paths'         => '//depot/a/...',
                                'moderators'    => array('m1', 'a')
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'prj2',
                        'members'  => array('joe'),
                        'branches' => array(
                            array(
                                'id'            => 'a2',
                                'name'          => 'A2',
                                'paths'         => '//depot/a/...',
                                'moderators'    => array()
                            ),
                            array(
                                'id'            => 'b',
                                'name'          => 'B',
                                'paths'         => '//depot/b/...',
                                'moderators'    => array('joe')
                            ),
                        ),
                    ),
                    array(
                        'id'       => 'prj3',
                        'members'  => array('x', 'y', 'z'),
                        'branches' => array(
                            array(
                                'id'            => 'c',
                                'name'          => 'C',
                                'paths'         => '//depot/c/...',
                                'moderators'    => array('x', 'm2')
                            ),
                        ),
                    ),
                ),
                'tests' => array(
                    // test review touching projects 1 & 2
                    // members:    [a, b, c, joe]
                    // moderators: [m1, a]
                    array(
                        'reviewFiles'  => array('//depot/a/file', '//depot/x/foo'),
                        'reviewAuthor' => 'joe',
                        'asserts'      => array(
                            'foo' => $noTransitions,
                            'joe' => array(
                                $needsReview   => array($needsRevision, $archived),
                                $needsRevision => array($needsReview, $archived),
                                $approved      => array($needsReview, $needsRevision, $approvedCommit, $archived),
                                $rejected      => array(),
                                $archived      => array($needsReview, $needsRevision)
                            ),
                            'a'   => $allTransitions,
                            'b'   => $memberOnlyTransitions,
                            'm1'  => $allTransitions,
                            'm2'  => $noTransitions
                        ),
                    ),
                    // test author as the only moderator
                    array(
                        'reviewFiles'  => array('//depot/b/file'),
                        'reviewAuthor' => 'joe',
                        'asserts'      => array(
                            'foo' => $noTransitions,
                            'joe' => $allTransitions
                        ),
                    ),
                    // test author as one of many moderators
                    array(
                        'reviewFiles'  => array('//depot/a/file'),
                        'reviewAuthor' => 'a',
                        'asserts'      => array(
                            'a'  => $allTransitions,
                            'b'  => $memberOnlyTransitions,
                            'c'  => $memberOnlyTransitions,
                            'm1' => $allTransitions
                        ),
                    ),
                    // verify that authors who are also moderators cannot approve their own reviews
                    array(
                        'config'       => array('reviews' => array('disable_self_approve' => true)),
                        'reviewFiles'  => array('//depot/a/file'),
                        'reviewAuthor' => 'a',
                        'asserts'      => array(
                            'a'  => $allButApprovedTransitions,
                            'b'  => $memberOnlyTransitions,
                            'c'  => $memberOnlyTransitions,
                            'm1' => $allTransitions
                        ),
                    )
                ),
            ),
        );
    }

    /**
     * Test that voting on old versions (different from head) results in stale votes.
     */
    public function testStaleVotes()
    {
        // create review to test with
        $review = new Review($this->p4);
        $review->setParticipants(array('foo', 'bar'))
               ->set('author', 'joe')
               ->save();

        // add several versions
        $review->setVersions(
            array(
                array('change' => 2, 'user' => 'joe', 'time' => 700, 'pending' => 1, 'difference' => 1),
                array('change' => 3, 'user' => 'joe', 'time' => 710, 'pending' => 1, 'difference' => 1),
                array('change' => 4, 'user' => 'joe', 'time' => 720, 'pending' => 1, 'difference' => 2),
            )
        )->save();

        // prepare connections for users 'foo', 'bar'
        $p4Foo   = $this->connectWithAccess('foo', array('//...'));
        $p4Bar   = $this->connectWithAccess('bar', array('//...'));
        $userFoo = User::fetch('foo', $this->p4);
        $userBar = User::fetch('bar', $this->p4);

        // test that voting without version applies to latest
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Foo)
                 ->setService('user', $userFoo);
        $this->getRequest()->setMethod('POST');
        $this->dispatch('/reviews/' . $review->getId() . '/vote/up');

        $votes = Review::fetch($review->getId(), $this->p4)->getVotes();
        $this->assertSame(1,     count($votes));
        $this->assertSame(1,     $votes['foo']['value']);
        $this->assertSame(3,     $votes['foo']['version']);
        $this->assertSame(false, $votes['foo']['isStale']);

        // try voting on a particular version
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Foo)
                 ->setService('user', $userFoo);
        $this->getRequest()->setMethod('POST')->setPost(new Parameters(array('version' => 2)));
        $this->dispatch('/reviews/' . $review->getId() . '/vote/up');

        $votes = Review::fetch($review->getId(), $this->p4)->getVotes();
        $this->assertSame(1,     count($votes));
        $this->assertSame(1,     $votes['foo']['value']);
        $this->assertSame(2,     $votes['foo']['version']);
        $this->assertSame(false, $votes['foo']['isStale']);

        // vote as 'bar' on version 1 and verify its evaluated as stale vote
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $services->setService('p4_user', $p4Bar)
                 ->setService('user', $userBar);
        $this->getRequest()->setMethod('POST')->setPost(new Parameters(array('version' => 1)));
        $this->dispatch('/reviews/' . $review->getId() . '/vote/down');

        $votes = Review::fetch($review->getId(), $this->p4)->getVotes();
        $this->assertSame(2,     count($votes));
        $this->assertSame(1,     $votes['foo']['value']);
        $this->assertSame(2,     $votes['foo']['version']);
        $this->assertSame(false, $votes['foo']['isStale']);
        $this->assertSame(-1,    $votes['bar']['value']);
        $this->assertSame(1,     $votes['bar']['version']);
        $this->assertSame(true,  $votes['bar']['isStale']);
    }

    public function testVoteAction()
    {
        // create users
        $joe = new User($this->p4);
        $joe->setId('joe')
            ->setEmail('joe@example.com')
            ->setFullName('Mr Bastianich')
            ->setPassword('abcd1234')
            ->save();

        $graham = new User($this->p4);
        $graham->setId('graham')
               ->setEmail('graham@example.com')
               ->setFullName('Mr Elliot')
               ->setPassword('abcd1234')
               ->save();

        $review = new Review($this->p4);
        $review->setParticipants(array('joe', 'graham'))
               ->set('author', 'graham')
               ->save();

        $reviewId = $review->getId();

        // switch to the joe user
        $services = $this->getApplication()->getServiceManager();
        $auth     = $services->get('auth');
        $adapter  = new \Users\Authentication\Adapter('joe', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        // vote down
        $this->getRequest()->setMethod('POST');
        $this->dispatch('/reviews/' . $reviewId . '/vote/down');
        $this->assertResponseStatusCode(200);

        $review = Review::fetch($reviewId, $this->p4);
        $votes  = $review->getVotes(false);
        $this->assertSame(-1, $votes['joe']['value']);

        // vote up
        $this->resetApplication();

        // switch to the joe user
        $services = $this->getApplication()->getServiceManager();
        $auth     = $services->get('auth');
        $adapter  = new \Users\Authentication\Adapter('joe', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $this->getRequest()->setMethod('POST');
        $this->dispatch('/reviews/' . $reviewId . '/vote/up');
        $this->assertResponseStatusCode(200);

        $review = Review::fetch($reviewId, $this->p4);
        $votes  = $review->getVotes(false);
        $this->assertSame(1, $votes['joe']['value']);

        // vote clear
        $this->resetApplication();

        // switch to the joe user
        $services = $this->getApplication()->getServiceManager();
        $auth     = $services->get('auth');
        $adapter  = new \Users\Authentication\Adapter('joe', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $this->getRequest()->setMethod('POST');
        $this->dispatch('/reviews/' . $reviewId . '/vote/clear');
        $this->assertResponseStatusCode(200);

        $review = Review::fetch($reviewId, $this->p4);
        $this->assertSame(array(), $review->getVotes(false));
    }

    /**
     * Verify edit reviewers works ensuring:
     * - votes are not lost
     * - required reviewers editing works
     * - the 'editor' doesn't get erroneously dragged in as a reviewer by editing the list
     */
    public function testEditReviewers()
    {
        $user = new User;
        $user->setId('gnicol')->setEmail('gnicol@host.com')->setFullName('gnicol')->save();
        $user->setId('dj')->setEmail('dj@host.com')->setFullName('dj')->save();

        // create review record for change 1 and prime it with a vote
        $this->createChange();
        Review::createFromChange('1')->save()->updateFromChange('1')
              ->setParticipantData('gnicol', true, 'required')->addVote('gnicol', 1)
              ->save();

        // there shouldn't be any tasks but proccess just to be safe
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify the starting state is good
        $this->assertSame(
            array(
                'admin'  => array(),
                'gnicol' => array(
                    'required' => true, 'vote' => array('value' => 1, 'version' => 1, 'isStale' => false)
                )
            ),
            Review::fetch('2', $this->p4)->getParticipantsData()
        );

        $expected = array(
            'admin'  => array(),    // as they are the author; can't get rid of em
            'dj'     => array('required' => true),
            'gnicol' => array('vote' => array('value' => 1, 'version' => 1, 'isStale' => false))
        );
        $postData = new Parameters(
            array(
                'reviewers'         => array('gnicol', 'dj'),
                'requiredReviewers' => array('dj')
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/reviews/2/reviewers');
        $result = $this->getResult();
        $review = $result->getVariable('review');
        $this->assertRoute('review-reviewers');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        // @codingStandardsIgnoreStart
        $this->assertSame(true, $result->getVariable('isValid'), print_r($result->getVariables(), true));
        // @codingStandardsIgnoreEnd
        $this->assertSame(
            $expected,
            $review['participantsData']
        );

        // process all tasks as they may impact things
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify participants are still correct
        $review = Review::fetch('2', $this->p4);
        $this->assertSame(
            $expected,
            $review->getParticipantsData()
        );
    }

    public function testPatchReviewerRequiredToggle()
    {
        // create review record for change 1 and prime it with a required reviewer with a vote
        $this->createChange();
        Review::createFromChange('1')->save()->updateFromChange('1')
            ->setParticipantData('nonadmin', true, 'required')->addVote('nonadmin', 1)
            ->save();

        // there shouldn't be any tasks but proccess just to be safe
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify the starting state is good
        $this->assertSame(
            array(
                'admin'    => array(),
                'nonadmin' => array(
                    'required' => true, 'vote' => array('value' => 1, 'version' => 1, 'isStale' => false)
                )
            ),
            Review::fetch('2', $this->p4)->getParticipantsData()
        );

        $expected = array(
            'admin'    => array(),    // as they are the author; can't get rid of em
            'nonadmin' => array('vote' => array('value' => 1, 'version' => 1, 'isStale' => false))
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('required' => false)))
            ->setQuery(new Parameters(array('_method' => 'patch')));

        // dispatch and check output
        $this->dispatch('/reviews/2/reviewers/nonadmin');
        $this->assertRoute('review-reviewer');
        $this->assertResponseStatusCode(200);
        $result = $this->getResult();
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $review = $result->getVariable('review');
        // @codingStandardsIgnoreStart
        $this->assertSame(true, $result->getVariable('isValid'), print_r($result->getVariables(), true));
        // @codingStandardsIgnoreEnd
        $this->assertSame(
            $expected,
            $review['participantsData']
        );

        // proccess all tasks as they may impact things
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify participants are still correct
        $review = Review::fetch('2', $this->p4);
        $this->assertSame(
            $expected,
            $review->getParticipantsData()
        );


        // now try using patch and setting it to required
        $expected['nonadmin'] = array(
            'required' => true, 'vote' => array('value' => 1, 'version' => 1, 'isStale' => false)
        );
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_PATCH)
            ->setPost(new Parameters(array('required' => '1')));

        // dispatch and check output
        $this->dispatch('/reviews/2/reviewers/nonadmin');
        $this->assertRoute('review-reviewer');
        $this->assertResponseStatusCode(200);
        $result = $this->getResult();
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $review = $result->getVariable('review');
        // @codingStandardsIgnoreStart
        $this->assertSame(true, $result->getVariable('isValid'), print_r($result->getVariables(), true));
        // @codingStandardsIgnoreEnd
        $this->assertSame(
            $expected,
            $review['participantsData']
        );

        // proccess all tasks as they may impact things
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // verify participants are still correct
        $review = Review::fetch('2', $this->p4);
        $this->assertSame(
            $expected,
            $review->getParticipantsData()
        );
    }

    /**
     * codify tests for the following table which shows how we treat each file
     * given different combinations of left/right actions:
     *
     *                      R I G H T
     *
     *                |  A  |  E  |  D  |  X
     *           -----+-----+-----+-----+-----
     *             A  |  E  |  E  |  D  | D/R
     *       L   -----+-----+-----+-----+-----
     *       E     E  |  E  |  E  |  D  | E/R
     *       F   -----+-----+-----+-----+-----
     *       T     D  |  A  |  A  |  R  | A/R
     *           -----+-----+-----+-----+-----
     *             X  |  A  |  E  |  D  | n/a
     *
     *    A = add
     *    E = edit
     *    D = delete
     *    X = not present
     *    R = remove (no difference)
     *  D/R = delete if left shelved, otherwise remove (no diff)
     *  E/R = reverse diff (edits undone) if left shelved, otherwise remove (no diff)
     *  A/R = add if left shelved, otherwise remove (no diff)
     *
     * via separate consumers we run each test with:
     * - left committed, right committed
     * - left committed, right shelved
     * - left shelved,   right committed
     * - left shelved,   right shelved
     *
     * we occasionally include a 'new' or 'other' file just to force differences between
     * the review revisions allowing shelved updates to take despite two restrictions:
     * 1) we want at least one file in each version
     * 2) ensure v1/v2 differ so updating the review doesn't skip creating a version
     *
     * when listing the anticipated result the text 'v1' or 'v2' can be used in place
     * of the shelved change specifier (e.g. @=3) to improve clarity. It will be
     * resolved at test time to the actual value.
     */
    public function diffProvider()
    {
        return array(
            'AA' => array(  // both sides add but there are no content differences, drops file
                array('AA' => 'add'),
                array('AA' => 'add', "AANew" => 'add'), // need to ensure shelves differ for version to be created
                array(
                    'cc' => array('AANew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'cs' => array('AANew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                    'sc' => array('AANew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'ss' => array('AANew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                )
            ),
            'AA2' => array( // both sides add but content differs, change to an edit
                array('AA2' => 'add'),
                array('AA2' => array('action' => 'add', 'content' => 'changed!')),
                array(
                    'cc' => array('AA2' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => '#3')),
                    'cs' => array('AA2' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                    'sc' => array('AA2' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => '#1')),
                    'ss' => array('AA2' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'AE' => array(  // add on left, edit of same file on right but content is same, drops file
                array('AE' => array('action' => 'add',  'content' => 'same!')),
                array('AE' => array('action' => 'edit', 'content' => 'same!')),
                array(
                    'cc' => array(),
                    'cs' => array(),
                    'sc' => array(),
                    'ss' => array()
                )
            ),
            'AE2' => array( // add on left, edit of same file on right and content differs, shows as edit
                array('AE2' => 'add'),
                array('AE2' => 'edit'),
                array(
                    'cc' => array('AE2' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => '#2')),
                    'cs' => array('AE2' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                    'sc' => array('AE2' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => '#2')),
                    'ss' => array('AE2' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'AD' => array(  // add on left, delete on right, shows as delete
                array('AD' => 'add'),
                array('AD' => 'delete'),
                array(
                    'cc' => array('AD' => array('action' => 'delete', 'diffLeft' => '#1', 'diffRight' => '#2')),
                    'cs' => array('AD' => array('action' => 'delete', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                    'sc' => array('AD' => array('action' => 'delete', 'diffLeft' => 'v1', 'diffRight' => '#2')),
                    'ss' => array('AD' => array('action' => 'delete', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'AX' => array(  // add on left, file not present on right. shows as delete if left shelved otherwise drops
                array('AXOther' => 'add', 'AX'    => 'add'),
                array('AXOther' => 'add'),
                array(
                    'cc' => array(),
                    'cs' => array(),
                    'sc' => array('AX' => array('action' => 'delete', 'diffLeft' => 'v1', 'diffRight' => null)),
                    'ss' => array('AX' => array('action' => 'delete', 'diffLeft' => 'v1', 'diffRight' => null)),
                )
            ),
            'EA' => array(  // edit on left, add on right, shows as edit
                array('EA' => 'edit'),
                array('EA' => 'add'),
                array(
                    'cc' => array('EA' => array('action' => 'edit', 'diffLeft' => '#2', 'diffRight' => '#4')),
                    'cs' => array('EA' => array('action' => 'edit', 'diffLeft' => '#2', 'diffRight' => 'v2')),
                    'sc' => array('EA' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => '#3')),
                    'ss' => array('EA' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'EE' => array(  // edit on both sides but same content, drop file
                array('EE' => 'edit'),
                array('EE' => 'edit', 'EENew' => 'add'),
                array(
                    'cc' => array('EENew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'cs' => array('EENew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                    'sc' => array('EENew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'ss' => array('EENew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                )
            ),
            'EE2' => array( // edit on both sides but different content, shows as edit
                array('EE2' => 'edit'),
                array('EE2' => array('action' => 'edit', 'content' => 'edited!')),
                array(
                    'cc' => array('EE2' => array('action' => 'edit', 'diffLeft' => '#2', 'diffRight' => '#3')),
                    'cs' => array('EE2' => array('action' => 'edit', 'diffLeft' => '#2', 'diffRight' => 'v2')),
                    'sc' => array('EE2' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => '#2')),
                    'ss' => array('EE2' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'ED' => array(  // edit on left, delete on right shows as delete
                array('ED' => 'edit'),
                array('ED' => 'delete'),
                array(
                    'cc' => array('ED' => array('action' => 'delete', 'diffLeft' => '#2', 'diffRight' => '#3')),
                    'cs' => array('ED' => array('action' => 'delete', 'diffLeft' => '#2', 'diffRight' => 'v2')),
                    'sc' => array('ED' => array('action' => 'delete', 'diffLeft' => 'v1', 'diffRight' => '#2')),
                    'ss' => array('ED' => array('action' => 'delete', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'EX' => array(  // edit on left, missing on right, shows as reverse edit if left shelved otherwise drops
                array('EXOther' => 'add', 'EX' => 'edit'),
                array('EXOther' => 'add'),
                array(
                    'cc' => array(),
                    'cs' => array(),
                    'sc' => array('EX' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => '#1')),
                    'ss' => array('EX' => array('action' => 'edit', 'diffLeft' => 'v1', 'diffRight' => '#1')),
                )
            ),
            'DA' => array(  // delete on left add on right, shows as add
                array('DA' => 'delete'),
                array('DA' => 'add'),
                array(
                    'cc' => array('DA' => array('action' => 'add', 'diffLeft' => '#2', 'diffRight' => '#3')),
                    'cs' => array('DA' => array('action' => 'add', 'diffLeft' => '#2', 'diffRight' => 'v2')),
                    'sc' => array('DA' => array('action' => 'add', 'diffLeft' => 'v1', 'diffRight' => '#3')),
                    'ss' => array('DA' => array('action' => 'add', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'DE' => array(  // delete on left edit on right, shows as add
                array('DE' => 'delete'),
                array('DE' => 'edit'),
                array(
                    'cc' => array('DE' => array('action' => 'add', 'diffLeft' => '#2', 'diffRight' => '#4')),
                    'cs' => array('DE' => array('action' => 'add', 'diffLeft' => '#2', 'diffRight' => 'v2')),
                    'sc' => array('DE' => array('action' => 'add', 'diffLeft' => 'v1', 'diffRight' => '#2')),
                    'ss' => array('DE' => array('action' => 'add', 'diffLeft' => 'v1', 'diffRight' => 'v2')),
                )
            ),
            'DD' => array(  // delete on both sides, file dropped
                array('DD' => 'delete'),
                array('DD' => 'delete', 'DDNew' => 'add'),
                array(
                    'cc' => array('DDNew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'cs' => array('DDNew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                    'sc' => array('DDNew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'ss' => array('DDNew' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                )
            ),
            'DX' => array(  // delete on left missing on right, shows as add if left shelved otherwise drops
                array('DXOther' => 'add', 'DX' => 'delete'),
                array('DXOther' => 'add'),
                array(
                    'cc' => array(),
                    'cs' => array(),
                    'sc' => array('DX' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'ss' => array('DX' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                )
            ),
            'XA' => array(  // missing on left, add on right, shows as add
                array('XAOther' => 'add'),
                array('XAOther' => 'add', 'XA' => 'add'),
                array(
                    'cc' => array('XA' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'cs' => array('XA' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                    'sc' => array('XA' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => '#1')),
                    'ss' => array('XA' => array('action' => 'add', 'diffLeft' => null, 'diffRight' => 'v2')),
                )
            ),
            'XE' => array(  // missing on left, edit on right, shows as edit
                array('XEOther' => 'add'),
                array('XEOther' => 'add', 'XE' => 'edit'),
                array(
                    'cc' => array('XE' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => '#2')),
                    'cs' => array('XE' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                    'sc' => array('XE' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => '#2')),
                    'ss' => array('XE' => array('action' => 'edit', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                )
            ),
            'XD' => array(  // missing on left, delete on right, shows as delete
                array('XDOther' => 'add'),
                array('XDOther' => 'add', 'XD' => 'delete'),
                array(
                    'cc' => array('XD' => array('action' => 'delete', 'diffLeft' => '#1', 'diffRight' => '#2')),
                    'cs' => array('XD' => array('action' => 'delete', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                    'sc' => array('XD' => array('action' => 'delete', 'diffLeft' => '#1', 'diffRight' => '#2')),
                    'ss' => array('XD' => array('action' => 'delete', 'diffLeft' => '#1', 'diffRight' => 'v2')),
                )
            )
        );
    }

    /**
     * @dataProvider diffProvider
     */
    public function testDiffCommitted($left, $right, $expected)
    {
        $this->doDiffTest($left, $right, $expected, true, true);
    }


    /**
     * @dataProvider diffProvider
     */
    public function testDiffShelved($left, $right, $expected)
    {
        $this->doDiffTest($left, $right, $expected, false, false);
    }

    /**
     * @dataProvider diffProvider
     */
    public function testDiffShelvedCommitted($left, $right, $expected)
    {
        $this->doDiffTest($left, $right, $expected, false, true);
    }

    /**
     * @dataProvider diffProvider
     */
    public function testDiffCommittedShelved($left, $right, $expected)
    {
        $this->doDiffTest($left, $right, $expected, true, false);
    }

    /**
     * Does all the actual work for test diff committed/shelved
     */
    public function doDiffTest($left, $right, $expected, $commitLeft = true, $commitRight = true)
    {
        // submit/shelve our left hand file(s) and create the review
        $lchange = $this->createDiffChange($left, $commitLeft);
        $review  = Review::createFromChange($lchange, $this->p4);
        $review->save()->updateFromChange($lchange)->save();

        // submit/shelve right hand file(s) and update the review
        $rchange = $this->createDiffChange($right, $commitRight);
        $review->updateFromChange($rchange)->save();

        // request the review page and verify file diff info is accurate
        $this->dispatch('/reviews/' . $review->getId() . "/v1,2");
        $result = $this->getResult();

        // first check we actually hit the right action successfully
        $this->assertRoute('review');
        $this->assertRouteMatch('reviews', 'reviews\controller\indexcontroller', 'review');
        $this->assertResponseStatusCode(200, $this->getResponse()->getBody());
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);

        // munge the files result into a standardized format
        $files  = $result->getVariable('files');
        $result = array();
        foreach ($files as $file) {
            $result[basename($file['depotFile'])] = array(
                'action'    => $file['action'],
                'diffLeft'  => $file['diffLeft'],
                'diffRight' => $file['diffRight']
            );
        }

        // select the appropriate expected values array for our shelved/committed left/right
        // state and ensure any 'v1' or 'v2' placeholders are replaced with @=<change>.
        $index    = ($commitLeft ? 'c' : 's') . ($commitRight ? 'c' : 's');
        $index    = is_string($expected[$index]) ? $expected[$index] : $index;
        $expected = $expected[$index];
        foreach ($expected as $file => &$values) {
            if ($values['diffRight'][0] === 'v') {
                $values['diffRight'] = '@=' . $review->getChangeOfVersion($values['diffRight'][1]);
            }
            if ($values['diffLeft'][0] === 'v') {
                $values['diffLeft'] = '@=' . $review->getChangeOfVersion($values['diffLeft'][1]);
            }
        }

        $this->assertSame(
            $expected,
            $result,
            "response should match for left change $lchange, right change $rchange in review " . $review->getId()
        );
    }

    /**
     * Test setting file read/unread
     */
    public function testFileInfoValidInput()
    {
        // need a review with two files, one deleted
        $this->createChangeWithDelete();

        // dispatch to create a review (verify it worked)
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('change' => 2)));
        $this->dispatch('/review/add');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($this->getResult()->getVariable('isValid'));

        // review needs a version for file-info to work, process queue to make one
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // above should create review id 3 and two files
        // now try to set the read status of a file
        $this->resetApplication();
        $file = '//depot/main/foo/test.txt';
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 1)));
        $this->dispatch('/reviews/3/v1/files/' . ltrim($file, '/'));

        $this->assertResponseStatusCode(200);
        $this->assertSame(
            $this->getResult()->getVariable('readBy'),
            array('nonadmin' => array('version' => 1, 'digest' => '613D3B9C91E9445ABAECA02F2342E5A6'))
        );

        // ensure record was truly written
        $fileInfo = FileInfo::fetch(FileInfo::composeId(3, $file), $this->p4);
        $this->assertTrue($fileInfo->isReadBy('nonadmin', 1, '613D3B9C91E9445ABAECA02F2342E5A6'));

        // now try to clear read status
        $this->resetApplication();
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 0)));
        $this->dispatch('/reviews/3/v1/files/' . ltrim($file, '/'));

        $this->assertResponseStatusCode(200);
        $this->assertSame(
            $this->getResult()->getVariable('readBy'),
            array()
        );

        // ensure record was truly written
        $fileInfo = FileInfo::fetch(FileInfo::composeId(3, $file), $this->p4);
        $this->assertFalse($fileInfo->isReadBy('nonadmin', 1, '613D3B9C91E9445ABAECA02F2342E5A6'));

        // now try to set the read status on the deleted file
        $this->resetApplication();
        $file = '//depot/main/foo/delete.txt';
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 1)));
        $this->dispatch('/reviews/3/v1/files/' . ltrim($file, '/'));

        $this->assertResponseStatusCode(200);
        $this->assertSame(
            $this->getResult()->getVariable('readBy'),
            array('nonadmin' => array('version' => 1, 'digest' => null))
        );

        // ensure record was truly written
        $fileInfo = FileInfo::fetch(FileInfo::composeId(3, $file), $this->p4);
        $this->assertTrue($fileInfo->isReadBy('nonadmin', 1, ''));
    }

    /**
     * Test setting file info with invalid urls and post data
     */
    public function testFileInfoInvalidInput()
    {
        // need a review with at least one file
        $this->createChange();

        // dispatch to create a review (verify it worked)
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('change' => 1)));
        $this->dispatch('/review/add');
        $this->assertResponseStatusCode(200);
        $this->assertTrue($this->getResult()->getVariable('isValid'));

        // review needs a version for file-info to work, process queue to make one
        $this->resetApplication();
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        // try with a bad review id in url (should 404)
        $file = '//depot/main/foo/test.txt';
        $this->resetApplication();
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 1)));
        $this->dispatch('/reviews/123/v1/files/' . ltrim($file, '/'));
        $this->assertResponseStatusCode(404);

        // invalid version
        $this->resetApplication();
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 1)));
        $this->dispatch('/reviews/2/v2/files/' . ltrim($file, '/'));
        $this->assertResponseStatusCode(404);

        // no file
        $this->resetApplication();
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 1)));
        $this->dispatch('/reviews/2/v1/files/');
        $this->assertResponseStatusCode(404);

        // invalid file
        $this->resetApplication();
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'nonadmin', 'read' => 1)));
        $this->dispatch('/reviews/2/v1/files/woozle/wobble');
        $this->assertResponseStatusCode(404);

        // mismatched user
        $this->resetApplication();
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'baduser', 'read' => 1)));
        $this->dispatch('/reviews/2/v1/files/' . ltrim($file, '/'));
        $this->assertResponseStatusCode(400);

        // invalid read value
        $this->resetApplication();
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost(new Parameters(array('user' => 'baduser', 'read' => 123)));
        $this->dispatch('/reviews/2/v1/files/' . ltrim($file, '/'));
        $this->assertResponseStatusCode(400);
    }

    public function testAddForwarding()
    {
        // properties require p4d 2013.1+
        // built-in web-server requires php 5.4+ and posix_kill
        if (version_compare(PHP_VERSION, '5.4', '<')
            || !function_exists('posix_kill')
            || !$this->p4->isServerMinVersion('2013.1')
        ) {
            $this->markTestSkipped('Requires PHP 5.4+ with posix_kill() and P4D 2013.1+');
        }

        try {
            // fake out an edge server
            $reflection = new \ReflectionClass('P4\Connection\AbstractConnection');
            $property   = $reflection->getProperty('info');
            $property->setAccessible(true);
            $property->setValue($this->p4, array('serverServices' => 'edge-server') + $this->p4->getInfo());

            // setup a script to act as the commit swarm instance
            $scriptFile = DATA_PATH . '/web-server.php';
            $script     = <<<EOT
<?php
echo json_encode(
    isset(\$_POST['change'])
        ? array('isValid' => true, 'id' => 2, 'review' => array('foo' => 'bar'))
        : array('isValid' => false, 'error' => 'oops!', 'change' => null)
);
EOT;
            file_put_contents($scriptFile, $script);

            // setup a web server to act as the commit swarm instance
            $port    = (getmypid() % 60000) + 1024;
            $logFile = DATA_PATH . '/web-server.log';
            $pid     = $this->startWebServer($port, $logFile, $scriptFile);
            $this->p4->run(
                'property',
                array('-a', '-n', 'P4.Swarm.CommitURL', '-v', 'http://localhost:' . $port, '-s0')
            );

            // request a new review without supplying a change (should fail)
            $this->getRequest()->setMethod(\Zend\Http\Request::METHOD_POST);
            $this->dispatch('/reviews/add');
            $result = $this->getResult();
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertSame(false, $result->getVariable('isValid'));
            $this->resetApplication();

            // request a new review with a change (should succeed)
            $this->getRequest()
                 ->setMethod(\Zend\Http\Request::METHOD_POST)
                 ->getPost()->set('change', 1);
            $this->dispatch('/reviews/add');
            $result = $this->getResult();
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertSame(true, $result->getVariable('isValid'));
        } catch (\Exception $e) {
        }

        // now kill the web-server
        posix_kill($pid, 15);
    }

    /**
     * Start built-in web-server on a given port.
     *
     * @param   string|int  $port        port to start web server at
     * @param   string      $logFile     web-server log file (will log both stdout and stderr)
     * @param   string      $scriptFile  the router script to use
     * @return  int         pid of the web-server process
     */
    protected function startWebServer($port, $logFile, $scriptFile)
    {
        // launch a web-server to monitor for requests from add review when forwarding
        $command = PHP_BINDIR . '/php -S localhost:' . $port . ' '
                 . $scriptFile . ' > ' . $logFile . ' 2>&1 & echo $!;';
        $pid     = exec($command, $output);

        // sleep to give the web-server a chance to startup
        usleep(250000);

        return $pid;
    }

    /**
     * Creates a change with the requested files in the requested state(s) with the specified contents.
     * If no contents are specified, the default value is "action name" e.g. "add foo".
     * If an action other than add is specified the file will be added first.
     * If the file already exists but an add was requested the file is deleted first.
     *
     * @param   array   $files      files array as generated by diffProvider
     * @param   bool    $commit     true if final change should be committed, false to simply shelve
     * @return  int     the change ID that contains the requested files/state
     */
    protected function createDiffChange($files, $commit = true)
    {
        // first add any files which aren't ultimately intended to be adds and
        // delete any existing files which are intended to be adds
        foreach ($files as $name => $values) {
            // normalize $values that are just an action into an array and add in content
            if (is_string($values)) {
                $files[$name] = array('action' => $values);
            }
            $files[$name] += array('content' => "$name " . $files[$name]['action']);
            $values        = $files[$name];

            if (File::exists('//depot/' . $name, $this->p4, true)) {
                if ($values['action'] == 'add') {
                    $file = new File($this->p4);
                    $file->setFilespec('//depot/' . $name)->delete()->submit('deleting');
                }
            } elseif ($values['action'] != 'add') {
                $file = new File($this->p4);
                $file->setFilespec('//depot/' . $name)
                     ->setLocalContents($values['content'] + ' prep')
                     ->add()
                     ->submit('adding');
            }
        }

        // now drag everyone into the party
        $change = new Change($this->p4);
        $change->setDescription('preparing files')->save();
        foreach ($files as $name => $values) {
            $file = new File($this->p4);
            $file->setFilespec('//depot/' . $name)->setLocalContents($values['content']);
            switch ($values['action']) {
                case 'add':
                    $file->add($change->getId());
                    break;
                case 'edit':
                    $file->edit($change->getId());
                    break;
                case 'delete':
                    $file->sync(true)->delete($change->getId());
                    break;
            }
        }

        // submit or shelve the work as appropriate
        if ($commit) {
            $change->submit();
        } else {
            $this->p4->run('shelve', array('-c', $change->getId()));
            $this->p4->run('revert', '//...');
        }

        return $change->getId();
    }

    /**
     * Helper function, creates change with 1 file.
     */
    protected function createChange()
    {
        $file = new File($this->p4);
        $file->setFilespec('//depot/main/foo/test.txt')
            ->open()
            ->setLocalContents('xyz123')
            ->submit('change description');

        return $file->getChange();
    }

    /**
     * Helper function, creates change with 2 files 1 of which is deleted.
     */
    protected function createChangeWithDelete()
    {
        $file = new File($this->p4);
        $file->setFilespec('//depot/main/foo/delete.txt')
             ->open()
             ->setLocalContents('xyz123')
             ->submit('change description');

        // new change to hold delete and add
        $change = new Change($this->p4);
        $file->delete();
        $file2 = new File($this->p4);
        $file2->setFilespec('//depot/main/foo/test.txt')
              ->open()
              ->setLocalContents('xyz123');

        $change->addFile($file)
               ->addFile($file2)
               ->submit('test');

        return $change;
    }

    /**
     * Make a couple of projects
     */
    protected function createProjects()
    {
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'            => 'project1',
                'name'          => 'pro-jo',
                'description'   => 'what what!',
                'members'       => array('jdoe'),
                'branches'      => array(
                    array(
                        'id'         => 'main',
                        'name'       => 'Main',
                        'paths'      => '//depot/main/...',
                        'moderators' => array('bob')
                    )
                )
            )
        );
        $project->save();

        $project = new Project($this->p4);
        $project->set(
            array(
                'id'            => 'project2',
                'name'          => 'pro-tastic',
                'description'   => 'ho! ... hey!',
                'members'       => array('lumineer'),
                'branches'      => array(
                    array(
                        'id'    => 'dev',
                        'name'  => 'Dev',
                        'paths' => '//depot/dev/...'
                    )
                )
            )
        );
        $project->save();
    }
}
