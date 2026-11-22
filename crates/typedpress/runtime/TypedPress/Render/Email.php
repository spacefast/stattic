<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Html;
use TypedPress\JsObject;
use TypedPress\Wp\Runtime;

final class Email
{
    /** @return array{html: string, text: string}|null */
    public static function invitation(string $siteName, string $identityUrl, string $invitationCode): ?array
    {
        $plugin = Runtime::plugin();
        $name = $plugin?->schema->components()['emails/identity/organization-invite.tsx'] ?? null;
        if ($name === null) {
            return null;
        }
        $plugin->app();
        return self::render('\\TypedPressApp\\' . $name, new JsObject([
            'siteName' => $siteName,
            'identityUrl' => $identityUrl,
            'invitationCode' => $invitationCode,
            'expiresIn' => 'seven days',
        ]));
    }

    /** `mail.render(Template, props)`: `{ html, text }` to pass to `mail.send()`. */
    public static function message(string $template, mixed $props): JsObject
    {
        return new JsObject(self::render($template, $props));
    }

    /** @return array{html: string, text: string} */
    public static function render(string $template, mixed $props): array
    {
        $html = Html::child($template($props));

        // Preserve paragraphs, line breaks and link destinations in the plain-text alternative.
        $text = preg_replace_callback('/(<a\b[^>]*>)(.*?)<\/a>/is', static function (array $match): string {
            $tag = new \WP_HTML_Tag_Processor($match[1]);
            $tag->next_tag('A');
            $href = $tag->get_attribute('href');
            return $match[2] . (is_string($href) && $href !== '' ? ' ' . Html::escape($href) : '');
        }, $html);
        $text = preg_replace('/<\/?p(?:\s[^>]*)?>/i', "\n\n", $text);
        $text = preg_replace('/<br\b[^>]*>/i', "\n", $text);
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\r]+/u', ' ', $text);
        return ['html' => $html, 'text' => trim(preg_replace('/\n{3,}/', "\n\n", $text))];
    }

    public static function component(string $name, ?string $attribute, mixed $children): Html
    {
        $attr = Html::escape($attribute ?? '');
        return Html::component(static function (mixed $children) use ($name, $attribute, $attr): Html {
            $body = Html::child($children);
            // Default markup from React Email 6.11.16. Keep the preview and the PHP output in parity.
            return Html::raw(match ($name) {
                'EmailHtml' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
                    . '<html dir="ltr" lang="' . ($attribute === null ? 'en' : $attr) . '"><head></head>' . $body . '</html>',
                'EmailBody' => '<body dir="ltr" lang="en"><!--$--><!--html--><!--body--><table border="0" width="100%" cellPadding="0" cellSpacing="0" role="presentation" align="center"><tbody><tr><td dir="ltr" lang="en">'
                    . $body . '</td></tr></tbody></table><!--/$--></body>',
                'EmailText' => '<p style="font-size:14px;line-height:24px;margin-top:16px;margin-bottom:16px">' . $body . '</p>',
                'EmailButton' => '<a' . ($attribute !== null && Html::safeUrl($attribute) ? ' href="' . $attr . '"' : '')
                    . ' style="line-height:100%;text-decoration:none;display:inline-block;max-width:100%;mso-padding-alt:0px" target="_blank">'
                    . '<span><!--[if mso]><i style="mso-font-width:0%;mso-text-raise:0px" hidden></i><![endif]--></span>'
                    . '<span style="max-width:100%;display:inline-block;line-height:120%;mso-padding-alt:0px">' . $body . '</span>'
                    . '<span><!--[if mso]><i style="mso-font-width:0%" hidden>&#8203;</i><![endif]--></span></a>',
            });
        }, $children);
    }
}
