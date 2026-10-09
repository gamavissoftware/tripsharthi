<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Converts a stored template row into Meta's components array format
 * for use in the message_templates submission API and the messages API.
 *
 * Output shape mirrors Meta's Cloud API component structure:
 *   [
 *     {"type":"HEADER", "format":"IMAGE", "example":{"header_handle":["..."]}},
 *     {"type":"BODY",   "text":"Hi {{1}}!", "example":{"body_text":[["John"]]}},
 *     {"type":"FOOTER", "text":"Unsubscribe"}
 *   ]
 *
 * When building a send-time components array (for sendTemplate), a different
 * shape is used:
 *   [
 *     {"type":"header", "parameters":[{"type":"image","image":{"link":"..."}}]},
 *     {"type":"body",   "parameters":[{"type":"text","text":"John"}]}
 *   ]
 */
final class TemplateComponentBuilder
{
    // ------------------------------------------------------------------
    // Submission payload (POST /{waba_id}/message_templates)
    // ------------------------------------------------------------------

    /**
     * Build the components array for submitting a template to Meta for approval.
     */
    public static function forSubmission(array $template): array
    {
        $components = [];

        // Header
        if ($template['header_type'] !== 'none' && ! empty($template['header_content'])) {
            $components[] = self::submissionHeader($template);
        }

        // Body
        $bodyComponent = [
            'type' => 'BODY',
            'text' => $template['body'],
        ];

        $variables = json_decode($template['variables'] ?? '[]', true) ?: [];
        if (! empty($variables)) {
            // Meta shows these samples to the human reviewing the template, and
            // renders the body with them substituted in. A placeholder makes the
            // finished message read as nonsense — "I wrote because example_value
            // is where most owners lose the most time" — which is a rejection
            // waiting to happen, so a real sample is used wherever we have one.
            //
            // Two stored shapes are in the wild: a bare sample ({"1":"Rajesh"},
            // which every seeder writes) and a descriptor
            // ({"1":{"example":"Rajesh"}}). Only the second was handled before,
            // so every seeded template silently submitted placeholders.
            uksort($variables, static fn ($a, $b) => (int) $a <=> (int) $b);

            $examples = array_map(
                static function ($v): string {
                    $sample = trim((string) (is_array($v) ? ($v['example'] ?? '') : $v));

                    // Meta rejects an empty example outright, so something has
                    // to go in even when the template never recorded a sample.
                    return $sample === '' ? 'example_value' : $sample;
                },
                array_values($variables)
            );
            $bodyComponent['example'] = ['body_text' => [$examples]];
        }

        $components[] = $bodyComponent;

        // Footer
        if (! empty($template['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $template['footer']];
        }

        // Buttons
        $buttons = json_decode($template['buttons'] ?? '[]', true);
        if (! empty($buttons)) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        // Carousel (multi-card). The intro message is the BODY above; each card
        // carries its own image header, body, and buttons.
        $cards = json_decode($template['cards'] ?? '[]', true) ?: [];
        if (! empty($cards)) {
            $components[] = self::submissionCarousel($cards);
        }

        return $components;
    }

    /** Build the Meta CAROUSEL component for template submission. */
    private static function submissionCarousel(array $cards): array
    {
        $out = [];
        foreach ($cards as $card) {
            // Prefer a resolved Meta header_handle (from the resumable upload);
            // fall back to the raw URL (works in mock mode / pre-resolved handles).
            $cardComponents = [
                ['type' => 'HEADER', 'format' => 'IMAGE',
                 'example' => ['header_handle' => [(string) ($card['_handle'] ?? $card['image_url'] ?? '')]]],
                ['type' => 'BODY', 'text' => (string) ($card['body'] ?? '')],
            ];
            $btns = self::normaliseButtons($card['buttons'] ?? []);
            if (! empty($btns)) {
                $cardComponents[] = ['type' => 'BUTTONS', 'buttons' => $btns];
            }
            $out[] = ['components' => $cardComponents];
        }

        return ['type' => 'CAROUSEL', 'cards' => $out];
    }

    /** Normalise card buttons to Meta's submission shape (URL or QUICK_REPLY). */
    private static function normaliseButtons(array $buttons): array
    {
        $out = [];
        foreach ($buttons as $b) {
            if (strtoupper($b['type'] ?? 'QUICK_REPLY') === 'URL') {
                $out[] = ['type' => 'URL', 'text' => $b['text'] ?? 'Open',
                          'url' => $b['url'] ?? 'https://example.com'];
            } else {
                $out[] = ['type' => 'QUICK_REPLY', 'text' => $b['text'] ?? 'Reply'];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Send-time payload (POST /{phone_number_id}/messages)
    // ------------------------------------------------------------------

    /**
     * Build the components array for a specific send, with resolved body parameters.
     *
     * @param array $template    Template DB row.
     * @param array $bodyParams  Resolved body variable parameters:
     *                           [["type"=>"text","text"=>"John"], …]
     */
    public static function forSend(array $template, array $bodyParams = []): array
    {
        $components = [];

        // Header (media, or text WITH a variable). A static text header carries
        // no send-time parameter — Meta uses the registered text — so sendHeader
        // returns [] for it and we skip the component entirely.
        if ($template['header_type'] !== 'none' && ! empty($template['header_content'])) {
            $header = self::sendHeader($template);
            if ($header !== []) {
                $components[] = $header;
            }
        }

        // Body (personalised variables per recipient)
        if (! empty($bodyParams)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => $bodyParams,
            ];
        }

        // Carousel: each card supplies its image at send time (the image isn't
        // registered with the template — only an example was at submission).
        $cards = json_decode($template['cards'] ?? '[]', true) ?: [];
        if (! empty($cards)) {
            $components[] = self::sendCarousel($cards);
        }

        return $components;
    }

    /** Build the Meta carousel component for a message send. */
    private static function sendCarousel(array $cards): array
    {
        $out = [];
        foreach ($cards as $i => $card) {
            $cardComps = [
                ['type' => 'header', 'parameters' => [
                    ['type' => 'image', 'image' => ['link' => (string) ($card['image_url'] ?? '')]],
                ]],
            ];

            // Quick-reply buttons need a payload parameter at send time; static
            // URL buttons need none. Keep the button index aligned across types.
            foreach (array_values($card['buttons'] ?? []) as $btnIndex => $b) {
                if (strtoupper($b['type'] ?? 'QUICK_REPLY') !== 'URL') {
                    $cardComps[] = [
                        'type'       => 'button',
                        'sub_type'   => 'quick_reply',
                        'index'      => (string) $btnIndex,
                        'parameters' => [['type' => 'payload', 'payload' => "card{$i}_btn{$btnIndex}"]],
                    ];
                }
            }

            $out[] = ['card_index' => $i, 'components' => $cardComps];
        }

        return ['type' => 'carousel', 'cards' => $out];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private static function submissionHeader(array $template): array
    {
        $type    = strtoupper($template['header_type']); // TEXT, IMAGE, DOCUMENT, VIDEO
        $content = $template['header_content'];

        $component = ['type' => 'HEADER', 'format' => $type];

        if ($type === 'TEXT') {
            $component['text'] = $content;
        } else {
            // Meta requires an example media handle or URL for media headers
            $component['example'] = ['header_handle' => [$content]];
        }

        return $component;
    }

    private static function sendHeader(array $template): array
    {
        $type    = $template['header_type']; // text, image, document, video
        $content = $template['header_content'];

        if ($type === 'text') {
            // A static text header (no {{n}} placeholder) takes ZERO parameters
            // at send time — sending one triggers Meta error #132000. Only a
            // header that actually contains a variable needs a parameter.
            if (! str_contains((string) $content, '{{')) {
                return [];
            }
            return [
                'type'       => 'header',
                'parameters' => [['type' => 'text', 'text' => $content]],
            ];
        }

        // image, document, video
        return [
            'type'       => 'header',
            'parameters' => [
                [
                    'type' => $type,
                    $type  => ['link' => $content],
                ],
            ],
        ];
    }
}
