<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\IntegrationModel;
use App\Services\Social\GraphClient;
use RuntimeException;

/**
 * Create a REAL test lead on a Facebook lead form, with real values.
 *
 * Meta's two test paths are both useless for proving a CRM integration end
 * to end: the Lead Ads Testing Tool fills every field with "dummy data for
 * <field>", and Business Suite's "Test form" cannot submit at all. The Graph
 * edge `POST /{form_id}/test_leads` accepts `field_data`, so the lead that
 * comes back through the webhook carries the phone number we chose — and
 * a contact really gets created, the flow really fires.
 *
 * Meta allows one test lead per form at a time; an existing one is removed
 * first. Test leads are flagged by Meta and never counted as ad results.
 */
class MetaTestLeadService
{
    public function __construct(private ?GraphClient $graph = null)
    {
        $this->graph ??= new GraphClient();
    }

    /**
     * The lead forms on the tenant's linked Page.
     *
     * @return array<int,array{id:string,name:string,status:string,leads_count:int}>
     */
    public function forms(int $tenantId): array
    {
        [$pageId, $token] = $this->pageAndToken($tenantId);

        $resp  = $this->graph->get("{$pageId}/leadgen_forms", [
            'fields'       => 'id,name,status,leads_count',
            'limit'        => 100,
            'access_token' => $token,
        ]);
        $forms = [];
        foreach ((array) ($resp['data'] ?? []) as $f) {
            $f       = (array) $f;
            $forms[] = [
                'id'          => (string) ($f['id'] ?? ''),
                'name'        => (string) ($f['name'] ?? ''),
                'status'      => (string) ($f['status'] ?? ''),
                'leads_count' => (int) ($f['leads_count'] ?? 0),
            ];
        }

        return $forms;
    }

    /**
     * Submit a test lead to $formId with these contact values; every other
     * question on the form gets a sensible filler so Meta accepts it.
     *
     * @return array{leadgen_id:string,form_id:string,field_data:array<int,array{name:string,values:array<int,string>}>}
     */
    public function create(int $tenantId, string $formId, string $phone, string $name = 'TravelPilot Test Lead', string $email = 'test@travelpilot.test'): array
    {
        [, $token] = $this->pageAndToken($tenantId);

        $form = $this->graph->get($formId, [
            'fields'       => 'id,name,questions{key,type,label,options}',
            'access_token' => $token,
        ]);

        $fieldData = [];
        foreach ((array) ($form['questions'] ?? []) as $q) {
            $q     = (array) $q;
            $key   = (string) ($q['key'] ?? '');
            $type  = strtoupper((string) ($q['type'] ?? 'CUSTOM'));
            if ($key === '') {
                continue;
            }
            $fieldData[] = ['name' => $key, 'values' => [self::valueFor($key, $type, (array) ($q['options'] ?? []), $phone, $name, $email)]];
        }
        if ($fieldData === []) {
            throw new RuntimeException("Form {$formId} has no questions Meta will let us fill.");
        }

        // One test lead per form: clear any previous one first.
        try {
            $this->graph->delete("{$formId}/test_leads", ['access_token' => $token]);
        } catch (\Throwable $e) {
            // Nothing to delete is the normal case.
        }

        $resp = $this->graph->post("{$formId}/test_leads", [
            'field_data'   => json_encode($fieldData),
            'access_token' => $token,
        ]);
        $leadId = (string) ($resp['id'] ?? '');
        if ($leadId === '') {
            throw new RuntimeException('Meta did not return a lead id for the test lead.');
        }

        log_message('info', "MetaTestLeadService: tenant {$tenantId} created test lead {$leadId} on form {$formId}.");

        return ['leadgen_id' => $leadId, 'form_id' => $formId, 'field_data' => $fieldData];
    }

    /**
     * A plausible answer for one question. Phone-like questions get the real
     * number; choice questions get their first option; the rest get a label.
     *
     * @param array<int,mixed> $options
     */
    public static function valueFor(string $key, string $type, array $options, string $phone, string $name, string $email): string
    {
        $k = strtolower($key);
        if ($type === 'PHONE' || $type === 'WORK_PHONE_NUMBER' || preg_match('/whatsapp|phone|mobile|contact_number|cell/', $k)) {
            return $phone;
        }
        if ($type === 'EMAIL' || $type === 'WORK_EMAIL' || str_contains($k, 'email')) {
            return $email;
        }
        if (in_array($type, ['FULL_NAME', 'FIRST_NAME', 'LAST_NAME'], true) || preg_match('/^(full_|first_|last_)?name$/', $k)) {
            return $type === 'LAST_NAME' ? 'Lead' : $name;
        }
        if ($type === 'COMPANY_NAME' || str_contains($k, 'company')) {
            return 'TravelPilot Test Co';
        }
        if ($type === 'JOB_TITLE' || str_contains($k, 'job')) {
            return 'Tester';
        }
        if ($options !== []) {
            $first = (array) $options[0];

            return (string) ($first['value'] ?? $first['key'] ?? 'Option 1');
        }

        return 'Test lead sent from TravelPilot';
    }

    /**
     * @return array{0:string,1:string} [page_id, decrypted page token]
     */
    private function pageAndToken(int $tenantId): array
    {
        $model = new IntegrationModel();
        $row   = $model->findActiveByType($tenantId, MetaLeadAdsLinker::TYPE);
        if (! $row) {
            throw new RuntimeException('No Facebook Page is linked for Lead Ads on this workspace.');
        }
        $row    = (array) $row;
        $token  = $model->decryptPageToken($row);
        $pageId = (string) ($row['page_id'] ?? '');
        if ($token === '' || $pageId === '') {
            throw new RuntimeException('The linked Page has no usable access token — reconnect with Facebook.');
        }

        return [$pageId, $token];
    }
}
