<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Builds the document for the preview iframe of a stored or rendered message.
 *
 * A mail body is untrusted: it may contain text typed by an anonymous visitor (a contact form), so it is
 * never inserted into the panel DOM. The caller puts the result into `srcdoc` (escaped by Blade) of an
 * iframe with an empty `sandbox` attribute: no scripts, no same-origin access, no forms, no top navigation.
 * The CSP meta tag is a second line that also stops remote fonts, frames and `<form>` posts.
 */
final class SafeFrame
{
    public static function document(string $html): string
    {
        $csp = config('filament-mail-log.preview.csp');

        if (!is_string($csp) || $csp === '') {
            return $html;
        }

        return '<meta http-equiv="Content-Security-Policy" content="'.e($csp).'">'.$html;
    }
}
