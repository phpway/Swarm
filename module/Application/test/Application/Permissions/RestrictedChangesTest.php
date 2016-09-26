<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Permissions;

use Application\Permissions\RestrictedChanges;
use P4\File\File;
use P4\Spec\Change;
use P4Test\TestCase;

class RestrictedChangesTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                 'Zend\Loader\StandardAutoloader' => array(
                     'namespaces' => array(
                         'Application' => BASE_PATH . '/module/Application/src/Application'
                     )
                 )
            )
        );
    }

    public function testBasic()
    {
        $filter = new RestrictedChanges($this->p4);
        $this->assertTrue($filter instanceof RestrictedChanges);

        // by default, non-existing changes are filtered out
        $this->assertSame(
            array(),
            $filter->filter(
                array(
                    array('change' => 1),
                    array('change' => 2),
                    array('change' => 3)
                ),
                'change'
            )
        );
    }

    public function testFilter()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create few changes to test with
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #1'); // NOT accessible by 'foo'
        $id1 = $change->getId();

        $file = new File;
        $file->setFilespec('//depot/foo/test3')->open()->setLocalContents('def');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #2'); // accessible by 'foo'
        $id2 = $change->getId();

        $file = new File;
        $file->setFilespec('//depot/test2')->open()->setLocalContents('ghi');
        $change = new Change($this->p4);
        $change->setType(Change::PUBLIC_CHANGE)->addFile($file)->submit('public'); // accessible by 'foo'
        $id3 = $change->getId();

        $ids = array(
            array('change' => $id1),
            array('change' => $id2),
            array('change' => $id3)
        );

        // test filter with admin connection
        $filter = new RestrictedChanges($this->p4);
        $this->assertSame(3, count($filter->filter($ids, 'change')));

        // test with 'foo' connection
        $filter   = new RestrictedChanges($p4Foo);
        $expected = array();
        foreach ($filter->filter($ids, 'change') as $item) {
            $expected[] = current($item);
        }

        $this->assertSame(2, count($expected));
        $this->assertTrue(in_array("$id2", $expected));
        $this->assertTrue(in_array("$id3", $expected));
    }

    public function testCanAccess()
    {
        $p4Foo = $this->connectWithAccess('foo', array('//depot/foo/...'));

        // create few changes to test with
        $file = new File;
        $file->setFilespec('//depot/test1')->open()->setLocalContents('abc');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #1'); // NOT accessible by 'foo'
        $id1 = $change->getId();

        $file = new File;
        $file->setFilespec('//depot/foo/test3')->open()->setLocalContents('def');
        $change = new Change($this->p4);
        $change->setType(Change::RESTRICTED_CHANGE)->addFile($file)->submit('restricted #2'); // accessible by 'foo'
        $id2 = $change->getId();

        $file = new File;
        $file->setFilespec('//depot/test2')->open()->setLocalContents('ghi');
        $change = new Change($this->p4);
        $change->setType(Change::PUBLIC_CHANGE)->addFile($file)->submit('public'); // accessible by 'foo'
        $id3 = $change->getId();

        // test access with admin connection
        $filter = new RestrictedChanges($this->p4);
        $this->assertTrue($filter->canAccess($id1));
        $this->assertTrue($filter->canAccess($id2));
        $this->assertTrue($filter->canAccess($id3));

        // test with 'foo' connection
        $filter = new RestrictedChanges($p4Foo);
        $this->assertFalse($filter->canAccess($id1));
        $this->assertTrue($filter->canAccess($id2));
        $this->assertTrue($filter->canAccess($id3));
    }
}
