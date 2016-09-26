<?php
/**
 * Tests for the Translator service
 *
 * @copyright   2015 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace ApplicationTest\I18n;

use Application\Validator\Callback;
use ModuleTest\TestControllerCase;
use Zend\InputFilter\InputFilter;
use Zend\Validator\AbstractValidator;
use Zend\View\Model\ViewModel;
use Zend\View\Resolver\TemplatePathStack;

class TranslatorTest extends TestControllerCase
{
    public function setUp()
    {
        parent::setUp();

        $this->reconfigureTranslator(
            array(
                'locale'                    => 'en_US',
                'event_manager_enabled'     => true,
                'translation_file_patterns' => array(
                    array(
                        'type'     => 'gettext',
                        'base_dir' => BASE_PATH . '/tests/phpunit/ModuleTest/assets/language',
                        'pattern'  => '%s/default.mo',
                    ),
                    array(
                        'type'        => 'gettext',
                        'base_dir'    => BASE_PATH . '/tests/phpunit/ModuleTest/assets/language',
                        'pattern'     => '%s/toolbar.mo',
                        'text_domain' => 'toolbar'
                    ),
                )
            )
        );
    }

    /**
     * @dataProvider translationProvider
     */
    public function testTranslation($type, $expected, $token, array $replacements = null, $context = null)
    {
        $this->assertSame($expected, $this->getTranslator()->$type($token, $replacements, $context));
    }

    /**
     * @dataProvider pluralTranslationProvider
     */
    public function testPluralTranslation(
        $type,
        $expected,
        $singularToken,
        $pluralToken,
        $number,
        array $replacements = null,
        $context = null
    ) {
        $this->assertSame(
            $expected,
            $this->getTranslator()->$type($singularToken, $pluralToken, $number, $replacements, $context)
        );
    }

    public function testContextTranslation()
    {
        $translator = $this->getTranslator();

        // original translate()
        $this->assertSame('my my my sharona', $translator->translate("customcontext" . "\x04" . "my sharona"));
        $this->assertSame('oh mickey you\'re so fine', $translator->translate("customcontext" . "\x04" . "hey mickey"));

        // extra-crispy translateReplace()
        $this->assertSame('my my my sharona', $translator->translateReplace("my sharona", null, "customcontext"));
        $this->assertSame(
            'oh mickey you\'re so fine',
            $translator->translateReplace("hey mickey", null, "customcontext")
        );

        // double-down t()
        $this->assertSame('my my my sharona', $translator->t("my sharona", null, "customcontext"));
        $this->assertSame(
            'oh mickey you\'re so fine',
            $translator->t("hey mickey", null, "customcontext")
        );
    }

    public function testCustomTextDomain()
    {
        $translator = $this->getTranslator();

        // original
        $this->assertSame('Tool Bar', $translator->translate("toolbar", 'toolbar'));

        // translateReplace-style
        $this->assertSame('Tool Bar', $translator->translateReplace("toolbar", null, null, 'toolbar'));
    }

    public function testFallbackContext()
    {
        $translator = $this->getTranslator();

        $this->assertSame(
            'TRANSLATION WAS SUCCESSFUL',
            $translator->translate('customcontext' . "\x04" . 'SUCCESSFUL TRANSLATION')
        );

        $this->assertSame(
            'TRANSLATION WAS SUCCESSFUL',
            $translator->translateReplace('SUCCESSFUL TRANSLATION', null, 'customcontext')
        );

        $this->assertSame(
            'Fallback Works',
            $translator->translateReplace('Fallback Works', null, 'customcontext')
        );

        $this->assertSame(
            'wtf',
            $translator->translate("\x04\x04\x04\x04wtf")
        );

        $this->assertSame(
            "\x04",
            $translator->translate("\x04\x04\x04\x04")
        );
    }

    public function testLocaleDetection()
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.8';
        $translator                      = $this->getTranslator();
        $this->assertSame('en_US', $translator->getLocale());
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testLanguagePatternGuessing()
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-AU,en;q=0.8';
        $translator                      = $this->getTranslator();
        $this->assertSame('en_US', $translator->getLocale());
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testLanguageFileGuessing()
    {
        $this->reconfigureTranslator(
            array(
                'translation_files'         => array(
                    array(
                        'type'        => 'gettext',
                        'filename'    => BASE_PATH . '/tests/phpunit/ModuleTest/assets/language/en_US/default.mo',
                        'locale'      => 'en_US',
                    )
                )
            )
        );

        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-AU,en;q=0.8';
        $translator                      = $this->getTranslator();
        $this->assertSame('en_US', $translator->getLocale());
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    public function testLanguageFallbackOrder()
    {
        $translator = $this->getTranslator();
        $translator->setLocale('ja_JP');
        $translator->setFallbackLocale('en_US');

        $this->assertSame('domo arigato',   $translator->t('japanese string'));
        $this->assertSame('domo arigato',   $translator->t('japanese string', null, 'customcontext'));
        $this->assertSame('found in en_US', $translator->t('english fallback'));
        $this->assertSame('no translation', $translator->t('no translation'));
    }

    public function testBrokenConfiguration()
    {
        $this->reconfigureTranslator(array());
        $translator = $this->getTranslator();

        $this->assertSame('en_US', $translator->getLocale());
        $this->assertSame('en_US', $translator->getFallbackLocale());

        // Because we blanked out the configuration, it doesn't know where the translation files are
        // Let's verify that it fails out in the way we expect (that is, no fatal errors/exceptions)
        $this->assertSame('SUCCESSFUL TRANSLATION', $translator->translate('SUCCESSFUL TRANSLATION'));
        $this->assertSame(
            'SUCCESSFUL TRANSLATION',
            $translator->translateReplace('SUCCESSFUL TRANSLATION', null, 'customcontext')
        );
    }

    public function testValidatorPrintf()
    {
        // basic printf version
        $validator = new Callback;
        $validator->setCallback(
            function ($value) {
                return 'Test %s';
            }
        );
        $this->assertFalse($validator->isValid('testing'));
        $messages = $validator->getMessages();
        $this->assertSame(array('Test testing'), array_values($messages));

        // truncated version
        $validator = new Callback;
        $validator->setCallback(
            function ($value) {
                return 'Test Test Test Test %s';
            }
        );
        $validator->setMessageLength(10);

        $this->assertFalse($validator->isValid('testing'));
        $messages = $validator->getMessages();
        $this->assertSame(array('Test Te...'), array_values($messages));
        $validator->setMessageLength(-1);

        // multiple replacements with positional syntax
        $validator = new Callback(
            array(
                'callback' => function ($value) {
                    return 'Valid Test Values: "%2$s", but received %1$s';
                },
                'messageVariables' => array('transitions' => array('open', 'closed'))
            )
        );

        $this->assertFalse($validator->isValid('testing'));
        $messages = $validator->getMessages();
        $this->assertSame(array('Valid Test Values: "open, closed", but received testing'), array_values($messages));

        // multiple replacements with positional syntax AND old-style value replacement
        $validator = new Callback(
            array(
                'callback' => function ($value) {
                    return 'Valid Test Values: "%2$s", but received %value%';
                },
                'messageVariables' => array('transitions' => array('open', 'closed'))
            )
        );

        $this->assertFalse($validator->isValid('testing'));
        $messages = $validator->getMessages();
        $this->assertSame(array('Valid Test Values: "open, closed", but received testing'), array_values($messages));
    }

    /**
     * @dataProvider languageStringProvider
     */
    public function testBadLanguageString($language, $token, $translatedToken, $locale, $fallbackLocale)
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $language;
        $translator                      = $this->getTranslator();
        $this->assertSame($translatedToken, $translator->translate($token));
        $this->assertsame($locale, $translator->getLocale());
        $this->assertsame($fallbackLocale, $translator->getFallbackLocale());
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    /**
     * @dataProvider viewHelperProvider
     */
    public function testViewHelper($template, $expected)
    {
        $filename = 'translated.phtml';
        file_put_contents(DATA_PATH . '/' . $filename, $template);
        $services = $this->getApplication()->getServiceManager();
        $renderer = clone $services->get('ViewManager')->getRenderer();
        $resolver = new TemplatePathStack;
        $resolver->addPaths(array(DATA_PATH));
        $renderer->setResolver($resolver);
        $viewModel = new ViewModel;
        $viewModel->setTemplate($filename);
        $result = $renderer->render($viewModel);
        $this->assertSame($expected, $result);
        unlink(DATA_PATH . '/' . $filename);
    }

    public function testDefaultValidatorTranslator()
    {
        $this->assertInstanceOf('Application\I18n\Translator', AbstractValidator::getDefaultTranslator());
    }

    public function testValidators()
    {
        $this->reconfigureTranslator(
            array(
                'locale'                    => 'fr',
                'event_manager_enabled'     => true,
                'translation_file_patterns' => array(
                    array(
                        'type'     => 'gettext',
                        'base_dir' => BASE_PATH . '/tests/phpunit/ModuleTest/assets/language',
                        'pattern'  => '%s/default.mo',
                    ),
                    array(
                        'type'     => 'phparray',
                        'base_dir' => BASE_PATH . '/tests/phpunit/ModuleTest/assets/language/',
                        'pattern'  => '%s/Zend_Validate.php',
                    )
                )
            )
        );

        $translator = $this->getTranslator();
        AbstractValidator::setDefaultTranslator($translator);
        $inputFilter = new InputFilter;

        $inputFilter->add(
            array(
                'name'      => 'topic',
                'required'  => true
            )
        );

        $inputFilter->add(
            array(
                'name' => 'flags',
                'validators' => array(array('name' => '\Application\Validator\IsArray'))
            )
        );

        $inputFilter->add(
            array(
                'name' => 'counter',
                'validators' => array(
                    array(
                        'name' => 'LessThan',
                        'options' => array(
                            'max' => 5
                        ),
                    ),
                ),
            )
        );

        $inputFilter->add(
            array(
                'name'       => 'foo',
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                return 'bar';
                            }
                        )
                    )
                )
            )
        );

        $inputFilter->add(
            array(
                'name'       => 'baz',
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                return 'bar %value%';
                            }
                        )
                    )
                )
            )
        );

        $inputFilter->add(
            array(
                'name'       => 'taskState',
                'validators' => array(
                    array(
                        'name'      => '\Application\Validator\Callback',
                        'options'   => array(
                            'callback'  => function ($value) {
                                $transitions = array('open', 'closed');
                                if (!in_array($value, $transitions, true)) {
                                    return 'Invalid task state transition specified. '
                                    . 'Valid transitions are: %transitions%';
                                }
                                return true;
                            },
                            'messageVariables' => array(
                                'transitions' => array('open', 'closed'),
                            ),
                        )
                    )
                )
            )
        );

        $inputFilter->add(
            array(
                'name'          => 'name',
                'validators'    => array(
                    array(
                        'name'      => 'NotEmpty',
                        'options'   => array(
                            'message'   =>  "bar"
                        )
                    )
                )
            )
        );

        $inputFilter->setData(
            array('flags' => 'test', 'counter' => 10, 'taskState' => 'approved', 'foo' => 'testing', 'baz' => 'five')
        );

        $this->assertFalse($inputFilter->isValid());
        $messages = $inputFilter->getMessages();
        $this->assertSame(
            array(
                'topic' => array('isEmpty'  => "Une valeur est requise et ne peut être vide"),
                'flags' => array('invalid'  => "Le mauvais cours désiré"),
                'counter' => array('notLessThan' => "L'entrée n'est pas inférieure à '5'"),
                'foo' => array('callbackValue' => 'la barrette'),
                'baz' => array('callbackValue' => 'les five barrettes'),
                'taskState' => array(
                    'callbackValue' => "Le chef des modifications illégales. "
                                     . "De manière fermée et ouverte: open, closed."
                ),
                'name' => array('isEmpty' => 'la barrette')
            ),
            $messages
        );
    }

    public function viewHelperProvider()
    {
        return array(
            'simple_case' => array(
                '<h1><?php echo $this->t("SUCCESSFUL TRANSLATION") ?></h1>',
                '<h1>TRANSLATION WAS SUCCESSFUL</h1>'
            ),
            'fallthrough_case' => array(
                '<h1><?php echo $this->t("FALLTHROUGH TRANSLATION") ?></h1>',
                '<h1>FALLTHROUGH TRANSLATION</h1>'
            ),
            'nonexistent_case' => array(
                '<h1><?php echo $this->t("NONEXISTENT TRANSLATION") ?></h1>',
                '<h1>NONEXISTENT TRANSLATION</h1>'
            ),
            'replacement_case' => array(
                '<h1><?php echo $this->t("REPL_TRANS", array("TRANSLATION", "REPLACED")) ?></h1>',
                '<h1>REPLACED TRANSLATION</h1>'
            ),
            'escacped_replacement_case' => array(
                '<h1><?php echo $this->t("REPL_TRANS", array("TRANS<b>L</b>ATION", "REPLACED")) ?></h1>',
                '<h1>REPLACED TRANS&lt;b&gt;L&lt;/b&gt;ATION</h1>'
            ),
            'context_case' => array(
                '<h1><?php echo $this->t("my sharona", array(), "customcontext") ?></h1>',
                '<h1>my my my sharona</h1>'
            ),
            'singular_case' => array(
                '<h1><?php echo $this->tp("%d orange", "%d oranges", 1) ?></h1>',
                '<h1>1 orange</h1>'
            ),
            'plural_case' => array(
                '<h1><?php echo $this->tp("%d orange", "%d oranges", 5) ?></h1>',
                '<h1>5 Florida Oranges</h1>'
            ),
            'singular_with_replacement' => array(
                '<p><?php echo $this->tp("SINGULAR_REPL_TRANS", "PLURAL_REPL_TRANS", 5, array(5, "green")) ?></p>',
                '<p>5 green grapes</p>'
            ),
            'singular_with_escaped_replacement_arguments' => array(
                '<p><?php echo $this->tp("SINGULAR_REPL_TRANS","PLURAL_REPL_TRANS",5,array(5,"<b>green</b>")) ?></p>',
                '<p>5 &lt;b&gt;green&lt;/b&gt; grapes</p>'
            ),
            'double_case' => array(
                '<h1><?php echo $this->t("SUCCESSFUL TRANSLATION") ?> - '
                . '<?php echo $this->t("my sharona", null, "customcontext") ?></h1>',
                '<h1>TRANSLATION WAS SUCCESSFUL - my my my sharona</h1>'
            ),
            'double_escape_case' => array(
                '<h1><?php echo $this->t("SUCCESSFUL TRANSLATION") ?> - '
                . '<?php echo $this->te("my sharona", null, "customcontext") ?></h1>',
                '<h1>TRANSLATION WAS SUCCESSFUL - my my my sharona</h1>'
            ),
            'escape_case' => array(
                '<p><?php echo $this->te("REPL_TRANS_ESC", array("TWO", "ONE")) ?></p>',
                '<p>&lt;blink&gt;ONE TWO&lt;/blink&gt;</p>'
            ),
            'no_escape_case' => array(
                '<p><?php echo $this->t("REPL_TRANS_ESC", array("TWO", "ONE")) ?></p>',
                '<p><blink>ONE TWO</blink></p>'
            ),
            'escape_arguments_case' => array(
                '<p><?php echo $this->te("REPL_TRANS_ESC", array("<b>TWO</b>", "ONE")) ?></p>',
                '<p>&lt;blink&gt;ONE &lt;b&gt;TWO&lt;/b&gt;&lt;/blink&gt;</p>'
            ),
            'singular_with_escaped_replacement' => array(
                '<p><?php echo $this->tpe("SINGULAR_REPL_TRANS_ESC", "PLURAL_REPL_TRANS_ESC", 5, array(5, "green")) ?>'
                . '</p>',
                '<p>&lt;marquee&gt;5 green grapes&lt;/marquee&gt;</p>'
            ),
        );
    }

    public function languageStringProvider()
    {
        return array(
            array(
                'en_GIBBERISH,en;q=0.8',
                'SUCCESSFUL TRANSLATION',
                'TRANSLATION WAS SUCCESSFUL',
                'en_US', // we expect the proper locale to be guessed
                'en_US'
            ),
            array(
                'en_GB,en;q=0.8',
                'SUCCESSFUL TRANSLATION',
                'TRANSLATION WAS SUCCESSFUL',
                'en_US', // we expect the proper locale to be guessed
                'en_US'
            ),
            array(
                'ja_JAPANESE',  // invalid japanese language string
                'japanese string',
                'domo arigato',
                'ja_JP', // we expect the proper locale to be guessed
                'en_US'
            ),
            array(
                'ja_JP',
                'japanese string',
                'domo arigato',
                'ja_JP',
                'en_US'
            ),
        );
    }

    public function translationProvider()
    {
        return array(
            array(
                'translateReplace',
                'TRANSLATION WAS SUCCESSFUL',
                'SUCCESSFUL TRANSLATION'
            ),
            array(
                'translateReplace',
                'FALLTHROUGH TRANSLATION',
                'FALLTHROUGH TRANSLATION'
            ),
            array(
                'translateReplace',
                'REPLACEMENT TRANSLATION',
                'REPL_TRANS',
                array('TRANSLATION', 'REPLACEMENT')
            ),
            array(
                'translateReplaceEscape',
                '&lt;blink&gt;REPLACEMENT TRANSLATION&lt;/blink&gt;',
                'REPL_TRANS_ESC',
                array('TRANSLATION', 'REPLACEMENT')
            )
        );
    }

    public function pluralTranslationProvider()
    {
        return array(
            array(
                'translatePluralReplace',
                '1 not found singular',
                '%d not found singular',
                '%d not found plurals',
                1
            ),
            array(
                'translatePluralReplace',
                '10 not found plurals',
                '%d not found singular',
                '%d not found plurals',
                10
            ),
            array(
                'translatePluralReplace',
                '1 sour grape',
                'SINGULAR_REPL_TRANS',
                'PLURAL_REPL_TRANS',
                1,
                array(1, 'sour')
            ),
            array(
                'translatePluralReplace',
                '5 sour grapes',
                'SINGULAR_REPL_TRANS',
                'PLURAL_REPL_TRANS',
                5,
                array(5, 'sour')
            ),
            array(
                'translatePluralReplace',
                '1 orange',
                '%d orange',
                '%d oranges',
                1,
            ),
            array(
                'translatePluralReplace',
                '2 Florida Oranges',
                '%d orange',
                '%d oranges',
                2,
            ),
            array(
                'translatePluralReplaceEscape',
                '&lt;marquee&gt;1 sour grape&lt;/marquee&gt;',
                'SINGULAR_REPL_TRANS_ESC',
                'PLURAL_REPL_TRANS_ESC',
                1,
                array(1, 'sour')
            ),
            array(
                'translatePluralReplaceEscape',
                '&lt;marquee&gt;5 sour grapes&lt;/marquee&gt;',
                'SINGULAR_REPL_TRANS_ESC',
                'PLURAL_REPL_TRANS_ESC',
                5,
                array(5, 'sour')
            ),
        );
    }

    protected function getTranslator()
    {
        return $this->getApplication()->getServiceManager()->get('translator');
    }

    protected function reconfigureTranslator(array $translatorConfig)
    {
        $services = $this->getApplication()->getServiceManager();
        $config   = $services->get('config');

        // override the translator configuration
        $config['translator'] = $translatorConfig;

        $services->setService('config', $config);
        $services->setFactory('MvcTranslator', $config['service_manager']['factories']['MvcTranslator']);
    }
}
