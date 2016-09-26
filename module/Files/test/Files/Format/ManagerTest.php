<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace FilesTest\Format;

use Files;
use P4Test\TestCase;
use Zend\Http\Request;

class ManagerTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                'Zend\Loader\StandardAutoloader' => array(
                    'namespaces' => array(
                        'Files' => BASE_PATH . '/module/Files/src/Files'
                    )
                )
            )
        );
    }

    public function testBasic()
    {
        $manager = new Files\Format\Manager;
        $this->assertTrue($manager instanceof Files\Format\Manager);
        $this->assertFalse($manager->canPreview(new \P4\File\File, new Request));
        try {
            $manager->renderPreview(new \P4\File\File, new Request);
            $this->fail("Unexpected success rendering with no handlers");
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
    }

    public function testHandler()
    {
        $file = new \P4\File\File($this->p4);
        $file->setFilespec('//depot/foo')->setLocalContents('test')->add()->submit('test');
        $manager = new Files\Format\Manager;
        $handler = new Files\Format\Handler;
        $handler->setCanPreviewCallback(
            function () {
                return true;
            }
        );
        $handler->setRenderPreviewCallback(
            function () {
                return "preview";
            }
        );

        $manager->addHandler($handler);
        $this->assertSame(array($handler), $manager->getHandlers());

        $manager->setHandlers(null);
        $this->assertSame(array(), $manager->getHandlers());

        $manager->setHandlers(array($handler));
        $this->assertSame(array($handler), $manager->getHandlers());

        $this->assertTrue($manager->canPreview($file, new Request));
        $this->assertSame("preview", $manager->renderPreview($file, new Request));
    }
}
