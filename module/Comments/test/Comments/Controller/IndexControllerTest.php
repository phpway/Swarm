<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace CommentsTest\Controller;

use Comments\Model\Comment;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;
use P4\Spec\Job;
use Reviews\Model\Review;
use Users\Model\User;
use Zend\Http\Request;
use Zend\Json\Json;
use Zend\Stdlib\Parameters;

class IndexControllerTest extends TestControllerCase
{
    /**
     * Test index action.
     */
    public function testIndexAction()
    {
        // create several comments
        $comments = array(
            array(
                'topic'     => 'a/b',
                'user'      => 'foo',
                'body'      => 'comment 1'
            ),
            array(
                'topic'     => 'a/c',
                'user'      => 'bar',
                'body'      => 'abc z'
            ),
            array(
                'topic'     => 'a/b/123',
                'user'      => 'foo',
                'body'      => 'xyz'
            ),
            array(
                'topic'     => 'a/b/123',
                'user'      => 'foo',
                'body'      => 'xyz 123'
            ),
            array(
                'topic'     => 'a/b',
                'user'      => 'bar',
                'body'      => 'comment 2'
            ),
            array(
                'topic'     => 'a/c',
                'user'      => 'foo',
                'body'      => 'abc x'
            ),
            array(
                'topic'     => 'a/c',
                'user'      => 'foo',
                'body'      => 'abc y'
            ),
        );
        foreach ($comments as $values) {
            $model = new Comment($this->p4);
            $model->set($values)
                  ->save();
        }

        $this->dispatch('/comments/a/c');

        // verify output
        $result = $this->getResult();
        $this->assertRoute('comments');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertSame('a/c', $result->getVariable('topic'));
        $this->assertQueryCount('.comments-wrapper .comments-table tr.row-main', 3);
        $this->assertQueryContentContains('.comments-wrapper .comment-body', 'abc x');
        $this->assertQueryContentContains('.comments-wrapper .comment-body', 'abc y');
        $this->assertQueryContentContains('.comments-wrapper .comment-body', 'abc z');

        // ensure that url with singular case works as well
        $this->resetApplication();
        $this->dispatch('/comment/a/b/');

        $result = $this->getResult();
        $this->assertRoute('comments');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertSame('a/b', $result->getVariable('topic'));
        $this->assertQueryCount('.comments-wrapper .comments-table tr.row-main', 2);
        $this->assertQueryContentContains('.comments-wrapper .comment-body', 'comment 1');
        $this->assertQueryContentContains('.comments-wrapper .comment-body', 'comment 2');
    }

    /**
     * Test index action with url pointing to a non-existing topic.
     */
    public function testIndexActionWithNonExistingTopic()
    {
        $this->dispatch('/comments/not-exists');

        // verify output
        $result = $this->getResult();
        $this->assertRoute('comments');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertSame('not-exists', $result->getVariable('topic'));
        $this->assertQueryCount('.comments-wrapper .comments-table tr', 0);
        $this->assertNotQuery('.comments-wrapper .comments-table');
        $this->assertNotQuery('.comments-wrapper .comment-body');
    }

    /**
     * Test a case where comments contain a context.
     */
    public function testIndexActionWithContext()
    {
        // create several comments
        $comments = array(
            array(
                'topic'     => 'x/1',
                'user'      => 'foo',
                'body'      => 'line lr 20',
                'context'   => array(
                    'file'      => '//depot/foo/FileA',
                    'leftLine'  => 20,
                    'rightLine' => 20
                )
            ),
            array(
                'topic'     => 'x/1',
                'user'      => 'bar',
                'body'      => 'line l 21',
                'context'   => array(
                    'file'      => '//depot/foo/FileA',
                    'leftLine'  => 21,
                    'rightLine' => null
                )
            ),
            array(
                'topic'     => 'x/1',
                'user'      => 'baz',
                'body'      => 'line r 22',
                'context'   => array(
                    'file'      => '//depot/foo/FileB',
                    'leftLine'  => null,
                    'rightLine' => 22,
                    'content'   => array(
                        'line 1',
                        ' line 2',
                        '  line 3',
                        '  line 4',
                        '  line 5'
                    )
                )
            )
        );
        foreach ($comments as $values) {
            $model = new Comment($this->p4);
            $model->set($values)
                  ->save();
        }

        $this->dispatch('/comments/x/1');

        // verify output
        $result = $this->getResult();
        $this->assertRoute('comments');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
        $this->assertSame('x/1', $result->getVariable('topic'));
        $this->assertQueryCount('.comments-wrapper .comments-table tr.row-main', 3);
        $this->assertQueryContentContains('.comments-wrapper span.context', '(on FileA, line 20)');
        $this->assertQueryContentContains('.comments-wrapper span.context', '(on FileA, line 21)');
        $this->assertQueryContentContains('.comments-wrapper span.context', '(on FileB, line 22)');
        $this->assertQueryContentContains('.comments-wrapper .content-context .content-line-value', 'line 1');
        $this->assertQueryContentContains('.comments-wrapper .content-context .content-line-value', ' line 2');
        $this->assertQueryContentContains('.comments-wrapper .content-context .content-line-value', '  line 3');
        $this->assertQueryContentContains('.comments-wrapper .content-context .content-line-value', '  line 4');
        $this->assertQueryContentContains('.comments-wrapper .content-context .content-line-value', '  line 5');
    }

    /**
     * Test rendering comments related to restricted changes.
     */
    public function testIndexActionWithRestrictedChanges()
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

        // create couple of reviews
        $review1 = new Review($this->p4);
        $review1->setChanges(array($changes[1], $changes[2]))->save();

        $review2 = new Review($this->p4);
        $review2->setChanges(array($changes[3]))->save();

        // create several comments
        // prepare topics with values denoting whether its restricted for 'foo'
        $topics = array(
            'a/' . $changes[1]             => 0,
            'a/' . $changes[2]             => 0,
            'a/' . $changes[3]             => 0,
            'changes/' . $changes[1]       => 1,
            'changes/' . $changes[2]       => 0,
            'changes/' . $changes[3]       => 1,
            'reviews/' . $review1->getId() => 0,
            'reviews/' . $review2->getId() => 1
        );
        foreach (array_keys($topics) as $topic) {
            $model = new Comment($this->p4);
            $model->set('topic', $topic)->set('body', 'comment ' . $topic)->save();
        }

        foreach ($topics as $topic => $isRestricted) {
            // standard user should see the comment
            $this->resetApplication();
            $this->dispatch('/comments/' . $topic);

            $result = $this->getResult();
            $this->assertRoute('comments');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
            $this->assertQueryCount('.comments-wrapper .comments-table tr.row-main', 1);

            // verify the same in json
            $this->resetApplication();
            $this->getRequest()->getQuery()->set('format', 'json');
            $this->dispatch('/comments/' . $topic);

            $result = $this->getResult();
            $this->assertRoute('comments');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $data = Json::decode($this->getResponse()->getBody(), Json::TYPE_ARRAY);
            $this->assertSame(1, count($data['comments']));

            // verify access for user 'foo'
            $this->resetApplication();
            $this->getApplication()->getServiceManager()->setService('p4', $p4Foo);
            $this->dispatch('/comments/' . $topic);

            $result = $this->getResult();
            $this->assertRoute('comments');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
            $this->assertResponseStatusCode($isRestricted ? 403 : 200);
            if (!$isRestricted) {
                $this->assertInstanceOf('Zend\View\Model\ViewModel', $result);
                $this->assertQueryCount('.comments-wrapper .comments-table tr.row-main', 1);
            }

            // same in json
            $this->resetApplication();
            $this->getApplication()->getServiceManager()->setService('p4', $p4Foo);
            $this->getRequest()->getQuery()->set('format', 'json');
            $this->dispatch('/comments/' . $topic);

            $result = $this->getResult();
            $this->assertRoute('comments');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
            $this->assertResponseStatusCode($isRestricted ? 403 : 200);
            if (!$isRestricted) {
                $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
                $data     = Json::decode($this->getResponse()->getBody(), Json::TYPE_ARRAY);
                $comments = $data['comments'];
                $this->assertSame(1, count($comments));
            }
        }
    }

    /**
     * Test rendering comments related to a change that has been renumbered.
     */
    public function testIndexActionWithRenumberedChange()
    {
        // create a change that gets renumbered
        $change = new Change($this->p4);
        $change->save();
        $oldId   = $change->getId();
        $change1 = new Change($this->p4);
        $change1->save();

        $file = new File;
        $file->setFilespec('//depot/test')->open()->setLocalContents('abc');
        $change->addFile($file)->submit('test');
        $newId = $change->getId();

        // create comment with topic refering to old change
        $model = new Comment($this->p4);
        $model->set('topic', 'changes/' . $oldId)->set('body', 'test')->save();

        // at this point, change with id=oldId doesn't exist, however fetching comments
        // with topic refering to old change id should still work
        $this->getRequest()->getQuery()->set('context', array('change' => $newId));
        $this->dispatch('/comments/changes/' . $oldId);
        $result = $this->getResult();
        $this->assertRoute('comments');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertQueryCount('.comments-wrapper .comments-table tr.row-main', 1);
    }

    /**
     * Verify that topic must be provided when rendering comments.
     */
    public function testIndexActionWithNoTopic()
    {
        $this->dispatch('/comments');
        $this->assertRoute('comments');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test add action with invalid data.
     */
    public function testAddActionWithInvalidData()
    {
        // set active user
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new \Users\Authentication\Adapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $tests = array(
            array(
                'postData'  => array(
                    'user'      => '',
                    'topic'     => 'test',
                    'body'      => 'a b c'
                ),
                'message'   => __LINE__ . 'empty user'
            ),
            array(
                'postData'  => array(
                    'user'      => 'foo',
                    'topic'     => '',
                    'body'      => 'abc'
                ),
                'message'   => __LINE__ . 'empty topic'
            ),
            array(
                'postData'  => array(
                    'user'      => 'foo',
                    'topic'     => 'xyz',
                    'body'      => ''
                ),
                'message'   => __LINE__ . 'empty comment'
            ),
            array(
                'postData'  => array(
                    'user'      => 'bar',
                    'topic'     => 'a',
                    'body'      => 'x'
                ),
                'message'   => __LINE__ . 'user is different from current user'
            ),
            array(
                'postData'  => array(
                    'topic'     => 'test',
                    'body'      => 'xyz'
                ),
                'message'   => __LINE__ . 'missing user'
            ),
            array(
                'postData'  => array(
                    'user'      => 'foo',
                    'body'      => 'xyz'
                ),
                'message'   => __LINE__ . 'missing topic'
            ),
            array(
                'postData'  => array(
                    'user'      => 'foo',
                    'topic'     => 'topic'
                ),
                'message'   => __LINE__ . 'missing message'
            ),
            array(
                'postData'  => array(),
                'message'   => __LINE__ . 'missing all data'
            )
        );

        foreach ($tests as $test) {
            $postData = new Parameters($test['postData']);
            $this->getRequest()
                 ->setMethod(\Zend\Http\Request::METHOD_POST)
                 ->setPost($postData);

            // dispatch and check output
            $this->dispatch('/comments/add');
            $result = $this->getResult();
            $this->assertRoute('add-comment');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertSame(false, $result->getVariable('isValid'), $test['message']);
            $this->assertSame(null, $result->getVariable('comments'), $test['message']);
            $this->assertNotEmpty($result->getVariable('messages'), $test['message']);

            // reset application
            $this->resetApplication();
        }

        // ensure no comments have been stored
        $this->assertSame(0, count(Comment::fetchAll(array(), $this->p4)));
    }

    /**
     * Test add action with valid data.
     */
    public function testAddActionWithValidData()
    {
        $tests = array(
            array(
                'topic'     => 'test',
                'body'      => 'a b c'
            ),
            array(
                'topic'     => 'a/b',
                'body'      => 'lorem ipsum'
            ),
            array(
                'topic'     => 'abc/def/xyz-123',
                'body'      => 'abc xyz'
            ),
            array(
                'topic'     => 'a',
                'body'      => 'q w e r t y u i o p'
            ),
            array(
                'topic'     => 'a-b-c',
                'context'   => json_encode(
                    array(
                        'file'      => 'depot//a/b/file.txt',
                        'leftLine'  => null,
                        'rightLine' => 100,
                        'content'   => array('1', '2', '3', 'x', 'a b c')
                    )
                ),
                'body'      => 'a b c',
                'timestamp' => 123
            )
        );

        foreach ($tests as $test) {
            $postData = new Parameters($test + array('user' => 'nonadmin'));
            $this->getRequest()
                 ->setMethod(\Zend\Http\Request::METHOD_POST)
                 ->setPost($postData);

            // dispatch and check output
            $this->dispatch('/comments/add');
            $result = $this->getResult();
            $this->assertRoute('add-comment');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertSame(true, $result->getVariable('isValid'));

            // reset application
            $this->resetApplication();
        }

        // ensure comments have been stored (check only the total number)
        $this->assertSame(count($tests), count(Comment::fetchAll(array(), $this->p4)));
    }

    public function testDelayedComments()
    {
        $services = $this->getApplication()->getServiceManager();
        $mailer   = $services->get('mailer');

        // prepare user with valid email to pass the email validator
        $foo = new User($this->superP4);
        $foo->setId('foo')->setFullName('foo')->setEmail('foo@test.com')->save();

        // set active user
        $p4Bar   = $this->connectWithAccess('bar', array( '//depot/...' => 'write'));
        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new \Users\Authentication\Adapter('bar', '', $this->p4);
        $auth->authenticate($adapter);

        // create a review
        $review = new Review($this->p4);
        $review->setId(1)->setParticipants(array('foo'))->save();

        // add delayed review comment
        $data     = array(
            'topic'             => 'reviews/1',
            'user'              => 'bar',
            'body'              => 'testing @foo',
            'delayNotification' => true
        );
        $postData = new Parameters($data);
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/comments/add');
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);

        // ensure no emails were sent
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
        $this->assertSame(null, $mailer->getLastFile());

        // add another delayed comment
        $data     = array(
            'topic'             => 'reviews/1',
            'user'              => 'bar',
            'body'              => 'another comment',
            'delayNotification' => true
        );
        $postData = new Parameters($data);
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/comments/add');
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);

        // ensure that no email were sent
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
        $this->assertSame(null, $mailer->getLastFile());

        // now add a comment and end the batch
        $data     = array(
            'topic'             => 'reviews/1',
            'user'              => 'bar',
            'body'              => 'comment #3',
            'delayNotification' => false
        );
        $postData = new Parameters($data);
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/comments/add');
        $this->assertRoute('add-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
        $this->assertResponseStatusCode(200);

        // ensure there was an email sent
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
        $this->assertTrue(strlen($mailer->getLastFile()) > 0);
    }

    /**
     * Test new comment with attachment
     */
    public function testAddWithAttachment()
    {
        $services = $this->getApplication()->getServiceManager();

        // Override the service container to set depot_storage_path
        $config                               = $services->get('config');
        $config['depot_storage']['base_path'] = '//depot/swarm-attachments';
        $services->setService('config', $config);
        $depot  = $services->get('depot_storage');
        $mailer = $services->get('mailer');
        $services->get('p4_admin')->setService('depot_storage', $depot);

        // update the admin email address to follow the proper spec, then flush the disk cache
        User::fetch('admin')->setEmail('swarm-admin@example.com')->save();
        $this->p4->getService('cache')->invalidateItem('users');

        $tests = array(
            array(
                'topic'       => 'test',
                'body'        => 'testing @admin @nonadmin',
                'context'     => array(),
                'attachments' => array(),
                'test_data'   => array(
                    array(
                        'name' => 'test%22swarm.txt',
                        'data' => '123456',
                        'type' => 'text/plain'
                    ),
                ),
            ),
        );

        foreach ($tests as $test) {
            $attachments = $test['test_data'];
            unset($test['test_data']);

            // transmit and test the attachment(s)
            foreach ($attachments as $attachment) {
                // upload attachment
                $OLD_FILES = $_FILES;
                $_FILES    = array();

                $attachment['tmp_name']     = tempnam(DATA_PATH, 'attach_test');
                $_FILES['file']['name']     = $attachment['name'];
                $_FILES['file']['size']     = strlen($attachment['data']);
                $_FILES['file']['type']     = $attachment['type'];
                $_FILES['file']['tmp_name'] = $attachment['tmp_name'];

                file_put_contents($attachment['tmp_name'], $attachment['data']);

                $postData = new Parameters();
                $this->getRequest()
                    ->setMethod(\Zend\Http\Request::METHOD_POST)
                    ->setPost($postData);
                $this->dispatch('/attachments/add');
                $result = $this->getResult();
                $vars   = $result->getVariables();
                $this->assertResponseStatusCode(
                    200,
                    isset($vars['exception'])
                    ? 'Message was: ' . $vars['exception']->getMessage()
                    : 'Error adding attachment. Also, exception was not returned.'
                );
                $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
                // retrieve ID
                // add to test context
                $test['attachments'][] = $vars['attachment']['id'];

                $_FILES = $OLD_FILES;
            }

            // test the comment with the attachment as context
            $test['context'] = json_encode($test['context']);

            $postData = new Parameters($test + array('user' => 'nonadmin'));
            $this->getRequest()
                ->setMethod(\Zend\Http\Request::METHOD_POST)
                ->setPost($postData);

            // dispatch and check output
            $this->dispatch('/comments/add');
            $result = $this->getResult();
            $this->assertRoute('add-comment');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'add');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertSame(true, $result->getVariable('isValid'));
            $this->assertContains('/attachments/1', $result->getVariable('comments'));

            // check that attachment can properly be retrieved
            $this->resetApplication();

            $this->dispatch('/attachments/1/test%22swarm.txt');
            $result = $this->getResult();
            $this->assertInstanceOf('Application\Response\CallbackResponse', $result);
            $this->assertResponseStatusCode(200);
            $this->assertEquals('123456', $result->getContent());
            $this->assertEquals(
                'attachment',
                $result->getHeaders()->get('Content-Disposition')->getFieldValue()
            );

            // check that attachment can't be retrieved with a fake filename
            $this->resetApplication();

            $this->dispatch('/attachments/1/testxswarm.txt');
            $this->assertResponseStatusCode(404);

            // reset application
            $this->resetApplication();
            $this->getApplication()->getServiceManager()->setService('mailer', $mailer);

            $admin = \P4\Spec\User::fetch('nonadmin');
            $admin->set('Email', 'test@example.com');
            $admin->save();

            $lastFile = $mailer->getLastFile();

            $this->assertSame(null, $lastFile, 'expected empty mail queue');

            $queue = $services->get('queue');
            $this->assertSame(2, $queue->getTaskCount());

            $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
            $this->dispatch('/queue/worker');

            $this->resetApplication();

            $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
            $this->dispatch('/queue/worker');

            $lastFile = $mailer->getLastFile();
            $this->assertTrue(strlen($lastFile) > 0, "Mailer did not return a valid LastFile value");
            $lastFileContents = file_get_contents($lastFile);

            $this->assertContains(
                "Attachments:\n\ttest\"swarm.txt (6 B) http://localhost/attachments/1/",
                $lastFileContents,
                'expected email data'
            );

            $this->assertContains('<a href="http://localhost/attachments/1/">', $lastFileContents);
            $this->assertContains(
                'test&quot;swarm.txt</a> <span style="color: #555555;">(6 B)</span>',
                $lastFileContents
            );
        }
    }

    /**
     * Test edit action with valid data.
     */
    public function testEditActionWithValidData()
    {
        // set active user
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new \Users\Authentication\Adapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $tests = array(
            array(
                'addFlags'      => array('xyz', '123'),
                'result'        => array('xyz', '123'),
            ),
            array(
                'initial'       => null,
                'addFlags'      => array('xyz', '123'),
                'result'        => array('xyz', '123'),
            ),
            array(
                'initial'       => array(),
                'addFlags'      => array('xyz', '123'),
                'result'        => array('xyz', '123'),
            ),
            array(
                'initial'       => array('def'),
                'flags'         => array('abc'),
                'addFlags'      => array('xyz', '123'),
                'result'        => array('abc', 'xyz', '123'),
            ),
            array(
                'initial'       => array('xyz', '123'),
                'removeFlags'   => array('xyz'),
                'result'        => array('123'),
            ),
            array(
                'initial'       => array('abc', 'test', 'foo'),
                'addFlags'      => array('xyz', '123'),
                'removeFlags'   => array('test', 'xyz'),
                'result'        => array('abc', 'foo', '123'),
            ),
            array(
                'initial'       => array('xyz', '123'),
                'addFlags'      => array('xyz'),
                'result'        => array('xyz', '123'),
            ),
            array(
                'initial'       => array('xyz', '123'),
                'removeFlags'   => array('test', 'xyz'),
                'result'        => array('123'),
            ),
        );

        foreach ($tests as $test) {
            $model = new Comment($this->p4);
            $model->set(
                array(
                    'user'      => 'foo',
                    'topic'     => 'test',
                    'body'      => 'a b c'
                )
            );

            if (isset($test['initial'])) {
                $model->setFlags($test['initial']);
            }

            $model->save();

            $commentId = $model->getId();
            $postData  = new Parameters($test);
            $this->getRequest()
                 ->setMethod(\Zend\Http\Request::METHOD_POST)
                 ->setPost($postData);

            // dispatch and check output
            $this->dispatch('/comment/edit/' . $commentId);
            $result = $this->getResult();
            $this->assertRoute('edit-comment');
            $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'edit');
            $this->assertResponseStatusCode(200);
            $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
            $this->assertSame(true, $result->getVariable('isValid'));

            // reset application
            $this->resetApplication();

            $comment = Comment::fetch($commentId, $this->p4);
            $this->assertSame($comment->getFlags(), $test['result']);
        }
    }

    /**
     * Test edit action with valid data.
     */
    public function testEditActionWithBody()
    {
        // set active user
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');
        $mailer   = $services->get('mailer');

        $user = new User($this->p4);
        $user->setId('foo')
            ->setEmail('foo@example.com')
            ->setFullName('Mr Foo')
            ->setPassword('abcd1234')
            ->save();

        $user = new User($this->p4);
        $user->setId('bar')
            ->setEmail('bar@example.com')
            ->setFullName('Mr Foo')
            ->setPassword('abcd1234')
            ->save();

        $job = new Job($this->p4);
        $job->setDescription('test2')
            ->setUser('bar')
            ->save();

        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new \Users\Authentication\Adapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $model = new Comment($this->p4);
        $model->set(
            array(
                'user'      => 'foo',
                'topic'     => 'jobs/' . $job->getId(),
                'body'      => 'a b c',
                'time'      => time() - 300
            )
        )->save();

        $commentId = $model->getId();
        $postData  = new Parameters(array('body' => 'x y z'));
        $this->getRequest()
            ->setMethod(Request::METHOD_POST)
            ->setPost($postData);

        // dispatch and check output
        $this->dispatch('/comment/edit/' . $commentId);
        $result = $this->getResult();
        $this->assertRoute('edit-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertSame(true, $result->getVariable('isValid'));

        $comment = Comment::fetch($commentId, $this->p4);
        $this->assertSame($comment->get('body'), 'x y z');

        $lastFile = $mailer->getLastFile();
        $this->assertSame(null, $lastFile, 'expected empty mail queue');
        $this->assertSame(1, $queue->getTaskCount());

        // process queue and fetch activity
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
        $this->dispatch('/activity');

        // verify basic output
        $data = Json::decode($this->getResponse()->getBody(), true);

        $this->assertRoute('activity');
        $this->assertRouteMatch('activity', 'activity\controller\indexcontroller', 'index');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $this->getResult());

        unset($data['activity'][0]['time']);
        unset($data['activity'][0]['date']);
        unset($data['activity'][0]['avatar']);
        $this->assertSame(
            array(
                'id'             => 2,
                'type'           => 'comment',
                'link'           => array(
                    'job',
                    array(
                        'job' => 'job000001',
                        'fragment' => 'comments'
                    )
                ),
                'user'           => 'foo',
                'action'         => 'edited a comment on',
                'target'         => 'job000001',
                'preposition'    => 'for',
                'description'    => '<span class="first-line">x y z</span>',
                'details'        => array(),
                'topic'          => 'jobs/job000001',
                'depotFile'      => null,
                'behalfOf'       => null,
                'projects'       => array(),
                'followers'      => array(),
                'streams'        => array(
                    0 => 'user-foo',
                    1 => 'personal-foo',
                    2 => 'personal-bar', // that would be a big seller
                ),
                'change'         => null,
                'url'            => '/jobs/job000001#comments',
                'projectList'    => '',
                'userExists'     => true,
                'behalfOfExists' => false,
                'comments'       => array(1, 0),
            ),
            $data['activity'][0]
        );
        $this->assertSame(2, count($data['activity']));

        $this->resetApplication();

        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $lastFile = $mailer->getLastFile();
        $this->assertTrue(strlen($lastFile) > 0, "Mailer did not return a valid LastFile value");
        $lastFileContents = file_get_contents($lastFile);

        $this->assertContains(
            "foo edited a comment on job000001",
            $lastFileContents,
            'expected activity notification for comment editing'
        );
    }

    /**
     * Test comment state transitions.
     */
    public function testTransition()
    {
        // set active user
        $user = new User($this->p4);
        $user->setId('foo')
            ->setEmail('foo@test.com')
            ->setFullName('Mr Foo')
            ->setPassword('abcd1234')
            ->save();

        $auth    = $this->getApplication()->getServiceManager()->get('auth');
        $adapter = new \Users\Authentication\Adapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $model = new Comment($this->p4);
        $model->set(
            array(
                'user'      => 'foo',
                'topic'     => 'test',
                'body'      => 'a b c'
            )
        )->save();

        // basic transition from regular comment to open
        $commentId   = $model->getId();
        $result      = $this->transitionViaController($commentId, 'open');
        $commentData = $result->getVariable('comment');
        $this->assertRoute('edit-comment');
        $this->assertRouteMatch('comments', 'comments\controller\indexcontroller', 'edit');
        $this->assertResponseStatusCode(200);
        $this->assertInstanceOf('Zend\View\Model\JsonModel', $result);
        $this->assertTrue($result->getVariable('isValid'));
        $this->assertSame(Comment::TASK_OPEN, $commentData['taskState']);

        $comment = Comment::fetch($commentId, $this->p4);
        $this->assertSame(Comment::TASK_OPEN, $comment->get('taskState'));

        // now change from open back to comment
        $result      = $this->transitionViaController($commentId, 'comment');
        $commentData = $result->getVariable('comment');
        $this->assertTrue($result->getVariable('isValid'));
        $this->assertSame(Comment::TASK_COMMENT, $commentData['taskState']);
        $comment = Comment::fetch($commentId, $this->p4);
        $this->assertSame(Comment::TASK_COMMENT, $comment->get('taskState'));

        // change from comment to verified (invalid transition)
        $result      = $this->transitionViaController($commentId, 'verified');
        $commentData = $result->getVariable('comment');

        $this->assertFalse($result->getVariable('isValid'));
        $this->assertSame(Comment::TASK_COMMENT, $commentData['taskState']);
        $comment = Comment::fetch($commentId, $this->p4);
        $this->assertSame(Comment::TASK_COMMENT, $comment->get('taskState'));
        $message = $result->getVariable('messages');
        $message = $message['taskState'];
        $this->assertSame(
            'Invalid task state transition specified. Valid transitions are: open',
            $message['callbackValue']
        );

        // set state to resolved, attempt to verify and archive
        $comment->set('taskState', Comment::TASK_ADDRESSED)
                ->save();
        $result      = $this->transitionViaController($commentId, 'verified:archive');
        $commentData = $result->getVariable('comment');
        $this->assertTrue($result->getVariable('isValid'));
        $this->assertSame(Comment::TASK_VERIFIED, $commentData['taskState']);
        $comment = Comment::fetch($commentId, $this->p4);
        $this->assertSame(Comment::TASK_VERIFIED, $comment->get('taskState'));
        $this->assertTrue(in_array('closed', $comment->get('flags')));
    }

    /**
     * Test queue behavior.
     */
    public function testAddQueuesComment()
    {
        // set active user
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        $services = $this->getApplication()->getServiceManager();
        $auth     = $services->get('auth');
        $adapter  = new \Users\Authentication\Adapter('foo', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $post = new Parameters(
            array(
                'user'      => 'foo',
                'topic'     => 'a-b-c',
                'body'      => 'a b c',
                'timestamp' => 123
            )
        );
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost($post);

        // dispatch and check output
        $this->dispatch('/comments/add');

        // ensure comment has been stored
        $this->assertSame(1, count(Comment::fetchAll(array(), $this->p4)));

        // ensure comment was queue
        $queue = $services->get('queue');
        $this->assertSame(1, $queue->getTaskCount());
        $task = $queue->grabTask();
        $this->assertSame('comment', $task['type']);
        $this->assertSame('1',       $task['id']);
    }

    public function testAddRemoveLike()
    {
        // create a comment authored by user with a valid email address
        $user = new User($this->p4);
        $user->setId('foo')
             ->setEmail('foo@test.com')
             ->setFullName('Mr Foo')
             ->setPassword('abcd1234')
             ->save();

        $comment = new Comment($this->p4);
        $comment->set(
            array(
                'user'      => 'foo',
                'topic'     => 'test',
                'body'      => 'a b c'
            )
        )->save();

        // set active user
        $user = new User($this->p4);
        $user->setId('bar')
             ->setEmail('bar@test.com')
             ->setFullName('Mr Bar')
             ->setPassword('abcd1234')
             ->save();

        $services = $this->getApplication()->getServiceManager();
        $auth     = $services->get('auth');
        $adapter  = new \Users\Authentication\Adapter('bar', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        // add like
        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('addLike' => 'bar')));

        $id = $comment->getId();
        $this->dispatch('/comments/edit/' . $id);

        $comment = Comment::fetch($id, $this->p4);
        $this->assertSame(array('bar'), $comment->getLikes());

        // verify that email has been sent to the comment author (foo)
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');

        $mailer   = $this->getApplication()->getServiceManager()->get('mailer');
        $lastFile = $mailer->getLastFile();
        $this->assertTrue(strlen($lastFile) > 0);

        $lastFileContents = file_get_contents($lastFile);
        $this->assertContains(
            "To: foo@test.com",
            $lastFileContents,
            'expected email data (recipient)'
        );
        $this->assertContains(
            "bar liked your comment on test",
            $lastFileContents,
            'expected email data (body)'
        );

        // remove like
        $this->resetApplication();
        $services = $this->getApplication()->getServiceManager();
        $auth     = $services->get('auth');
        $adapter  = new \Users\Authentication\Adapter('bar', 'abcd1234', $this->p4);
        $auth->authenticate($adapter);

        $this->getRequest()
             ->setMethod(\Zend\Http\Request::METHOD_POST)
             ->setPost(new Parameters(array('removeLike' => 'bar')));

        $this->dispatch('/comments/edit/' . $id);

        $comment = Comment::fetch($id, $this->p4);
        $this->assertSame(array(), $comment->getLikes());

        // verify that no email was sent
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
        $mailer = $this->getApplication()->getServiceManager()->get('mailer');
        $this->assertSame(null, $mailer->getLastFile());
    }

    protected function transitionViaController($commentId, $state)
    {
        $postData = new Parameters(array('taskState' => $state));
        $this->getRequest()
            ->setMethod(\Zend\Http\Request::METHOD_POST)
            ->setPost($postData);

        $this->dispatch('/comment/edit/' . $commentId);
        return $this->getResult();
    }
}
