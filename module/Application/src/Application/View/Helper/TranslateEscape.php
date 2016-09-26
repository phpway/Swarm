<?php
/**
 * Perforce Swarm
 *
 * @copyright   2015 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace Application\View\Helper;

use Zend\I18n\Exception;
use Zend\I18n\View\Helper\Translate as ZendTranslate;

class TranslateEscape extends ZendTranslate
{
    public function __invoke(
        $message,
        array $replacements = null,
        $context = null,
        $textDomain = "default",
        $locale = null
    ) {
        if ($this->translator === null) {
            throw new Exception\RuntimeException('Translator has not been set');
        }

        return $this->translator->translateReplaceEscape($message, $replacements, $context, $textDomain, $locale);
    }
}
