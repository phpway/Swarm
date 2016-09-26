<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ReviewsTest\Model;

use P4Test\TestCase;
use Reviews\Model\FileInfo;
use Reviews\Model\Review;

class FileInfoTest extends TestCase
{
    /**
     * Extend parent to additionally init modules we will use.
     */
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Reviews' => BASE_PATH . '/module/Reviews/src/Reviews',
                    )
                )
            )
        );
    }

    /**
     * Test model creation.
     */
    public function testBasicFunction()
    {
        new FileInfo($this->p4);
    }

    /**
     * Test saving, fetching
     */
    public function testSaveAndFetch()
    {
        $fileInfo = new FileInfo($this->p4);
        $fileInfo->set('review', '123')
                ->set('depotFile', '//depot/foo')
                ->save();

        $id = '123-' . md5('//depot/foo');
        $this->assertSame($fileInfo->getId(), $id);

        $key = $this->p4->run('counters', array('-u', '-e', 'swarm-fileInfo-*'))->getData(0);
        $this->assertSame($key['counter'], 'swarm-fileInfo-' . $id);

        $fileInfo = FileInfo::fetch($id, $this->p4);
        $this->assertSame($fileInfo->get('review'), '123');
        $this->assertSame($fileInfo->get('depotFile'), '//depot/foo');
    }

    /**
     * Test bulk fetch for a given review
     */
    public function testFetchByReview()
    {
        for ($i = 0; $i < 3; $i++) {
            $fileInfo = new FileInfo($this->p4);
            $fileInfo->set('review', '123')
                     ->set('depotFile', '//depot/foo' . $i)
                     ->save();
        }

        $results = FileInfo::fetchAll(array(FileInfo::FETCH_BY_REVIEW => '123'), $this->p4);
        $this->assertSame(count($results), 3);
        $this->assertSame(
            $results->sortBy('depotFile')->invoke('get', array('depotFile')),
            array('//depot/foo0', '//depot/foo1', '//depot/foo2')
        );

        // try using the convenience method
        $review = new Review($this->p4);
        $review->setId('123');
        $results = FileInfo::fetchAllByReview($review, $this->p4);
        $this->assertSame(count($results), 3);
        $this->assertSame(
            $results->sortBy('depotFile')->invoke('get', array('depotFile')),
            array('//depot/foo0', '//depot/foo1', '//depot/foo2')
        );
    }

    /**
     * Test get/setReadBy
     */
    public function testGetSetReadBy()
    {
        $fileInfo = new FileInfo($this->p4);

        // properly formed input
        $readBy = array(
            'jdoe' => array('version' => 3, 'digest' => strtoupper(md5('bar'))),
            'pat'  => array('version' => 1, 'digest' => strtoupper(md5('foo'))),
        );
        $fileInfo->setReadBy($readBy);
        $this->assertSame($fileInfo->getReadBy(), $readBy);

        // empty digests occur on deleted files
        // ensure set allows them and converts empty strings to null
        $readBy = array(
            'jdoe' => array('version' => 3, 'digest' => ''),
            'pat'  => array('version' => 1, 'digest' => null),
        );
        $fileInfo->setReadBy($readBy);
        $readBy['jdoe']['digest'] = null;
        $this->assertSame($fileInfo->getReadBy(), $readBy);

        // null is ok too
        $fileInfo->setReadBy(null);
        $this->assertSame($fileInfo->getReadBy(), array());

        // try with bad input
        try {
            $readBy = array(
                'pat' => array('version' => 1, 'digest' => strtoupper(md5('foo'))),
                1     => array('version' => 3, 'digest' => strtoupper(md5('bar'))),
            );

            $fileInfo->setReadBy($readBy);
            $this->assertFalse(true);
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    public function testMarkClearIsReadBy()
    {
        $fileInfo = new FileInfo($this->p4);

        $fileInfo->markReadBy('pat',  1, strtoupper(md5('foo')));
        $fileInfo->markReadBy('jdoe', 3, strtoupper(md5('bar')));
        $fileInfo->markReadBy('bob',  1, '');

        // is read by knows about version and digest
        $this->assertTrue($fileInfo->isReadBy('pat', 1, md5('foo')));
        $this->assertTrue($fileInfo->isReadBy('pat', 2, md5('foo')));
        $this->assertTrue($fileInfo->isReadBy('pat', 0, md5('bar')));
        $this->assertFalse($fileInfo->isReadBy('bob', 1, md5('foo')));
        $this->assertFalse($fileInfo->isReadBy('pat', 1, md5('bar')));
        $this->assertFalse($fileInfo->isReadBy('pat', 2, md5('bar')));

        // empty digests that match also slide forward (e.g. deleted files)
        $this->assertTrue($fileInfo->isReadBy('bob', 1, ''));
        $this->assertTrue($fileInfo->isReadBy('bob', 1, null));
        $this->assertFalse($fileInfo->isReadBy('bob', 1, md5('woozle')));
        $this->assertTrue($fileInfo->isReadBy('bob', 2, ''));
        $this->assertTrue($fileInfo->isReadBy('bob', 3, null));

        $fileInfo->clearReadBy('pat');
        $this->assertFalse($fileInfo->isReadBy('pat', 1, md5('foo')));
    }
}
