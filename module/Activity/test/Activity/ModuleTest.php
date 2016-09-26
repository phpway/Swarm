<?php
/**
 * Tests for the activity module.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ActivityTest;

use Activity\Model\Activity;
use Comments\Model\Comment;
use ModuleTest\TestControllerCase;
use P4\File\File;
use P4\Spec\Change;

class ModuleTest extends TestControllerCase
{
    /**
     * Verify that activity record related to a change is updated when that change is submitted.
     */
    public function testUpdateChangeFieldAfterCommit()
    {
        $services = $this->getApplication()->getServiceManager();
        $queue    = $services->get('queue');

        // create 2 pending changes to test with
        $change1 = new Change($this->p4);
        $change1->save(); // id 1
        $change2 = new Change($this->p4);
        $change2->save(); // id 2

        // add a comment to change 1
        $comment = new Comment($this->p4);
        $comment->set(
            array(
                'topic' => 'changes/1',
                'user'  => 'joe',
                'body'  => 'test'
            )
        )->save();

        // add comment task and process the queue
        $queue->addTask('comment', $comment->getId(), array('current' => $comment->get()));
        $this->processQueue();

        // verify that activity record for the comment has been created and the
        // 'change' field value was set
        $activity = Activity::fetchAll(array(), $this->p4)->first();
        $this->assertSame(1, $activity->getId());
        $this->assertSame('comment', $activity->get('type'));
        $this->assertSame(1, $activity->get('change'));

        // submit change 1, it will become change 3
        $file = new File;
        $file->setFilespec('//depot/testfile')->open()->setLocalContents('abc789');
        $change1->addFile($file)->submit();
        $changeId = $change1->getId(); // 3

        // add change commit task and process the queue
        $queue->addTask('commit', $changeId);
        $this->processQueue();

        // verify that:
        // 1. new activity record for the change 3 has been created and the 'change'
        //    filed was properly set
        // 2. change field on activity 1 record has been updated with the new change id
        $activity = Activity::fetch(2, $this->p4);
        $this->assertSame('change', $activity->get('type'));
        $this->assertSame(3, $activity->get('change'));

        $activity = Activity::fetch(1, $this->p4);
        $this->assertSame(3, $activity->get('change'));
    }

    protected function processQueue()
    {
        $this->getRequest()->getQuery()->set('debug', 1)->set('retire', 1);
        $this->dispatch('/queue/worker');
    }
}
