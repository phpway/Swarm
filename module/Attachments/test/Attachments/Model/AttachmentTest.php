<?php
/**
 * Perforce Swarm
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Attachments\test\Attachments\Model;

use Attachments\Model\Attachment;
use ModuleTest\TestControllerCase;
use Record\Exception\NotFoundException;

class AttachmentTest extends TestControllerCase
{
    /**
     * Test that exception handling is behaving as expected.
     */
    public function testDeleteWhenDepotFileDeleted()
    {
        $services = $this->getApplication()->getServiceManager();
        // Override the service container to set depot_storage_path
        $config                               = $services->get('config');
        $config['depot_storage']['base_path'] = '//depot/swarm-attachments';
        $services->setService('config', $config);
        $depot = $services->get('depot_storage');
        $services->get('p4_admin')->setService('depot_storage', $depot);
        $attachment = new Attachment($this->p4);
        $attachment->set(
            array(
                'name' => 'test.txt',
                'size' => 4,
                'type' => 'text/plain',
            )
        );

        $tempFile = tempnam(DATA_PATH, 'attachtest');
        file_put_contents($tempFile, 'test');

        $attachment->save($tempFile);

        $attachmentId = $attachment->getId();
        $attachment   = Attachment::fetch($attachmentId, $this->p4);

        $fileSpec = $attachment->get('depotFile');

        // certain conditions might cause $attachment->delete() to fail unexpectedly. Here, we try to simulate one.
        // the call to $depot->delete() that is *inside* $attachment->delete() will fail, but failure
        // should be caught and ignored.
        $depot->delete($fileSpec);
        $attachment->delete();

        // then, when an attempt is made to fetch the attachment record, it shouldn't exist.
        // this verifies that the attachment records are deleted cleanly.
        $attachmentRefetch = false;
        try {
            $attachmentRefetch = Attachment::fetch($attachmentId, $this->p4);
        } catch (NotFoundException $e) {
            if ($e->getMessage() != 'Cannot fetch entry. Id does not exist.') {
                throw $e;
            }
        }

        $this->assertSame(false, $attachmentRefetch);
    }

    public function testDeleteWhenDepotFileNonexistent()
    {
        $services = $this->getApplication()->getServiceManager();
        // Override the service container to set depot_storage_path
        $config                               = $services->get('config');
        $config['depot_storage']['base_path'] = '//depot/swarm-attachments';
        $services->setService('config', $config);
        $depot = $services->get('depot_storage');
        $services->get('p4_admin')->setService('depot_storage', $depot);

        $attachment = new Attachment($this->p4);
        $attachment->set(
            array(
                'name'      => 'test.txt',
                'size'      => 4,
                'type'      => 'text/plain',
                'depotFile' => '//depot/swarm-attachments/test.txt',
            )
        );

        $attachment->save();

        $id         = $attachment->getId();
        $attachment = Attachment::fetch($id, $this->p4);
        $this->assertSame('//depot/swarm-attachments/test.txt', $attachment->get('depotFile'));

        $e = null;
        try {
            $attachment->delete();
        } catch (\Exception $e) {
        }

        $this->assertSame(
            false,
            isset($e),
            'An exception was thrown when deleting an attachment with a nonexistent depot file: ' . $e
        );

        $this->assertSame(
            false,
            Attachment::exists($id, $this->p4),
            'Attachment was not properly deleted from the system!'
        );
    }

    /**
     * @dataProvider filenames
     */
    public function testCleanFilename($filename, $expectedResult)
    {
        $attachment = new Attachment();
        $reflection = new \ReflectionClass('Attachments\Model\Attachment');
        $method     = $reflection->getMethod('cleanFilename');
        $method->setAccessible(true);

        $safeName = $method->invokeArgs($attachment, array($filename));

        $this->assertSame($expectedResult, $safeName, "Attachment filename sanitization failed");
    }

    public function filenames()
    {
        return array(
            array("test.jpg", "test.jpg"),
            array("test...jpg", "test.jpg"),
            array("test   ...jpg", "test-.jpg"),
            array("test..jpg----  ", "test.jpg"),
            array('test@myhouse.jpg', 'test-myhouse.jpg'),
            array('test/myhouse.jpg', 'test-myhouse.jpg'),
            array(
                "Dies ist ein Test. Es ist eine mächtige Test.txt",
                'Dies-ist-ein-Test.-Es-ist-eine-m-chtige-Test.txt'
            ),
            array('test.tar.gz', 'test.tar.gz'),
        );
    }
}
