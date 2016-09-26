<?php
/**
 * Perforce Swarm
 *
 * @copyright   2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ImagickTest\Controller;

use ModuleTest\TestControllerCase;
use P4\File\File;

class IndexControllerTest extends TestControllerCase
{
    public function testIndexActionSymlink()
    {
        $p4     = $this->p4;
        $target = BASE_PATH . '/public/index.php';

        // this test uses specific path in the filesystem for the symlink,
        // skip if the path doesn't exist (this should not happen)
        if (!file_exists($target)) {
            $this->markTestSkipped();
        }

        // add file to depot that is a symbolic link
        $file = new File($p4);
        $file->setFilespec('//depot/foo.jpg');
        $file->createLocalPath();
        symlink($target, $file->getLocalFilename());
        $file->add()->submit('test');

        $this->dispatch('/imagick/depot/foo.jpg');

        // if it returns 200, that means it succeeded in invoking ImageMagick (when it should not have)
        $this->assertSame(415, $this->getResponse()->getStatusCode());
    }
}
