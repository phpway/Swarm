<?php
/**
 * Tests for the comment model.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace CommentTest\Model;

use Application\I18n\Translator;
use Application\Permissions\Protections;
use Comments\Model\Comment;
use P4\Connection\Connection;
use P4\Spec\Protections as P4Protections;
use P4Test\TestCase;
use Users\Model\User;

class CommentTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                 'Zend\Loader\StandardAutoloader' => array(
                     'namespaces' => array(
                         'Application' => BASE_PATH . '/module/Application/src/Application',
                         'Comments'    => BASE_PATH . '/module/Comments/src/Comments',
                         'Users'       => BASE_PATH . '/module/Users/src/Users',
                     )
                 )
            )
        );
    }

    public function testBasicFunction()
    {
        $model = new Comment($this->p4);
    }

    public function testFetchAllEmpty()
    {
        $this->assertSame(
            array(),
            Comment::fetchAll(array(), $this->p4)->toArray(),
            'expected matching result on empty fetch'
        );
    }

    public function testSaveFetchDelete()
    {
        $time    = time();
        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')
            ->set('time', $time)
            ->set('body', 'test1')
            ->save();

        $comment = Comment::fetch(1, $this->p4);

        // verify comment values - we will extract 'updated' and check it later
        // as it is not guaranteed to have the same value as $time
        $expected = $comment->get();
        unset($expected['updated']);
        $this->assertSame(
            array(
                'id'          => 1,
                'topic'       => 'change/1',
                'context'     => array(),
                'attachments' => array(),
                'flags'       => array(),
                'taskState'   => Comment::TASK_COMMENT,
                'likes'       => array(),
                'user'        => null,
                'time'        => $time,
                'edited'      => null,
                'body'        => 'test1'
            ),
            $expected
        );

        // ensure that 'updated' is set and differs from 'time' by no more than 1s
        // since 'update' is set on save, it can (rarely) happen that it will be greater than 'time'
        $this->assertTrue(abs($comment->get('updated') - $time) <= 1);

        $time    = time();
        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')
            ->set('time', $time)
            ->set('body', 'test1')
            ->save();

        $comments = Comment::fetchAll(array(), $this->p4);
        $this->assertSame(2, count($comments));

        // remove the first comment.
        Comment::fetch(1, $this->p4)->delete();

        // verify count.
        $this->assertSame(
            array(1, 0),
            Comment::countByTopic('change/1', $this->p4)
        );

        // remove the last comment.
        Comment::fetch(2, $this->p4)->delete();

        $this->assertSame(
            array(0, 0),
            Comment::countByTopic('change/1', $this->p4)
        );
    }

    public function testFetchAllWithIpProtects()
    {
        // set protections for testing
        $protections = P4Protections::fetch($this->p4);
        $protections->setProtections(
            array(
                'read user * * //...',
                'super user tester * //...',
                'list user foo 1.2.3.4 -//...',
                'list user foo 1.2.3.4 //foo/...',
                'read user foo 1.2.3.4 //foo/read/...'
            )
        )->save();

        // create several comments
        $comment = new Comment($this->p4);
        $comment->set('topic', 'test/1')
            ->setContext(array('file' => '//depot/a'))
            ->save();

        $comment = new Comment($this->p4);
        $comment->set('topic', 'test/2')
            ->setContext(array('file' => '//foo/a'))
            ->save();

        $comment = new Comment($this->p4);
        $comment->set('topic', 'test/3')
            ->setContext(array('file' => '//foo/read/a'))
            ->save();

        // create connection for foo user
        $user = new User($this->p4);
        $user->setId('foo')
            ->setFullName('foo test')
            ->setEmail('test@test')
            ->save();

        $p4Params = $this->getP4Params();
        $p4Foo    = Connection::factory(
            $p4Params['port'],
            'foo',
            'client-foo-test',
            '',
            null,
            null
        );

        // prepare helper function to get perforce protections for a given ip and connection
        $getIpProtections = function ($ip, $connection) {
            return $connection->run('protects', array('-h', $ip))->getData();
        };

        // test with ip protections effectivelly disabled
        $protects = new Protections;
        $protects->setEnabled(false);
        $models = Comment::fetchAll(array(), $this->p4, $protects);
        $this->assertSame(3, $models->count());

        // test with ip protections with no protections defined, i.e. it should filter out everything
        $protects = new Protections;
        $protects->setProtections(null);
        $models = Comment::fetchAll(array(), $this->p4, $protects);
        $this->assertSame(0, $models->count());

        // test with protects enabled, but with non-restrictive IP
        $protects = new Protections;
        $protects->setProtections($getIpProtections('1.1.1.1', $this->p4));
        $models = Comment::fetchAll(array(), $this->p4, $protects);
        $this->assertSame(3, $models->count());

        $protects = new Protections;
        $protects->setProtections($getIpProtections('1.1.1.1', $p4Foo));
        $models = Comment::fetchAll(array(), $this->p4, $protects);
        $this->assertSame(3, $models->count());

        // test with restricted IP
        $protects = new Protections;
        $protects->setProtections($getIpProtections('1.2.3.4', $this->p4));
        $models = Comment::fetchAll(array(), $this->p4, $protects);
        $this->assertSame(3, $models->count());

        $protects = new Protections;
        $protects->setProtections($getIpProtections('1.2.3.4', $p4Foo));
        $models = Comment::fetchAll(array(), $this->p4, $protects);
        $this->assertSame(1, $models->count());
        $this->assertSame('test/3', $models->first()->get('topic'));
    }

    public function testCount()
    {
        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')
            ->set('body', 'test1')
            ->save();

        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/2')
            ->set('body', 'test2')
            ->save();

        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')
            ->set('body', 'test3')
            ->save();

        // verify we cleaned up the earlier count index
        $indices = $this->p4->run(
            'search',
            Comment::COUNT_INDEX . '=' . strtoupper(bin2hex('change/1'))
        )->getData();
        $this->assertSame(
            array(strtoupper(bin2hex('change/1')) . '-2-0'),
            $indices,
            'expected matching search output for counts'
        );

        // ensure we get the right answer from the model
        $this->assertSame(
            array(2, 0),
            Comment::countByTopic('change/1', $this->p4),
            'expected matching count for change 1'
        );

        // test multi-count
        $this->assertSame(
            array('change/1' => array(2, 0), 'change/2' => array(1, 0)),
            Comment::countByTopic(array('change/1', 'change/2'), $this->p4),
            'expected matching count for changes 1 & 2'
        );

        // test multi-count
        $this->assertSame(
            array('change/1' => array(2, 0), 'change/2' => array(1, 0), 'no-such/topic' => array(0, 0)),
            Comment::countByTopic(array('change/1', 'change/2', 'no-such/topic'), $this->p4),
            'expected matching count for changes 1, 2 and no such topic.'
        );

        // test closing (aka 'archiving') comments
        $comment = Comment::fetch(1, $this->p4)->addFlags(array('closed'))->save();
        $this->assertSame(
            array(1, 1),
            Comment::countByTopic('change/1', $this->p4),
            'expected matching count for change 1'
        );
    }

    public function testFetchByTopic()
    {
        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')
            ->set('description', 'test1')
            ->save();

        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/2')
            ->set('description', 'test2')
            ->save();

        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')
            ->set('description', 'test3')
            ->save();

        // ensure we get the right answer from the model
        $this->assertSame(
            2,
            count(Comment::fetchAll(array('topic' => 'change/1'), $this->p4)),
            'expected matching count for change/1 topic'
        );

        $this->assertSame(
            1,
            count(Comment::fetchAll(array('topic' => 'change/2'), $this->p4)),
            'expected matching count for change/2 topic'
        );

        Comment::fetch(1, $this->p4)->delete();

        $this->assertSame(
            1,
            count(Comment::fetchAll(array('topic' => 'change/1'), $this->p4)),
            'expected matching count for change/1 topic post delete'
        );
    }

    public function testGetSetAttachments()
    {
        $comment = new Comment($this->p4);

        $attachments = array(
            1,
            10,
            'foo',
            "15",
        );

        // verify the "no attachments" behaviour
        $this->assertSame(array(), $comment->getAttachments());

        // verify that the attachments array gets sanitized into integers by the mutator/accessor
        $comment->setAttachments($attachments);
        $this->assertNotSame($attachments, $comment->getAttachments());
        $this->assertSame(array(1, 10, 15), $comment->getAttachments());
    }

    public function testGetSetState()
    {
        $comment = new Comment($this->p4);

        // ensure that new comments default to the correct state
        $this->assertSame(Comment::TASK_COMMENT, $comment->getTaskState());

        // ensure setting an unknown state throws an exception
        $comment = new Comment($this->p4);
        try {
            $comment->setTaskState('comatose');
        } catch (\Exception $e) {
            $this->assertStringStartsWith('Invalid task state: comatose.', $e->getMessage());
        }

        // ensure setting a valid state causes the state to change
        $comment->setTaskState(Comment::TASK_OPEN);
        $this->assertSame(Comment::TASK_OPEN, $comment->getTaskState());

        // ensure saving and fetching retains the state
        $comment->set('topic', 'change/1')
                ->set('description', 'test1')
                ->save();
        $comment = Comment::fetchAll(array(), $this->p4)
                    ->first();

        $this->assertSame(Comment::TASK_OPEN, $comment->getTaskState());

        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/2')
                ->set('description', 'foo')
                ->setTaskState(Comment::TASK_VERIFIED)
                ->save();

        $comments = Comment::fetchAll(array(), $this->p4);
        $this->assertSame(Comment::TASK_VERIFIED, $comments[2]->getTaskState());

        // ensure that changing to 'verified:archive' changes the state to verified
        $comment = new Comment($this->p4);
        $comment->set('state', Comment::TASK_ADDRESSED);

        $comment->setTaskState('verified:archive');
        $this->assertSame(Comment::TASK_VERIFIED, $comment->getTaskState());
    }

    public function testStateTransitions()
    {
        // translator service is required for the model test to succeed once task transition strings are localized
        // model tests manually instantiate the connection, so the service is not attached to the connection
        // we add a null translator here to prevent the ->getService() call from failing
        $p4 = $this->p4;
        $p4->setService(
            'translator',
            function () {
                return new Translator();
            }
        );
        $comment = new Comment($p4);

        // ensure state transitions are correct for each state
        $this->assertSame(array(Comment::TASK_OPEN), array_keys($comment->getTaskTransitions()));
        $this->assertTrue($this->isValidTransition($comment, $comment->getTaskState()));
        $this->assertTrue($this->isValidTransition($comment, Comment::TASK_OPEN));
        $this->assertFalse($this->isValidTransition($comment, Comment::TASK_VERIFIED));
        $this->assertFalse($this->isValidTransition($comment, 'woot'));

        $comment->setTaskState(Comment::TASK_OPEN);
        $this->checkTransitions(
            array(Comment::TASK_ADDRESSED, Comment::TASK_COMMENT),
            array_keys($comment->getTaskTransitions())
        );

        $this->assertTrue($this->isValidTransition($comment, Comment::TASK_COMMENT));
        $this->assertFalse($this->isValidTransition($comment, Comment::TASK_VERIFIED));

        $comment->setTaskState(Comment::TASK_ADDRESSED);
        $this->checkTransitions(
            array(Comment::TASK_OPEN, Comment::TASK_VERIFIED, 'verified:archive'),
            array_keys($comment->getTaskTransitions())
        );

        $this->assertTrue($this->isValidTransition($comment, Comment::TASK_VERIFIED));
        $this->assertFalse($this->isValidTransition($comment, Comment::TASK_COMMENT));

        $comment->setTaskState(Comment::TASK_VERIFIED);
        $this->checkTransitions(
            array(Comment::TASK_OPEN),
            array_keys($comment->getTaskTransitions())
        );
        $this->assertTrue($this->isValidTransition($comment, Comment::TASK_OPEN));
        $this->assertFalse($this->isValidTransition($comment, Comment::TASK_ADDRESSED));
        $this->assertFalse($this->isValidTransition($comment, 'scoobydoo'));
    }

    public function testCommentActionDetection()
    {
        $comment = new Comment($this->p4);
        $comment->set('topic', 'change/1')->set('body', 'test1')->save();
        $this->assertSame(Comment::ACTION_ADD, Comment::deriveAction($comment));

        // test editing
        $old = $comment->get();
        $comment->set('body', 'testing')->save();
        $this->assertSame(Comment::ACTION_EDIT, Comment::deriveAction($comment, $old));

        // test state change
        $old = $comment->get();
        $comment->set('taskState', Comment::TASK_OPEN)->save();
        $this->assertSame(Comment::ACTION_STATE_CHANGE, Comment::deriveAction($comment, $old));

        // test nothing to do
        $old = $comment->get();
        $this->assertSame(Comment::ACTION_NONE, Comment::deriveAction($comment, $old));

        // test weirdness
        $this->assertSame(Comment::ACTION_NONE, Comment::deriveAction($comment, $comment));
        $this->assertSame(Comment::ACTION_NONE, Comment::deriveAction(array(), array()));
    }

    public function testLikes()
    {
        $comment = new Comment($this->p4);
        $comment->set('topic', 'x');

        $this->assertSame(array(), $comment->getLikes());
        $comment->setLikes(array('a', 'b', 'c', 'b'))->save();
        $this->assertSame(array('a', 'b', 'c'), $comment->getLikes());
        $comment->addLike('foo')->save();
        $this->assertSame(array('a', 'b', 'c', 'foo'), $comment->getLikes());
        $comment->removeLike('b')->save();
        $this->assertSame(array('a', 'c', 'foo'), $comment->getLikes());
    }

    private function checkTransitions($expected, $actual)
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    private function isValidTransition($comment, $transition)
    {
        $transitions = $comment->getTaskTransitions();
        return isset($transitions[$transition]) || $transition == $comment->getTaskState();
    }
}
