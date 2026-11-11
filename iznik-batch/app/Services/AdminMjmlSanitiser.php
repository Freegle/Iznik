<?php

namespace App\Services;

/**
 * Sanitises the MJML part of an ADMIN before it is compiled into an email.
 *
 * The MJML is written by a moderator, so it is untrusted markup that ends up in members' mail.
 * This walks it tag by tag and rebuilds it:
 *
 * - elements that run code, embed other documents or take input are removed with their content;
 * - document-level MJML (mjml, mj-head, mj-body, mj-include) is removed, because the ADMIN
 *   template supplies those, and mj-include would make the MJML server read a file;
 * - event-handler attributes are removed, and so are URL attributes whose scheme is not
 *   http, https, mailto or tel, and style attributes that try to run script;
 * - every attribute value is re-escaped, and a `<` that does not start a tag becomes `&lt;`,
 *   so nothing half-formed can join up with the template around it after compilation.
 *
 * What is left is ordinary MJML and HTML, which the MJML compiler turns into the HTML part.
 */
class AdminMjmlSanitiser
{
    /** Elements removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
        'textarea', 'select', 'noscript', 'svg', 'math', 'template', 'title', 'xml',
        'mj-head', 'mj-style', 'mj-attributes', 'mj-include',
    ];

    /** Elements whose tags are removed but whose content is kept. */
    private const DROP_TAG = [
        'mjml', 'mj-body', 'html', 'head', 'body', 'meta', 'link', 'base', 'input', 'button',
        'option', 'param', 'source', 'track',
    ];

    /** Attributes that hold a URL. */
    private const URL_ATTRIBUTES = [
        'href', 'src', 'action', 'formaction', 'background', 'background-url', 'poster',
        'xlink:href', 'cite', 'data', 'lowsrc', 'dynsrc', 'longdesc', 'usemap',
    ];

    /** Attributes removed outright: srcset can carry several URLs and is not needed in email. */
    private const DROP_ATTRIBUTES = ['srcset', 'formaction', 'xmlns'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** A tag, with quoted attribute values allowed to contain `>`; or a lone `<`; or text. */
    private const TOKEN = '/<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>|<|[^<]+/s';

    private const ATTRIBUTE = '/([^\s=\/"\']+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/';

    public function sanitise(string $mjml): string
    {
        // Comments go first: they can hide markup, and Outlook acts on conditional ones.
        $mjml = preg_replace('/<!--.*?(?:-->|$)/s', '', $mjml);

        preg_match_all(self::TOKEN, $mjml, $tokens, PREG_SET_ORDER);

        $out = '';
        $skipping = NULL;
        $depth = 0;

        foreach ($tokens as $token) {
            if (!isset($token[2]) || $token[2] === '') {
                // Text, or a `<` that does not start a tag.
                if ($skipping === NULL) {
                    $out .= $token[0] === '<' ? '&lt;' : $token[0];
                }

                continue;
            }

            $closing = $token[1] === '/';
            $name = strtolower($token[2]);
            $attributes = $token[3];
            $selfClosing = str_ends_with(rtrim($attributes), '/');

            if ($skipping !== NULL) {
                if ($name === $skipping) {
                    if ($closing) {
                        $depth--;
                    } elseif (!$selfClosing) {
                        $depth++;
                    }

                    if ($depth === 0) {
                        $skipping = NULL;
                    }
                }

                continue;
            }

            if (in_array($name, self::DROP_WITH_CONTENT, TRUE)) {
                if (!$closing && !$selfClosing) {
                    $skipping = $name;
                    $depth = 1;
                }

                continue;
            }

            if (in_array($name, self::DROP_TAG, TRUE)) {
                continue;
            }

            if ($closing) {
                $out .= "</{$name}>";
            } else {
                $out .= '<' . $name . $this->attributes($attributes) . ($selfClosing ? ' />' : '>');
            }
        }

        return $out;
    }

    private function attributes(string $attributes): string
    {
        $attributes = preg_replace('/\/\s*$/', '', $attributes);
        preg_match_all(self::ATTRIBUTE, $attributes, $matches, PREG_SET_ORDER);

        $out = '';

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (!preg_match('/^[a-z][a-z0-9:_-]*$/', $name)
                || str_starts_with($name, 'on')
                || in_array($name, self::DROP_ATTRIBUTES, TRUE)) {
                continue;
            }

            if (!isset($match[2])) {
                $out .= " {$name}";

                continue;
            }

            $value = $match[2];
            if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                $value = substr($value, 1, -1);
            }
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (in_array($name, self::URL_ATTRIBUTES, TRUE) && !$this->safeUrl($value)) {
                continue;
            }

            if ($name === 'style' && $this->scriptInStyle($value)) {
                continue;
            }

            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return $out;
    }

    private function safeUrl(string $url): bool
    {
        // Browsers ignore whitespace and control characters inside a scheme ("java\tscript:").
        $compact = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $url));

        if (!preg_match('/^([a-z][a-z0-9+.-]*):/', $compact, $m)) {
            // Relative URLs, fragments and MJML placeholders have no scheme.
            return TRUE;
        }

        return in_array($m[1], self::SAFE_SCHEMES, TRUE);
    }

    private function scriptInStyle(string $style): bool
    {
        $compact = strtolower(preg_replace('/[\x00-\x20\x7f\\\\]+/', '', $style));

        return str_contains($compact, 'expression(')
            || str_contains($compact, 'javascript:')
            || str_contains($compact, 'vbscript:')
            || str_contains($compact, 'behavior:')
            || str_contains($compact, '-moz-binding')
            || str_contains($compact, '@import');
    }
}
