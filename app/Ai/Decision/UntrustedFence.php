<?php

declare(strict_types=1);

namespace App\Ai\Decision;

/**
 * Keeps text embedded in an <untrusted_...> fence from ending its own fence
 * early or opening a fake one; shared by every prompt that fences third-party
 * or model-written text (DecisionPrompt, SecondOpinionCase).
 */
final class UntrustedFence
{
    /**
     * An opening or closing fence tag in any encoding neutralise() handles:
     * the "<" (plain, "&lt;", "&#60;", "&#x3c;" or "<") is replaced and
     * what follows it up to "untrusted_" (the "/" in any encoding, any run of
     * backslashes, whitespace) is kept in group 1.
     */
    private const string TAG = '~(?:<|&lt;?|&#0*60;?|&#x0*3c;?|\\\\*u003c)(\s*\\\\*(?:/|&#0*47;?|&#x0*2f;?|&sol;?|u002f)?\s*)untrusted_~i';

    /**
     * Neutralise any opening or closing <untrusted_...> tag in embedded text
     * by replacing its "<" with "[". The "<" and "/" may be written plainly,
     * JSON-escaped ("<\/", any run of backslashes, "<", "/") or as
     * an HTML entity ("&lt;", "&#60;", "&#x3c;", "&sol;", with or without the
     * semicolon), since a model reads every one of them as the tag. The result
     * holds no tag, so neutralising it again changes nothing.
     */
    public static function neutralise(string $text): string
    {
        return (string) preg_replace(self::TAG, '[$1untrusted_', $text);
    }
}
