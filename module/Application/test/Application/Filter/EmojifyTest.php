<?php
/**
 * Tests for the emojify filter
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\Filter;

use \Application\Filter\Emojify;

class EmojifyTest extends \PHPUnit_Framework_TestCase
{
    const TEST_MAX_TRY_COUNT = 1000;
    public $suffix           = '.outoftheway';
    public $gemoji_path      = '/public/vendor/gemoji';

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

    public function tearDown()
    {
        parent::tearDown();

        # tidy up gemoji structure
        @unlink(BASE_PATH . $this->gemoji_path .  '/images/emoji/neckbeard.png');
        @rmdir(BASE_PATH . $this->gemoji_path . '/images/emoji');
        @rmdir(BASE_PATH . $this->gemoji_path . '/images');
        @rmdir(BASE_PATH . $this->gemoji_path);
    }

    public function testBasic()
    {
        $emojify = new Emojify('');
        $this->assertTrue($emojify instanceof Emojify);

        $converted = $emojify->filter('words :smile: words :+1: words');

        $this->assertSame(
            'words <span class="emoji" title=":smile:">&#x1F604;</span> ' .
            'words <span class="emoji" title=":+1:">&#x1F44D;</span> words',
            $converted
        );
    }

    public function testInTags()
    {
        $emojify   = new Emojify('');
        $input     = 'test <a href="http://foo.com/:x:s">http://foo.com/:x:s</a> test';
        $converted = $emojify->filter($input);
        $this->assertSame(
            $input,
            $converted
        );
    }

    public function testFollowingLink()
    {
        $emojify   = new Emojify('');
        $input     = '<a href="#">test</a> :lipstick:';
        $converted = $emojify->filter($input);
        $this->assertSame(
            '<a href="#">test</a> <span class="emoji" title=":lipstick:">&#x1F484;</span>',
            $converted
        );
    }

    public function testAlmostWrapped()
    {
        $emojify   = new Emojify('');
        $input     = '<a href="#">test</a> :lipstick: <i>foo</i>';
        $converted = $emojify->filter($input);
        $this->assertSame(
            '<a href="#">test</a> <span class="emoji" title=":lipstick:">&#x1F484;</span> <i>foo</i>',
            $converted
        );
    }

    public function testGemoji()
    {
        // fake the existence of a gemoji image
        $this->assertTrue(
            @mkdir(BASE_PATH . $this->gemoji_path . '/images/emoji', 0777, true)
        );
        $this->assertTrue(
            @touch(BASE_PATH . $this->gemoji_path . '/images/emoji/neckbeard.png')
        );

        $emojify   = new Emojify('');
        $converted = $emojify->filter('Geoff has a :neckbeard:');
        $this->assertSame(
            'Geoff has a <img class="emoji" title=":neckbeard:" '
            . 'alt=":neckbeard:" width="18" height="18" '
            . 'src="/vendor/gemoji/images/emoji/neckbeard.png">',
            $converted
        );
    }
}
