<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Models\ItineraryModel;
use App\Models\TripModel;
use App\Services\Travel\ItineraryService;
use CodeIgniter\RESTful\ResourceController;

/** Customer-facing quote link. No auth; the 32-hex token is the credential. Cost/margin never leave the server. */
class ItineraryShareController extends ResourceController
{
    protected $format = 'json';

    private function find(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return (new ItineraryModel())->withoutTenantScope()->where('share_token', $token)->first();
    }

    public function show($token = '')
    {
        $it = $this->find((string) $token);
        if (! $it) { return $this->failNotFound('This quote link is invalid.'); }
        $tid = (int) $it['tenant_id'];
        $m = (new ItineraryModel())->setTenant($tid);
        $m->update((int) $it['id'], [
            'view_count' => (int) $it['view_count'] + 1,
            'viewed_at'  => $it['viewed_at'] ?: date('Y-m-d H:i:s'),
            'status'     => $it['status'] === 'sent' ? 'viewed' : $it['status'],
        ]);
        if (! $it['viewed_at']) { // first view only
            \App\Services\Travel\TravelTriggerService::safely(fn ($t) => $t->quoteEvent($tid, (int) $it['id'], 'quote_viewed'));
        }
        $data = (new ItineraryService())->full($tid, (int) $it['id'], true);
        $data['expired'] = $it['valid_until'] && $it['valid_until'] < date('Y-m-d');
        unset($data['tenant_id']);
        return $this->respond(['success' => true, 'data' => $data]);
    }

    /** POST /q/:token/accept — customer taps "Accept"; agent is notified via status. */
    public function accept($token = '')
    {
        $it = $this->find((string) $token);
        if (! $it) { return $this->failNotFound('This quote link is invalid.'); }
        if ($it['valid_until'] && $it['valid_until'] < date('Y-m-d')) { return $this->fail('This quote has expired.', 410); }
        if (! in_array($it['status'], ['sent', 'viewed'], true)) { return $this->fail('This quote can no longer be accepted.', 409); }
        $tid = (int) $it['tenant_id'];
        (new ItineraryModel())->setTenant($tid)->update((int) $it['id'], ['accepted_at' => date('Y-m-d H:i:s'), 'status' => 'accepted']);
        if ($it['trip_id']) {
            (new \App\Services\Travel\TripService())->setStatus($tid, (int) $it['trip_id'], 'negotiating');
        }
        \App\Services\Travel\TravelTriggerService::safely(fn ($t) => $t->quoteEvent($tid, (int) $it['id'], 'quote_accepted'));
        return $this->respond(['success' => true, 'data' => ['accepted' => true]]);
    }
}
