<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Parses an inbound WhatsApp Flow submission (interactive 'nfm_reply').
 *
 * Meta delivers the answers as a JSON string in response_json, which also
 * contains the flow_token we set when sending the Flow. Pure — no DB.
 */
class FlowResponseParser
{
    /**
     * @param array $interactive The message's `interactive` object.
     * @return array{flow_token:string, response:array}|null  null if not an nfm_reply.
     */
    public static function parse(array $interactive): ?array
    {
        if (($interactive['type'] ?? '') !== 'nfm_reply') {
            return null;
        }

        $raw      = $interactive['nfm_reply']['response_json'] ?? '{}';
        $response = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        $token = (string) ($response['flow_token'] ?? '');
        unset($response['flow_token']); // keep the token out of the answer map

        return ['flow_token' => $token, 'response' => $response];
    }
}
