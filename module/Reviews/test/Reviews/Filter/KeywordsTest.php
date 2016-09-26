<?php
/**
 * Tests for the review keyword filter.
 *
 * @copyright   2013 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ReviewsTest\Filter;

use ModuleTest\TestControllerCase;
use Reviews\Filter\Keywords;

class KeywordsTest extends TestControllerCase
{
    public function testBasicFunction()
    {
        new Keywords(array());
    }

    public function testWithConfig()
    {
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');
        new Keywords($config['reviews']['patterns']);
    }

    public function descriptionProvider()
    {
        // params are input, output (null/skip if same), extracted id (null/skip if no match expected)
        // note, extracted id should be empty string when a match without ID is expected;
        // null indicates the keyword wasn't present at all.
        return array(
            array("Review [review-1234] test"),
            array("Review [review] test"),
            array("[review-] test"),
            array("[review] test",                  "test",                     ""),
            array("[REVIEW] test",                  "test",                     ""),
            array("[review-1234] test\nmore",       "test\nmore",               "1234"),
            array("[Review-1234] test",             "test",                     "1234"),
            array("  [review-1234]\n\n test",       "test",                     "1234"),
            array("[review-1234] \n test",          "test",                     "1234"),
            array("test [review-1234]",             "test",                     "1234"),
            array("test\n [review-1234] ",          "test",                     "1234"),
            array("test\n\n[review-1234]",          "test",                     "1234"),
            array("test [review]",                  "test",                     ""),
            array("#review test",                   "test",                     ""),
            array("test #review",                   "test",                     ""),
            array("this #review test",              null,                       ""),
            array("#review-1234 test",              "test",                     "1234"),
            array("test #review-1234",              "test",                     "1234"),
            array("this #review-1234 test",         "this #review-1234 test",   "1234"),
            array("this (#review-1234), :)",        "this (#review-1234), :)",  "1234"),
            array("this #review, test",             "this #review, test",       ""),
            array("not #review( "),
            array("not <#review> "),
            array("not #review,words "),
            array("not words(#review,words "),
            array("nota#review here"),
            array("#reviewmiss here"),
            array("nota/#review here"),
            array("nota#review-1234 here"),
            array("#review-1234miss here"),
            array("nota/#review-1234 here"),
            array("nota #review- here"),
            array("#review- miss here")
        );
    }

    /**
     * @dataProvider descriptionProvider
     */
    public function testStripping($input, $output = null)
    {
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');
        $filter   = new Keywords($config['reviews']['patterns']);

        $this->assertSame(
            $output !== null ? $output : $input,
            $filter->filter($input)
        );
    }

    /**
     * @dataProvider descriptionProvider
     */
    public function testExtracting($input, $output = null, $id = null)
    {
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');
        $filter   = new Keywords($config['reviews']['patterns']);

        $matches = $filter->getMatches($input);

        // verify no hits if we didn't expect one (and stop testing in that case)
        if ($id === null) {
            $this->assertSame(
                array(),
                $matches
            );
            return;
        }

        $this->assertTrue(isset($matches['id']));
        $this->assertSame(
            (string) $id,
            $matches['id']
        );
    }

    public function testInserting()
    {
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');
        $filter   = new Keywords($config['reviews']['patterns']);

        // check that the description with no keyword gets a keyword in the default location
        $this->assertSame(
            "This description does not have a keyword.\n\n#review-1234",
            $filter->update('This description does not have a keyword.', array('id' => 1234))
        );

        // check that a description with a keyword is unchanged
        $this->assertSame(
            'This description does not have a keyword. #review-1234',
            $filter->update('This description does not have a keyword. #review-1234', array('id' => 1234))
        );

        // check that no id parameter means no keyword is added
        $this->assertSame(
            'This description does not have a keyword.',
            $filter->update('This description does not have a keyword.', array())
        );

        // check that multiple keywords are updated
        $this->assertSame(
            'This description has multiple keywords #review-1234 #review-1234 #review-1234.',
            $filter->update('This description has multiple keywords #review #review #review.', array('id' => 1234))
        );
    }
}
