<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An SSO sign-in matched an existing local account by email, but the IdP did
 * not assert the email as verified — silently attaching the identity would
 * let whoever controls that IdP account take over the local one.
 */
class SsoEmailCollisionException extends RuntimeException {}
