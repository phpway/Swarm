<?php
/**
 * Perforce Swarm
 *
 * @copyright   2012 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Controller;

use ModuleTest\TestControllerCase;

class ClickjackingTest extends TestControllerCase
{
    public function testHeader()
    {
        // dispatch and verify output
        $this->dispatch('/about');
        $headers = $this->getResponse()->getHeaders();
        $this->assertTrue((bool) $headers->get('X-Frame-Options'));
        $this->assertSame('X-Frame-Options: SAMEORIGIN', $headers->get('X-Frame-Options')->toString());
    }
}
