<?php
/**
 * Exception to be thrown when a login attempt fails.
 *
 * @copyright   2011 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level folder of this distribution.
 * @version     <release>/<patch>
 */

namespace P4\Connection\Exception;

class LoginException extends \P4\Exception
{
    const   IDENTITY_NOT_FOUND = -1;
    const   IDENTITY_AMBIGUOUS = -2;
    const   CREDENTIAL_INVALID = -3;
}
