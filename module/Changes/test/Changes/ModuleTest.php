<?php
/**
 * Tests for the review module.
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ChangesTest;

use Groups\Model\Group;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Depot;
use P4\Spec\User;

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
                        'ChangesTest' => BASE_PATH . '/module/Changes/test/Changes',
                    )
                )
            )
        );
    }

    public function testGitChangeGitUserRetry()
    {
        // ensure changes owned by the git fusion user are not processed instantly but
        // instead are pushed into the future until owned by another user (up to a limit)

        $services  = $this->getApplication()->getServiceManager();
        $queue     = $services->get('queue');
        $events    = $queue->getEventManager();
        $p4        = $this->p4;
        $queuePath = $queue->getConfig();
        $queuePath = $queuePath['path'];

        // create the git-fusion-user so we can credit the change to them
        $user = new User($this->p4);
        $user->setId('git-fusion-user')->setEmail('foo@bar.com')->setFullName('Git-Fusion User')->save();

        // create a change as per git's usage
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
        $p4->run('revert', array('//...'));

        // swap it to the git-fusion-user post shelf (as the shelving will fail otherwise)
        $shelf->setUser('git-fusion-user')->save();

        // add a listener before the event is handled to ensure the retry count from disk is correct
        $retry = false;
        $events->attach(
            'task.shelve',
            function ($event) use (&$retry) {
                $data  = (array) $event->getParam('data');
                $retry = isset($data['retries']) ? $data['retries'] : false;
            },
            301
        );

        // add a listener that comes after the event should be cancelled to confirm that happened
        $aborted = true;
        $events->attach(
            'task.shelve',
            function ($event) use (&$aborted) {
                $aborted = false;
            },
            299
        );

        // verify attempts 1-20 work out correctly and have a roughly appropriate delay
        for ($i = 0; $i < 20; $i++) {
            // push into queue and process
            $this->assertSame(0, $queue->getTaskCount());
            $data = null;
            if ($i) {
                $data = array('retries' => $i);
            }
            $queue->addTask('shelve', $shelf->getId(), $data);
            $this->processQueue();

            // verify the task wasn't fully processed
            $this->assertTrue($aborted, "iteration $i event should have aborted!");
            $this->assertSame($i ?: false, $retry, "iteration $i retry count isn't expected value");

            // queue should now contain 1 task, but we can't grab it yet
            $this->assertSame(1, $queue->getTaskCount(), "iteration $i task count");
            $this->assertFalse($queue->grabTask(), "iteration $i grab attempt");

            // verify the correct retry count
            $file = current(glob($queuePath . "/*"));
            $task = $queue->parseTaskFile($file);
            $this->assertSame(array('retries' => $i + 1), $task['data'], "iteration $i data");

            // verify the time is in the correct neighborhood (we allow a seconds slop to allow for clock rollover)
            $delay    = (int) ltrim(substr(basename($file), 0, -7), 0) - (int) microtime(true);
            $expected = min(pow(2, $i + 1), 60);
            $this->assertTrue($delay >= ($expected - 1), "iteration $i delay is too small");
            $this->assertTrue($delay < ($expected + 1),  "iteration $i delay is too big");

            unlink($file);
        }

        // verify attempt 21 results in event being processed
        // push into queue and process
        $queue->addTask('shelve', $shelf->getId(), array('retries' => 20));
        $this->processQueue();

        // verify the task was fully processed
        $this->assertFalse($aborted, "iteration 20 event should not have aborted!");
        $this->assertSame(20, $retry, "iteration 20 retry count isn't expected value");

        // queue should now contain 0 tasks
        $this->assertSame(0, $queue->getTaskCount(), "iteration 20 task count");
    }

    public function testGitChangeGitFusionDepot()
    {
        // verify changes owned by git-fusion-user are not delayed
        //  if they are against the .git-fusion depot

        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;

        // create the git-fusion-user so we can credit the change to them
        $user = new User($this->p4);
        $user->setId('git-fusion-user')->setEmail('foo@bar.com')->setFullName('Git-Fusion User')->save();

        // create the .git-fusion depot
        $depot = new Depot($this->superP4);
        $depot->setId('.git-fusion')->setType('local')->setMap(DATA_PATH . '/git-fusion/...')->save();
        $this->p4->disconnect()->connect();

        $this->p4->getService('clients')->grab();

        // create a change as per git's usage
        $shelf = new Change($p4);
        $shelf->setDescription("Test git change!")->save();
        $file = new File($p4);
        $file->setFilespec('//.git-fusion/foo');
        $file->setLocalContents('some file contents');
        $file->add(1);
        $p4->run('shelve', array('-c', 1, '//...'));
        $p4->run('revert', array('//...'));

        // swap it to the git-fusion-user post shelf (as the shelving will fail otherwise)
        $shelf->setUser('git-fusion-user')->save();

        // push into queue and process
        $queue->addTask('shelve', $shelf->getId());
        $this->processQueue();

        // verify the task was fully processed
        $this->assertSame(0, $queue->getTaskCount(), "shelf against .git-fusion");


        // now lets try it with a commit
        $shelf->setUser($this->p4->getUser())->save(true);
        $p4->run('unshelve', array('-s', $shelf->getId(), '-c', $shelf->getId(), '-f'));
        $p4->run('shelve', array('-c', $shelf->getId(), '-d'));
        $shelf->submit('test commit');
        $shelf->setUser('git-fusion-user')->save(true);

        // push into queue and process
        $queue->addTask('commit', $shelf->getId());
        $this->processQueue();

        // verify the task was fully processed
        $this->assertSame(0, $queue->getTaskCount(), "commit against .git-fusion");
    }

    /**
     * Verify that when a group member commits a change, all other members from that group are notified via an email,
     * but only if the group is configured for it.
     */
    public function testEmailGroupMembersOnCommit()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $p4       = $this->p4;
        $p4Foo    = $this->connectWithAccess('foo', array('//depot/...' => 'write'));

        // we create 3 groups:
        // - group g1 containing user 'foo' and configured for emailing about commits
        // - group g2 also containing user 'foo' but not configured for emailing about commits
        // - group g3 not containing user 'foo' but configured for emailing about commits
        // - group g4 containing user 'foo' and configured for emailing about commits
        // when foo commits a change, only users from groups g1 and g4 should get notification
        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('commits' => 1));
        $group->setId('g1')
              ->setUsers(array('foo', 'bar', 'baz'))
              ->save();

        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('commits' => 0));
        $group->setId('g2')
              ->setUsers(array('foo', 'bar', 'joe'))
              ->save();

        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('commits' => 1));
        $group->setId('g3')
              ->setUsers(array('a', 'b'))
              ->save();

        $group = new Group($this->superP4);
        $group->getConfig()->setEmailFlags(array('commits' => 1));
        $group->setId('g4')
              ->setUsers(array('foo', 'x', 'y'))
              ->save();

        // create users with valid-looking emails to pass the email validator
        User::fetch('foo', $p4)->setEmail('foo@test.com')->save();
        foreach (array('bar', 'baz', 'joe', 'a', 'b', 'x', 'y') as $userId) {
            $user = new User($this->p4);
            $user->setId($userId)
                 ->setEmail($userId . '@test.com')
                 ->setFullName('Mr ' . $userId)
                 ->save();
        }

        // commit a change as 'foo'
        $pool = $this->superP4->getService('clients');
        $pool->setConnection($p4Foo)->grab();
        $pool->reset();

        $change = new Change($p4Foo);
        $change->setDescription('test')->save();

        $file = new File($p4Foo);
        $file->setFilespec('//depot/test1');
        $file->setLocalContents('some file contents');
        $file->add($change->getId());
        $change->submit();

        // push into queue and process
        $queue->addTask('commit', $change->getId());
        $this->processQueue();

        // verify that commit email has been sent to the expected users
        $mailer    = $this->getApplication()->getServiceManager()->get('mailer');
        $emailFile = $mailer->getLastFile();

        $this->assertNotNull($emailFile, "Expected commit email was sent.");
        $content = file_get_contents($emailFile);
        $this->assertTrue(strpos($content, 'foo@test.com') !== false);
        $this->assertTrue(strpos($content, 'bar@test.com') !== false);
        $this->assertTrue(strpos($content, 'baz@test.com') !== false);
        $this->assertTrue(strpos($content, 'x@test.com') !== false);
        $this->assertTrue(strpos($content, 'y@test.com') !== false);
        $this->assertFalse(strpos($content, 'joe@test.com'));
        $this->assertFalse(strpos($content, 'a@test.com'));
        $this->assertFalse(strpos($content, 'b@test.com'));
    }

    protected function processQueue()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
    }
}
