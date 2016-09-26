<?php
/**
 * Tests for the review module.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ReviewsTest;

use Activity\Model\Activity;
use Application\Log\Writer\Mock as MockLog;
use Groups\Model\Group;
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
                        'ReviewsTest' => BASE_PATH . '/module/Reviews/test/Reviews',
                    )
                )
            )
        );
    }

    public function testShelveTaskNewReview()
    {
        $services = $this->getApplication()->getServiceManager();
        $events   = $services->get('queue')->getEventManager();

        $shelf = new Change($this->p4);
        $shelf->setDescription('[review] test')->save();

        $event = new Event;
        $event->setName('task.shelve')
            ->setParam('id',    $shelf->getId())
            ->setParam('time',  123456789)
            ->setTarget($this);

        $events->trigger($event);

        $this->assertSame(
            "[review-2] test\n",
            Change::fetch($shelf->getId())->getDescription(),
            'expected matching updated description'
        );
    }

    public function testShelveWithSymbolicLinks()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();
        $p4       = $this->p4;
        $target   = '/usr/share/fonts';

        // this test uses specific path in the filesystem for the symlink,
        // skip if the path doesn't exist
        if (!file_exists($target)) {
            $this->markTestSkipped();
        }

        // shelve a single file that is a symlink to target for review
        $shelf = new Change($p4);
        $shelf->setDescription('[review] test')->save();
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->createLocalPath();
        symlink($target, $file->getLocalFilename());

        $file->add($shelf->getId());
        $p4->run('shelve', array('-c', $shelf->getId(), '//...'));

        // temporarily capture php warnings to examine them later
        $warnings = array();
        $original = set_error_handler(
            function ($errorNumber, $errorString) use (&$warnings) {
                $warnings[] = $errorString;
            },
            E_WARNING
        );

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // restore original error handler for warnings
        set_error_handler($original, E_WARNING);

        // check if we got any warnings due to not permitted chmod calls
        foreach ($warnings as $warning) {
            if (strpos($warning, 'chmod(): Operation not permitted') !== false) {
                $this->fail("Unexpected PHP warning due to calling chmod() while not permitted");
            }
        }
    }

    public function testShelveTaskUpdateReviewBadId()
    {
        $services = $this->getApplication()->getServiceManager();
        $events   = $services->get('queue')->getEventManager();

        $shelf = new Change($this->p4);
        $shelf->setDescription('[review-1234] test')->save();

        $event = new Event;
        $event->setName('task.shelve')
            ->setParam('id',    $shelf->getId())
            ->setParam('time',  123456789)
            ->setTarget($this);

        $events->trigger($event);

        $this->assertSame(
            "[review-1234] test\n",
            Change::fetch($shelf->getId())->getDescription(),
            'expected matching left alone description'
        );
    }

    public function testReviewEmailsWithPrivateProjects()
    {
        $services = $this->getApplication()->getServiceManager();
        $mailer   = $services->get('mailer');
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();

        // subscribe to all queue events so as to eavesdrop.
        $captured = array();
        $events->attach(
            '*',
            function ($event) use (&$captured) {
                $captured[] = $event;
            }
        );

        // create a public project
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'            => 'prj-public',
                'name'          => 'public project',
                'description'   => 'test',
                'members'       => array('a', 'b', 'c'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'name'  => 'Main',
                        'paths' => array('//depot/public/...')
                    )
                ),
            )
        );
        $project->save();

        // create a private project
        $project = new Project($this->p4);
        $project->set(
            array(
                'id'            => 'prj-private',
                'name'          => 'private project',
                'description'   => 'test',
                'members'       => array('d', 'e'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'name'  => 'Main',
                        'paths' => array('//depot/private/...')
                    )
                ),
                'private'       => true,
            )
        );
        $project->save();

        // create a group that will pull members into recipients for the notification email
        // we can then check if these users (as long as they are not project members) get filtered out
        $group = new Group($this->superP4);
        $group->setId('buddies')->setUsers(array('foo', 'a', 'd', 'x'));
        $group->getConfig()->set('emailFlags', array('reviews' => true, 'commits' => true));
        $group->save();

        // define connection for 'foo' and create users with valid email addresses to pass
        // the email validator in th email module
        $p4Foo = $this->connectWithAccess('foo', array('//...' => 'write'));
        $foo   = User::fetch('foo', $this->p4)->setEmail('foo@test.com')->save();
        foreach (array('a', 'b', 'c', 'd', 'e', 'x') as $id) {
            $user = new User($this->p4);
            $user->setId($id)->setFullName("Mr $id")->setEmail("$id@test.com")->save();
        }

        // case #1:
        // shelve a single file for review touching only public project
        $pool = $this->superP4->getService('clients');
        $pool->setConnection($p4Foo)->grab();
        $pool->reset();

        $shelf = new Change($p4Foo);
        $shelf->setDescription('#review test1')->save();
        $file = new File($p4Foo);
        $file->setFilespec('//depot/public/1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4Foo->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // ensure expected events were fired
        $this->assertSame(7, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('task.shelve',     $captured[2]->getName());
        $this->assertSame('worker.loop',     $captured[3]->getName());
        $this->assertSame('task.review',     $captured[4]->getName());
        $this->assertSame('worker.loop',     $captured[5]->getName());
        $this->assertSame('worker.shutdown', $captured[6]->getName());

        // extract recipients from the sent email
        $email      = file_get_contents($mailer->getLastFile());
        $email      = current(explode('Subject:', $email));
        $email      = end(explode('To:', $email));
        $recipients = array_map('trim', explode(',', $email));
        sort($recipients);

        $expected = array(
            'a@test.com',
            'b@test.com',
            'c@test.com',
            'd@test.com',
            'foo@test.com',
            'x@test.com'
        );
        $this->assertSame($expected, $recipients);

        // case #2:
        // try again with a change touching private project
        $pool->reset();
        $shelf = new Change($p4Foo);
        $shelf->setDescription('#review test2')->save();
        $file = new File($p4Foo);
        $file->setFilespec('//depot/private/1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4Foo->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $captured = array();
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // ensure expected events were fired
        $this->assertSame(7, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('task.shelve',     $captured[2]->getName());
        $this->assertSame('worker.loop',     $captured[3]->getName());
        $this->assertSame('task.review',     $captured[4]->getName());
        $this->assertSame('worker.loop',     $captured[5]->getName());
        $this->assertSame('worker.shutdown', $captured[6]->getName());

        // extract recipients from the sent email
        $email      = file_get_contents($mailer->getLastFile());
        $email      = current(explode('Subject:', $email));
        $email      = end(explode('To:', $email));
        $recipients = array_map('trim', explode(',', $email));
        sort($recipients);

        $expected = array(
            'd@test.com',
            'e@test.com'
        );
        $this->assertSame($expected, $recipients);
    }

    public function testShelveReviewSideEffects()
    {
        // shelving files for review should:
        //  - update shelf description with review id
        //  - make review record and managed shelved change
        //  - queue a review task
        //  - update review with affected projects
        //  - update review with associated changes
        //  - update review with participants
        //  - update shelved change with affected files
        //  - make a archive/version shelf of affected files
        //  - trigger automated tests
        //  - create activity record w. expected user/projects/followers
        //    (should include project members)
        //  - configure mail message w. expected recipients

        $services = $this->getApplication()->getServiceManager();
        $logger   = $services->get('logger');
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();
        $p4       = $this->p4;

        // subscribe to all queue events so as to eavesdrop.
        $captured = array();
        $events->attach(
            '*',
            function ($event) use (&$captured) {
                $captured[] = $event;
            }
        );

        // make a project so it can be affected by this change
        $project = new Project($p4);
        $project->set(
            array(
                'id'            => 'project1',
                'name'          => 'you get a name in death',
                'description'   => 'test1!',
                'members'       => array('bob'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'name'  => 'Main Name',
                        'paths' => array('//depot/...')
                    )
                ),
                'tests'         => array(
                    'enabled'   => true,
                    'url'       => 'test://foo?{change}&{status}&{review}&{project}&{projectName}'
                                .  '&{branch}&{branchName}&{pass}&{fail}&{deploySuccess}&{deployFail}'
                ),
                'deploy'        => array(
                    'enabled'   => true,
                    'url'       => 'test://bar?{change}&{status}&{review}&{project}&{projectName}'
                                .  '&{branch}&{branchName}&{success}&{fail}'
                )
            )
        );
        $project->save();

        // make another project that can be affected by this change, but make it deleted to ensure that
        // this project won't influence activity/notifications
        $project = new Project($p4);
        $project->set(
            array(
                'id'            => 'project2',
                'name'          => 'you get a name in life',
                'description'   => 'test2!',
                'members'       => array('pink', 'purple'),
                'branches'      => array(
                    array(
                        'id'    => 'main',
                        'name'  => 'Main Branch',
                        'paths' => array('//depot/...')
                    )
                ),
                'deleted'       => true
            )
        );
        $project->save();

        // eavesdrop on logger to catch automated test trigger exception
        $mock = new MockLog;
        $logger->addWriter($mock);

        // shelve a single file for review.
        $shelf = new Change($p4);
        $shelf->setDescription('[review] test')->save();
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // ensure expected events were fired
        $this->assertSame(7, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('task.shelve',     $captured[2]->getName());
        $this->assertSame('worker.loop',     $captured[3]->getName());
        $this->assertSame('task.review',     $captured[4]->getName());
        $this->assertSame('worker.loop',     $captured[5]->getName());
        $this->assertSame('worker.shutdown', $captured[6]->getName());

        // verify shelved change updated
        $shelf = Change::fetch($shelf->getId(), $p4);
        $this->assertSame("[review-2] test\n", $shelf->getDescription());

        // verify review record created
        $review = Review::fetch(2, $p4);
        $this->assertSame(str_replace('[review-2] ', '', $shelf->getDescription()), $review->get('description'));

        // ensure managed shelved change has expected file
        $result = $p4->run('describe', array('-S', $review->getId()))->getData();
        $this->assertSame('//depot/foo', $result['0']['depotFile0']);

        // ensure archive shelved change has expected file
        $result = $p4->run('describe', array('-S', $review->getId() + 1))->getData();
        $this->assertSame('//depot/foo', $result['0']['depotFile0']);

        // ensure review 'affects' project
        $this->assertSame(array('project1' => array('main')), $review->getProjects());

        // ensure review has associated changes
        $this->assertSame(array(1, 3), $review->get('changes'));
        $this->assertSame(null, $review->get('committed'));

        // ensure review has expected participants
        $this->assertSame(array('admin'), $review->getParticipants());

        // ensure automated tests are invoked.
        $this->assertTrue(count($mock->events) >= 4);
        preg_match('/Invalid URI passed as string \(([^)]+)/', $mock->events[3]['message'], $matches);
        $this->assertTrue((bool) $matches);
        $this->assertSame(
            'test://foo?3&shelved&2&project1&you%20get%20a%20name%20in%20death&main&Main%20Name&' .
            'http%3A%2F%2Flocalhost%2Freviews%2F2%2Ftests%2Fpass%2F' . $review->getToken() . '%2F&' .
            'http%3A%2F%2Flocalhost%2Freviews%2F2%2Ftests%2Ffail%2F' . $review->getToken() . '%2F&' .
            'http%3A%2F%2Flocalhost%2Freviews%2F2%2Fdeploy%2Fsuccess%2F' . $review->getToken() . '%2F&' .
            'http%3A%2F%2Flocalhost%2Freviews%2F2%2Fdeploy%2Ffail%2F'    . $review->getToken() . '%2F',
            $matches[1]
        );

        // ensure deploy is invoked.
        $this->assertTrue(count($mock->events) >= 5);
        preg_match('/Invalid URI passed as string \(([^)]+)/', $mock->events[4]['message'], $matches);
        $this->assertTrue((bool) $matches);
        $this->assertSame(
            'test://bar?3&shelved&2&project1&you%20get%20a%20name%20in%20death&main&Main%20Name&' .
            'http%3A%2F%2Flocalhost%2Freviews%2F2%2Fdeploy%2Fsuccess%2F' . $review->getToken() . '%2F&' .
            'http%3A%2F%2Flocalhost%2Freviews%2F2%2Fdeploy%2Ffail%2F'    . $review->getToken() . '%2F',
            $matches[1]
        );

        // ensure activity appears on expected streams
        $activity = Activity::fetch(1, $p4);
        $this->assertSame(
            array('review-2', 'user-admin', 'personal-admin',  'project-project1', 'personal-bob'),
            $activity->getStreams()
        );

        // ensure mail is configured to send to expected recipients
        $mail = $captured[4]->getParam('mail');
        $this->assertSame("Review @2 - test\n", $mail['subject']);
        $this->assertSame(array('admin', 'bob'), $mail['toUsers']);
    }

    /**
     * Test commits outside of swarm on behalf of another user by shelving a change as one user, and then unshelving
     * and committing that change as a different user, ensuring that the resulting activity attributes the change to
     * the first user.
     */
    public function testCommitAsAuthorNoSwarm()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();

        // subscribe to all queue events so as to eavesdrop.
        $captured = array();
        $events->attach(
            '*',
            function ($event) use (&$captured) {
                $captured[] = $event;
            }
        );

        $pool = $this->superP4->getService('clients');
        $pool->grab();
        $pool->reset();

        // open a 'test' file for add and get it into a shelved change
        $shelf = new Change($this->superP4);
        $shelf->setDescription('#review Testing commit as author, without swarm.')
              ->save();

        $file = new File($this->superP4);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $this->superP4->run('shelve', array('-c', $shelf->getId()));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // unshelve the change as a different user, into a new change
        $pool = $this->userP4->getService('clients');
        $pool->grab();
        $pool->reset();
        $commit = new Change($this->userP4);
        $commit->setDescription('We are committing from here. #review-2')
               ->save();
        $this->userP4->run('unshelve', array('-s', $shelf->getId(), '-c', $commit->getId(), '-f'));
        $commit->addFile($file);
        $commit->submit('We are committing from here. #review-2');

        $queue->addTask('commit', 4);
        $queue->addTask('review', 2, array('updateFromChange' => 4));
        $this->processQueue();

        $commit = Change::fetch(4, $this->p4);
        // ensure that we have a commit
        $this->assertSame('submitted', $commit->getStatus());

        // ensure that the generated commit activity includes an 'behalfOf' field, which gets passed to
        // the view and the commit emails
        $this->dispatch('/activity/streams/review-2');

        $activities = json_decode($this->getResponse()->getBody(), true);
        $commit     = $activities['activity'][0];

        $this->assertSame('tester', $commit['behalfOf']);
        $this->assertTrue($commit['behalfOfExists']);
        $this->assertSame('committed', $commit['action']);
    }

    /**
     * Test commits within swarm on behalf of another user by shelving a change as one user, committing said change as a
     * different user, and ensuring that the resulting activity attributes the change to the first user.
     */
    public function testCommitAsAuthor()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();
        $p4       = $this->p4;

        // subscribe to all queue events so as to eavesdrop.
        $captured = array();
        $events->attach(
            '*',
            function ($event) use (&$captured) {
                $captured[] = $event;
            }
        );

        $pool = $this->superP4->getService('clients');
        $pool->grab();
        $pool->reset();

        // open a 'test' file for add and get it into a shelved change
        $shelf = new Change($this->superP4);
        $shelf->setDescription('#review Testing commit as author.')
              ->save();

        $file = new File($this->superP4);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $this->superP4->run('shelve', array('-c', $shelf->getId()));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // approve and commit the review as a different user
        $postData = new Parameters(
            array(
                'state'       => 'approved:commit',
                'description' => 'test commit'
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);
        $this->dispatch('/reviews/2/transition');

        $this->assertRoute('review-transition');
        $this->assertResponseStatusCode(200);

        $captured = array();
        $queue->addTask('commit', 4);
        $this->processQueue();

        // get changes for this review and ensure that the commit change is owned by the user who
        // originally started the review
        $review = Review::fetch(2, $p4);
        $commit = Change::fetch(4, $p4);

        // ensure that we have a commit, and that the owner of both is the same
        $this->assertTrue($commit->getStatus() == 'submitted');
        $this->assertTrue($commit->getUser()   == $review->get('author'));

        // ensure that the generated commit activity includes an 'behalfOf' field, which gets passed to
        // the view and the commit emails
        $this->dispatch('/activity/streams/review-2');
        $activities = get_object_vars(json_decode($this->getResponse()->getBody()));
        $commit     = $activities['activity'][0];
        $this->assertTrue(strlen($commit->behalfOf) > 0);
        $this->assertTrue($commit->behalfOfExists);

        // ensure mail is configured to come from the committer, not the review author
        $mail = $captured[2]->getParam('mail');
        $this->assertSame($commit->user, $mail['fromUser']);
    }

    public function testGitReview()
    {
        // test the full git-review workflow:
        // - shelving with the appropriate git-info will start a review with the same id
        // - shelving an update updates the review
        // - committing via swarm works
        // - shelving again re-opens the review

        $services = $this->getApplication()->getServiceManager();
        $logger   = $services->get('logger');
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();
        $p4       = $this->p4;

        // subscribe to all queue events so as to eavesdrop.
        $captured = array();
        $events->attach(
            '*',
            function ($event) use (&$captured) {
                $captured[] = $event;
            }
        );

        // make a project so it can be affected by this change
        $project = new Project($p4);
        $project->set(
            array(
                 'id'            => 'project1',
                 'name'          => 'you get a name in death',
                 'description'   => 'test1!',
                 'members'       => array('bob', 'nonadmin'),
                 'branches'      => array(
                     array(
                         'id'    => 'main',
                         'name'  => 'Main',
                         'paths' => array('//depot/...')
                     )
                 ),
                 'tests'         => array(
                     'enabled'   => true,
                     'url'       => 'test://foo?{change}&{status}&{review}&{project}&{branch}&{pass}&{fail}'
                         .  '&{deploySuccess}&{deployFail}'
                 ),
                 'deploy'        => array(
                     'enabled'   => true,
                     'url'       => 'test://bar?{change}&{status}&{review}&{project}&{branch}&{success}&{fail}'
                 )
            )
        );
        $project->save();

        // eavesdrop on logger to catch automated test trigger exception
        $mock = new MockLog;
        $logger->addWriter($mock);

        // ---- Phase one start a git review ----
        $shelf = new Change($p4);
        $shelf->setDescription(
            "Test git review!\n"
            . "With a two line description\n"
            . "\n"
            . "Imported from Git\n"
            . " Author: Bob Bobertson <bbobertson@perforce.com> 1381432565 -0700\n"
            . " Committer: Git Fusion Machinery <nobody@example.com> 1381432572 +0000\n"
            . " sha1: 6a96f259deb6d8567a4d85dce09ae2e707ca7286\n"
            . " push-state: complete\n"
            . " review-status: create\n"
            . " review-id: 1\n"
            . " review-repo: Talkhouse\n"
        )->save();
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4->run('shelve', array('-c', 1, '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // ensure expected events were fired
        $this->assertSame(7, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('task.shelve',     $captured[2]->getName());
        $this->assertSame('worker.loop',     $captured[3]->getName());
        $this->assertSame('task.review',     $captured[4]->getName());
        $this->assertSame('worker.loop',     $captured[5]->getName());
        $this->assertSame('worker.shutdown', $captured[6]->getName());

        // verify review record created
        $review = Review::fetch(1, $p4);
        $this->assertSame('Reviews\Model\GitReview', get_class($review));
        $this->assertSame("Test git review!\nWith a two line description", $review->get('description'));
        $versions = $review->getVersions();
        unset($versions[0]['time']);
        $this->assertSame(
            array(
                array(
                    'change' => 1,
                    'user' => 'admin',
                    'pending' => true
                ),
            ),
            $versions
        );

        // ensure no other changes were created
        $this->assertSame(array(1), Change::fetchAll(array(), $p4)->invoke('getId'));

        // ensure review 'affects' project
        $this->assertSame(array('project1' => array('main')), $review->getProjects());

        // ensure review has associated changes
        $this->assertSame(array(1), $review->get('changes'));
        $this->assertSame(null, $review->get('committed'));

        // ensure review has expected participants
        $this->assertSame(array('admin'), $review->getParticipants());

        // ensure automated tests are invoked.
        $this->assertTrue(count($mock->events) >= 4);
        preg_match('/Invalid URI passed as string \(([^)]+)/', $mock->events[3]['message'], $matches);
        $this->assertTrue((bool) $matches);

        // ensure deploy is invoked.
        $this->assertTrue(count($mock->events) >= 5);
        preg_match('/Invalid URI passed as string \(([^)]+)/', $mock->events[4]['message'], $matches);
        $this->assertTrue((bool) $matches);

        // ensure activity appears on expected streams
        $activity = Activity::fetch(1, $p4);
        $this->assertSame(
            array('review-1', 'user-admin', 'personal-admin',  'project-project1', 'personal-bob', 'personal-nonadmin'),
            $activity->getStreams()
        );

        // ensure mail is configured to send to expected recipients
        $mail = $captured[4]->getParam('mail');
        $this->assertSame("Review @1 - Test git review!\nWith a two line description", $mail['subject']);
        $this->assertSame(array('admin', 'bob', 'nonadmin'), $mail['toUsers']);


        // ---- Phase two update git review ----
        $mock->events = array();
        $captured     = array();
        $shelf        = Change::fetch(1, $p4);
        $shelf->setDescription(
            "Modified git review!\n"
            . "With a two line description\n"
            . "\n"
            . "Imported from Git\n"
            . " Author: Tony Bobertson <tbobertson@perforce.com> 1381432565 -0700\n"
            . " Committer: Git Fusion Machinery <nobody@example.com> 1381432572 +0000\n"
            . " sha1: 6a96f259deb6d8567a4d85dce09ae2e707ca7286\n"
            . " push-state: complete\n"
            . " review-status: update\n"
            . " review-id: 1\n"
            . " review-repo: Talkhouse\n"
        )->save();

        // fake out created to be in past so it will accurately appear as an update.
        // things that occur in the same second don't otherwise look like an update.
        $review = Review::fetch(1, $p4);
        $review->set('created', $review->get('updated') - 1);
        $review->save();

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // ensure expected events were fired
        $this->assertSame(7, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('task.shelve',     $captured[2]->getName());
        $this->assertSame('worker.loop',     $captured[3]->getName());
        $this->assertSame('task.review',     $captured[4]->getName());
        $this->assertSame('worker.loop',     $captured[5]->getName());
        $this->assertSame('worker.shutdown', $captured[6]->getName());

        // verify review record updated as appropriate
        $review = Review::fetch(1, $p4);
        $this->assertSame('Reviews\Model\GitReview', get_class($review));
        $this->assertSame("Test git review!\nWith a two line description", $review->get('description'));
        $versions = $review->getVersions();
        unset($versions[0]['time']);
        $this->assertSame(
            array(
                 array(
                     'change' => 1,
                     'user' => 'admin',
                     'pending' => true
                 ),
            ),
            $versions
        );

        // ensure no other changes were created
        $this->assertSame(array(1), Change::fetchAll(array(), $p4)->invoke('getId'));

        // ensure automated tests are invoked.
        $this->assertTrue(count($mock->events) >= 4);
        preg_match('/Invalid URI passed as string \(([^)]+)/', $mock->events[3]['message'], $matches);
        $this->assertTrue((bool) $matches);

        // ensure deploy is invoked.
        $this->assertTrue(count($mock->events) >= 5);
        preg_match('/Invalid URI passed as string \(([^)]+)/', $mock->events[4]['message'], $matches);
        $this->assertTrue((bool) $matches);

        // ensure activity appears on expected streams
        $activity = Activity::fetch(2, $p4);
        $this->assertSame(
            array('review-1', 'user-admin', 'personal-admin',  'project-project1', 'personal-bob', 'personal-nonadmin'),
            $activity->getStreams()
        );

        // verify action is correct
        $this->assertSame(
            "updated files in",
            $activity->get('action')
        );

        // verify body doesn't include keywords
        $this->assertSame(
            "Modified git review!\nWith a two line description",
            $activity->get('description')
        );

        // ensure mail is configured to send to expected recipients
        $mail = $captured[4]->getParam('mail');
        $this->assertSame("Review @1 - Test git review!\nWith a two line description", $mail['subject']);
        $this->assertSame(array('admin'), $mail['toUsers']);


        // ---- Phase three commit via swarm ----
        $mock->events = array();
        $captured     = array();

        $postData = new Parameters(
            array(
                 'state'       => 'approved:commit',
                 'description' => 'test commit'
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($postData);
        $this->dispatch('/reviews/1/transition');

        $this->assertRoute('review-transition');
        $this->assertResponseStatusCode(200);

        $queue->addTask('commit', 2);
        $this->processQueue();

        // ensure expected events were fired
        // the edit adds the first task.review, the commit processing adds the second task.review
        $captureNames = array();
        foreach ($captured as $event) {
            $captureNames[] = $event->getName();
        }
        $this->assertSame(
            array(
                'worker.startup', 'worker.loop', 'task.review', 'worker.loop', 'task.commit', 'worker.loop',
                'task.review', 'worker.loop', 'worker.shutdown'
            ),
            $captureNames
        );

        // verify review record updated as appropriate
        $review = Review::fetch(1, $p4);
        $this->assertFalse($review->get('pending'));
        $this->assertSame(array(1, 2), $review->get('changes'));
        $this->assertSame(array(2), $review->getCommits());


        // ---- Phase four re-open review ----
        $mock->events = array();
        $captured     = array();

        $queue->addTask('shelve', 1);
        $this->processQueue();

        // ensure expected events were fired
        $this->assertSame(7, count($captured));
        $this->assertSame('worker.startup',  $captured[0]->getName());
        $this->assertSame('worker.loop',     $captured[1]->getName());
        $this->assertSame('task.shelve',     $captured[2]->getName());
        $this->assertSame('worker.loop',     $captured[3]->getName());
        $this->assertSame('task.review',     $captured[4]->getName());
        $this->assertSame('worker.loop',     $captured[5]->getName());
        $this->assertSame('worker.shutdown', $captured[6]->getName());

        // verify review record updated as appropriate
        $review = Review::fetch(1, $p4);
        $this->assertTrue($review->get('pending'));
    }

    public function testGitReviewNoP4Reshelf()
    {
        // ensure p4 users cannot update a git review with new pending work

        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $events   = $queue->getEventManager();
        $p4       = $this->p4;

        // create the review as per git's usage
        $shelf = new Change($p4);
        $shelf->setDescription(
            "Test git review!\n"
            . "With a two line description\n"
            . "\n"
            . "Imported from Git\n"
            . " Author: Bob Bobertson <bbobertson@perforce.com> 1381432565 -0700\n"
            . " Committer: Git Fusion Machinery <nobody@example.com> 1381432572 +0000\n"
            . " sha1: 6a96f259deb6d8567a4d85dce09ae2e707ca7286\n"
            . " push-state: complete\n"
            . " review-status: create\n"
            . " review-id: 1\n"
            . " review-repo: Talkhouse\n"
        )->save();
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('some file contents');
        $file->add(1);
        $p4->run('shelve', array('-c', 1, '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // verify review record created
        $review = Review::fetch(1, $p4);
        $this->assertSame('Reviews\Model\GitReview', get_class($review));


        // attempt to update the review as a perforce user and verify failure
        $update = new Change($p4);
        $update->setDescription("Test p4 update #review-1")->save();
        $file = new File($p4);
        $file->setFilespec('//depot/foo2');
        $file->setLocalContents('some file contents #review-1');
        $file->add(2);
        $p4->run('shelve', array('-c', 2, '//...'));

        // push into queue and process
        $queue->addTask('shelve', $update->getId());
        $this->processQueue();


        // verify the review wasn't actually updated
        $result = $p4->run('files', array('//...@=1,@=1'));
        $files  = array_map('current', $result->getData());
        $this->assertSame(array('//depot/foo'), $files);

        $this->assertSame(array(2, 1), Change::fetchAll(array(), $p4)->invoke('getId'));
    }

    public function testReviewActivityWithRestrictedChanges()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;

        // create and connect user 'foo' with limited access to depot
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create a review from shelved change
        $shelf = new Change($p4);
        $shelf->setDescription('#review')->save();

        $file = new File($p4);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // ensure, that activity record has 'change' field set
        $activity = Activity::fetchAll(array('type' => 'review'), $p4)->first();
        $review   = Review::fetchAll(array('change' => $shelf->getId()), $p4)->first();
        $this->assertTrue($review instanceof Review, 'expected a review');
        $this->assertTrue($activity instanceof Activity, 'expected an activity record');
        $this->assertSame($review->getHeadChange(), $activity->get('change'));

        // verify acess: both standard and 'foo' users sould have access (change is not restricted)
        $this->verifyActivityAccess(array($activity->getId() => true));
        $this->verifyActivityAccess(array($activity->getId() => true), $p4Foo);

        // add a committed change to a review and verify that 'change' field gets updated
        $file = new File;
        $file->setFilespec('//depot/test2')->open()->setLocalContents('abc');
        $change = new Change($p4);
        $change->setType('restricted')->addFile($file)->submit('test');

        $queue->addTask('review', $review->getId(), array('updateFromChange' => $change->getId()));
        $this->processQueue();

        // ensure, that 'change' field for old activity has the new value
        $this->assertSame(
            $change->getId(),
            Activity::fetch($activity->getId(), $p4)->get('change')
        );

        // verify access: standard user should have access, but 'foo' should not
        $this->verifyActivityAccess(array($activity->getId() => true));
        $this->verifyActivityAccess(array($activity->getId() => false), $p4Foo);
    }

    /**
     * provider for testing various edit reviewer scenarios.
     * note the provider assumes the review author will be:  admin
     * the provider assumes the user doing the edit will be: nonadmin
     * the test itself is expected to ensure author is in the list so we can skip it here.
     *
     * if unknown users are specified, they will be created (as we cannot add missing users)
     *
     * @return array
     */
    public function editReviewersProvider()
    {
        // pull out actions so we can updated tests easier
        $joined       = 'joined';
        $left         = 'left';
        $madeRequired = 'made their vote required on';
        $madeOptional = 'made their vote optional on';
        $edited       = 'edited reviewers on';

        return array(
            array(array(),                          array('nonadmin'),                      $joined),
            array(array('a', 'b'),                  array('a', 'b', 'nonadmin'),            $joined),
            array(array('nonadmin'),                array(),                                $left),
            array(array('a', 'b', 'nonadmin'),      array('a', 'b'),                        $left),
            array(array('nonadmin'),                array('nonadmin' => 'required'),        $madeRequired),
            array(array('a'),                       array('a', 'nonadmin' => 'required'),   $madeRequired),
            array(array('nonadmin' => 'required'),  array('nonadmin'),                      $madeOptional),
            array(
                array('nonadmin' => 'required'),
                array('nonadmin', 'b'),
                $edited,
                'Added <a href="/users/b/">b</a> as an optional reviewer.'
                . ' Made <a href="/users/nonadmin/">nonadmin</a> an optional reviewer.'
            ),
            array(
                array('b' => 'required'),
                array('b'),
                $edited,
                'Made <a href="/users/b/">b</a> an optional reviewer.'
            ),
            array(
                array('b'),
                array('b' => 'required'),
                $edited,
                'Made <a href="/users/b/">b</a> a required reviewer.'
            ),
            array(
                array('a', 'b'),
                array('a', 'b', 'c'),
                $edited,
                'Added <a href="/users/c/">c</a> as an optional reviewer.'
            ),
            array(
                array(),
                array('a'),
                $edited,
                'Added <a href="/users/a/">a</a> as an optional reviewer.'
            ),
            array(
                array('a'),
                array(),
                $edited,
                'Removed <a href="/users/a/">a</a> from the review.'
            ),
        );
    }

    /**
     * @dataProvider editReviewersProvider
     *
     * primes a review with the 'starting' participants data, does a dispatch to edit
     * to update reviewers/required-reviewers to the 'edit' data and confirms action
     * in resulting activity is correct.
     */
    public function testEditReviewersActivity($starting, $edit, $action, $describedChanges = false)
    {
        // we'll ensure author is present, shape it like a standard participants data array
        // sort it and add shorthand for specifying required in norm.
        $norm = function ($users) {
            $normalized = array();
            foreach ((array) $users as $key => $value) {
                if (is_int($key)) {
                    $key   = $value;
                    $value = array();
                }
                $value = $value == 'required' ? array('required' => true) : $value;
                uksort($value, 'strnatcasecmp');
                $normalized[$key] = $value;
            }
            $normalized += array('admin' => array());
            uksort($normalized, 'strnatcasecmp');
            return $normalized;
        };

        $starting = $norm($starting);
        $edit     = $norm($edit);

        // ensure all specified users actually exist
        foreach (array_unique(array_merge(array_keys($starting), array_keys($edit))) as $userId) {
            if (!User::exists($userId)) {
                $user = new User;
                $user->setId($userId)->setFullName($userId)->setEmail($userId . '@example.com')->save();
            }
        }

        // process queue early so we don't end up dragging in a commit event via our
        // 'import old stuff if this is our first run' logic
        $this->processQueue();

        // make a review; hard to test without one :)
        $file = new File($this->p4);
        $file->setFilespec('//depot/main/foo/test.txt')
             ->open()
             ->setLocalContents('xyz123')
             ->submit('change description');
        $review = Review::createFromChange('1')->save()->updateFromChange('1')->save();
        $review->setParticipantsData($starting)->save();

        // verify our starting expectations are ok
        $this->assertSame(
            $starting,
            Review::fetch('2', $this->p4)->getParticipantsData(),
            'expected starting data to match'
        );

        // edit the review into the requested shape
        $getRequired = function ($values) {
            $required = array();
            foreach ((array) $values as $user => $data) {
                if (isset($data['required']) && $data['required']) {
                    $required[] = $user;
                }
            }
            return $required;
        };
        $postData = new Parameters(
            array(
                'reviewers'         => array_keys($edit),
                'requiredReviewers' => $getRequired($edit)
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
            $edit,
            $review['participantsData'],
            'expected response data to match'
        );
        $this->assertSame(
            $edit,
            Review::fetch('2', $this->p4)->getParticipantsData(),
            'expected ending fetched data to match'
        );

        // process the queue and confirm number/action of activity events are correct
        $this->processQueue();
        $activity = Activity::fetchAll(array(), $this->p4);
        $this->assertSame(
            array($action),
            $activity->invoke('get', array('action')),
            'expected a single matching activity entry with the correct action'
        );

        if ($describedChanges !== false) {
            $helper = $this->getApplication()->getServiceManager()->get('ViewHelperManager')->get('ReviewersChanges');
            $helper->setPlainText(false);
            $this->assertSame(
                $describedChanges,
                (string) $helper($activity->first()->getDetails('reviewers')),
                'Expected matching details'
            );
        }
    }

    /**
     * @dataProvider revertReviewStatusProvider
     */
    public function testRevertReviewStatus($version1, $version2, $stateVersion1, $stateVersion2)
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;

        // create a review with version 1
        $change = new Change($p4);
        $change->setDescription('#review')->save();
        foreach ($version1['files'] as $filespec => $content) {
            $file = new File($p4);
            $file->setFilespec($filespec);
            $file->setLocalContents($content);
            $file->add($change->getId());
        }
        $version1['isPending']
            ? $p4->run('shelve', array('-c', $change->getId(), '//...'))
            : $p4->run('submit', array('-c', $change->getId()));

        // push into queue and process
        $queue->addTask($version1['isPending'] ? 'shelve' : 'commit', $change->getId());
        $this->processQueue();

        // get the created review and set state
        $review = Review::fetchAll(array(), $p4)->first();
        $review->setState($stateVersion1)->save();

        // add version 2
        $change = new Change($p4);
        $change->setDescription('#review-' . $review->getId())->save();
        foreach ($version2['files'] as $filespec => $content) {
            $file = new File($p4);
            $file->setFilespec($filespec);
            $file->setLocalContents($content);
            File::exists($filespec, $this->p4)
                ? $file->open($change->getId())
                : $file->add($change->getId());
        }
        $version2['isPending']
            ? $p4->run('shelve', array('-c', $change->getId(), '//...'))
            : $p4->run('submit', array('-c', $change->getId()));

        // push into queue and process
        $queue->addTask($version2['isPending'] ? 'shelve' : 'commit', $change->getId());
        $this->processQueue();

        // verify review status
        $this->assertSame($stateVersion2, Review::fetch($review->getId(), $p4)->getState());
    }

    public function revertReviewStatusProvider()
    {
        $v1shelf  = array(
            'isPending' => true,
            'files'     => array(
                '//depot/test' => 'content 1'
            )
        );
        $v2shelf  = array(
            'isPending' => true,
            'files'     => array(
                '//depot/test' => 'content 2'
            )
        );
        $v1commit = array('isPending' => false) + $v1shelf;
        $v2commit = array('isPending' => false) + $v2shelf;
        return array(
            // shelf-shelf
            'ss-nre' => array($v1shelf, $v2shelf,   'needsReview',   'needsReview'),
            'ss-nrs' => array($v1shelf, $v2shelf,   'needsRevision', 'needsRevision'),
            'ss-app' => array($v1shelf, $v2shelf,   'approved',      'needsReview'),
            'ss-rej' => array($v1shelf, $v2shelf,   'rejected',      'rejected'),
            'ss-arc' => array($v1shelf, $v2shelf,   'archived',      'archived'),
            // shelf-commit
            'sc-nre' => array($v1shelf, $v2commit,  'needsReview',   'needsReview'),
            'sc-nrs' => array($v1shelf, $v2commit,  'needsRevision', 'needsRevision'),
            'sc-app' => array($v1shelf, $v2commit,  'approved',      'needsReview'),
            'sc-rej' => array($v1shelf, $v2commit,  'rejected',      'rejected'),
            'sc-arc' => array($v1shelf, $v2commit,  'archived',      'archived'),
            // commit-shelf
            'cs-nre' => array($v1commit, $v2shelf,  'needsReview',   'needsReview'),
            'cs-nrs' => array($v1commit, $v2shelf,  'needsRevision', 'needsRevision'),
            'cs-app' => array($v1commit, $v2shelf,  'approved',      'needsReview'),
            'cs-rej' => array($v1commit, $v2shelf,  'rejected',      'rejected'),
            'cs-arc' => array($v1commit, $v2shelf,  'archived',      'archived'),
            // commit-commit
            'cc-nre' => array($v1commit, $v2commit, 'needsReview',   'needsReview'),
            'cc-nrs' => array($v1commit, $v2commit, 'needsRevision', 'needsRevision'),
            'cc-app' => array($v1commit, $v2commit, 'approved',      'needsReview'),
            'cc-rej' => array($v1commit, $v2commit, 'rejected',      'rejected'),
            'cc-arc' => array($v1commit, $v2commit, 'archived',      'archived'),
            // no versions diff
            'ss-nd'  => array($v1shelf,  $v1shelf,  'approved',      'approved'),
            'sc-nd'  => array($v1shelf,  $v1commit, 'approved',      'approved'),
            'cs-nd'  => array($v1commit, $v1shelf,  'approved',      'needsReview'),
            'cc-nd'  => array($v1commit, $v1commit, 'approved',      'needsReview'),
        );
    }

    public function testSendingReviewEmail()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;

        // prepare connection for the user with valid email to pass the email validator
        $p4Foo = $this->connectWithAccess('foo', array( '//depot/...' => 'write'));
        User::fetch('foo', $p4)->setEmail('foo@test.com')->save();

        $pool = $this->superP4->getService('clients');
        $pool->setConnection($p4Foo)->grab();
        $pool->reset();

        // create a review as user 'foo'
        $shelf = new Change($p4Foo);
        $shelf->setDescription('#review')->save();

        $file = new File($p4Foo);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4Foo->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // verify that review email has been sent (we expect only one email)
        $mailer    = $this->getApplication()->getServiceManager()->get('mailer');
        $emailFile = $mailer->getLastFile();
        $this->assertNotNull($emailFile, "Expected review email was sent.");
    }

    public function testShelveExclusiveOpenFile()
    {
        $services = $this->getApplication()->getServiceManager();
        $logger   = $services->get('logger');
        $queue    = $services->get('queue');
        $p4       = $this->p4;

        // eavesdrop on logger to catch automated test trigger exception
        $mock = new MockLog;
        $logger->addWriter($mock);

        // shelving an exclusive open file should fail unless
        // the filetype.bypasslock configurable is enabled
        $shelf = new Change($p4);
        $shelf->setDescription('test #review')->save();
        $file = new File($p4);
        $file->setFilespec('//depot/foo');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId(), '+l');
        $p4->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // review update should have failed (no files)
        $change = Change::fetch(2, $p4);
        $this->assertSame(0, count($change->getFileData(true)));

        // ensure exception was logged
        $this->assertTrue(count($mock->events) >= 3);
        $this->assertTrue(
            strpos($mock->events[3]['message'], 'One or more files are exclusively open') !== false
        );

        // bypassing exclusive locks requires 2014.2+ and a build > 1073410
        $identity = $p4->getServerIdentity();
        if (!$p4->isServerMinVersion('2014.2') || $identity['build'] < 1073410) {
            return;
        }

        // now reconfigure server to enable filetype.bypasslock
        $this->superP4->run('configure', array('set', 'filetype.bypasslock=1'));

        // need to disconnect/reconnect for the configurable to take effect
        $this->p4->disconnect()->connect();

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // should work this time
        $change = Change::fetch(2, $p4);
        $this->assertSame(1, count($change->getFileData(true)));
    }

    public function testGroupsAssociationOnCreate()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;
        $p4Bar    = $this->connectWithAccess('bar', array('//depot/...' => 'write'));

        // create few groups to test with
        $group = new Group($this->superP4);
        $group->setId('g1')
              ->setUsers(array('foo', 'bar'))
              ->save();
        $group = new Group($this->superP4);
        $group->setId('g2')
              ->setUsers(array('a', 'b'))
              ->save();
        $group->setId('g3')
              ->setUsers(array('foo'))
              ->setSubgroups(array('g1'))
              ->save();

        // create a review as user 'bar' and verify that it has been associated with groups g1 and g3
        $pool = $this->superP4->getService('clients');
        $pool->setConnection($p4Bar)->grab();
        $pool->reset();
        $shelf = new Change($p4Bar);
        $shelf->setDescription('#review')->save();

        $file = new File($p4Bar);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4Bar->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        $review = Review::fetchAll(array(), $p4)->first();
        $this->assertSame(array('g1', 'g3'), $review->getGroups());
    }

    /**
     * Verify that when a group member creates a review, all other members from that group are notified via an email,
     * but only if the group is configured for it.
     */
    public function testEmailGroupMembersOnReviewCreate()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;
        $p4Foo    = $this->connectWithAccess('foo', array('//depot/...' => 'write'));

        // we create 3 groups:
        // - group g1 containing user 'foo' and configured for emailing about reviews
        // - group g2 also containing user 'foo' but not configured for emailing about reviews
        // - group g3 not containing user 'foo' but configured for emailing about reviews
        // when foo creates a review, only users from group g1 should get notification
        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('reviews' => 1));
        $group->setId('g1')
              ->setUsers(array('foo', 'bar', 'baz'))
              ->save();

        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('reviews' => 0));
        $group->setId('g2')
              ->setUsers(array('foo', 'bar', 'joe'))
              ->save();

        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('reviews' => 1));
        $group->setId('g3')
              ->setUsers(array('a', 'b'))
              ->save();

        // create users with valid-looking emails to pass the email validator
        User::fetch('foo', $p4)->setEmail('foo@test.com')->save();
        foreach (array('bar', 'baz', 'joe', 'a', 'b') as $userId) {
            $user = new User($this->p4);
            $user->setId($userId)
                 ->setEmail($userId . '@test.com')
                 ->setFullName('Mr ' . $userId)
                 ->save();
        }

        // create a review as user 'foo'
        $pool = $this->superP4->getService('clients');
        $pool->setConnection($p4Foo)->grab();
        $pool->reset();

        $shelf = new Change($p4Foo);
        $shelf->setDescription('#review')->save();

        $file = new File($p4Foo);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($shelf->getId());
        $p4Foo->run('shelve', array('-c', $shelf->getId(), '//...'));

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // verify that review email has been sent to the expected users
        $mailer    = $this->getApplication()->getServiceManager()->get('mailer');
        $emailFile = $mailer->getLastFile();

        $this->assertNotNull($emailFile, "Expected review email was sent.");
        $content = file_get_contents($emailFile);
        $this->assertTrue(strpos($content, 'foo@test.com') !== false);
        $this->assertTrue(strpos($content, 'bar@test.com') !== false);
        $this->assertTrue(strpos($content, 'baz@test.com') !== false);
        $this->assertFalse(strpos($content, 'joe@test.com'));
        $this->assertFalse(strpos($content, 'a@test.com'));
        $this->assertFalse(strpos($content, 'b@test.com'));
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
     * Helper method to dispatch to '/activity' and compare result with the expected list passed in parameter.
     *
     * @param   array               $accessById     list of true/false flags keyed by activity ids
     *                                              we dispatch to '/activity' and compare the result
     *                                              with this list, test will fail if:
     *                                               - flag is true, but the activity id is not present in the result
     *                                               - flag is false, but the activity is present in the the result
     * @param   Connection|null     $p4             optional - if provided then this connection will be used
     *                                              to emulate authenticated user
     */
    protected function verifyActivityAccess(array $accessById, $p4 = null)
    {
        $this->resetApplication();
        if ($p4) {
            $this->getApplication()->getServiceManager()->setService('p4', $p4);
        }

        $this->dispatch('/activity');
        $body = $this->getResponse()->getBody();
        $data = Json::decode($body, Json::TYPE_ARRAY);

        $visibleIds = array();
        foreach ($data['activity'] as $activity) {
            $visibleIds[] = $activity['id'];
        }

        $user = $this->getApplication()->getServiceManager()->get('p4')->getUser();
        foreach ($accessById as $id => $hasAccess) {
            $visible = in_array($id, $visibleIds);
            $this->assertTrue(
                $hasAccess ? $visible : !$visible,
                "Unexpected activity '$id' is" . ($visible ? ' not' : '') . " visible for user '$user'."
            );
        }
    }
}
