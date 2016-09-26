<?php
/**
 * Tests for the short-link model.
 *
 * @copyright   2014 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ShortLinkTest\Model;

use P4Test\TestCase;
use ShortLinks\Model\ShortLink;

class ShortLinkTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        \Zend\Loader\AutoloaderFactory::factory(
            array(
                 'Zend\Loader\StandardAutoloader' => array(
                     'namespaces' => array(
                         'ShortLinks' => BASE_PATH . '/module/ShortLinks/src/ShortLinks'
                     )
                 )
            )
        );
    }

    public function testBasicSaveAndFetch()
    {
        $link = new ShortLink($this->p4);
        $link->set('uri', 'test-uri-value-1')->save();
        $this->assertSame(1, $link->getId());
        $link = ShortLink::fetch(1, $this->p4);
        $this->assertSame($link->get('uri'), 'test-uri-value-1');
        $link = ShortLink::fetchByUri('test-uri-value-1', $this->p4);
        $this->assertSame($link->getId(), 1);
    }

    public function testIdObfuscation()
    {
        $this->assertSame('3d41',        ShortLink::obfuscateId('1',          'http://perforce.com'));
        $this->assertSame('bto2',        ShortLink::obfuscateId('2',          'http://google.com'));
        $this->assertSame('x252s',       ShortLink::obfuscateId('100',        'http://apple.com'));
        $this->assertSame('73kzik0zj',   ShortLink::obfuscateId('2147483647', 'http://twitter.com'));
    }

    public function testIdClarification()
    {
        $this->assertSame('1',          ShortLink::clarifyId('...1'));
        $this->assertSame('2',          ShortLink::clarifyId('...2'));
        $this->assertSame('100',        ShortLink::clarifyId('...2s'));
        $this->assertSame('2147483647', ShortLink::clarifyId('...zik0zj'));
    }

    public function testSaveAndFetch()
    {
        $link = new ShortLink($this->p4);
        $link->set('uri', 'test-uri-value-1')->save();
        $this->assertSame(1, $link->getId());
        $this->assertSame('cvo1', $link->getObfuscatedId());

        // manually bump the counter...
        $this->p4->run('counter', array('-u', ShortLink::KEY_COUNT, 2147483646));

        $link = new ShortLink($this->p4);
        $link->set('uri', 'test-uri-value-2')->save();
        $this->assertSame(2147483647, $link->getId());
        $this->assertSame('51azik0zj', $link->getObfuscatedId());

        // now go the other way (from storage)
        $link = ShortLink::fetchByObfuscatedId('cvo1', $this->p4);
        $this->assertSame($link->get('uri'), 'test-uri-value-1');
        $link = ShortLink::fetchByObfuscatedId('51azik0zj', $this->p4);
        $this->assertSame($link->get('uri'), 'test-uri-value-2');
    }

    public function testFetchByUri()
    {
        $link = new ShortLink($this->p4);
        $link->set('uri', '/some/path/to/lookup')->save();
        $link = new ShortLink($this->p4);
        $link->set('uri', 'http://some-external/link/to/thing')->save();

        $link = ShortLink::fetchByUri('/some/path/to/lookup', $this->p4);
        $this->assertSame('en31', $link->getObfuscatedId());

        $link = ShortLink::fetchByUri('http://some-external/link/to/thing', $this->p4);
        $this->assertSame('1b02', $link->getObfuscatedId());
    }

    public function testUriEncoding()
    {
        $link = new ShortLink($this->p4);

        // a simple uri should see no ill effects
        $this->assertSame(
            'http://apple.com',
            $link->set('uri', 'http://apple.com')->get('uri')
        );

        // a properly encoded uri should be untouched
        $this->assertSame(
            'http://apple.com/foo%20bar',
            $link->set('uri', 'http://apple.com/foo%20bar')->get('uri')
        );

        // this uri needs more encoding
        $this->assertSame(
            'http://perforce.com/spaces%20in%20path',
            $link->set('uri', 'http://perforce.com/spaces in path')->get('uri')
        );

        // partially encoded
        $this->assertSame(
            'http://perforce.com/%25zztest%20what/',
            $link->set('uri', 'http://perforce.com/%zztest what/')->get('uri')
        );
    }
}
