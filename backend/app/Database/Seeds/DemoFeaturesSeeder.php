<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Seeds demo data for the P1–P4 feature set so a fresh clone can immediately
 * click through Products, Inbox Routing, Webhooks, multi-language, and
 * birthday/date automations. Idempotent — safe to run repeatedly.
 *
 * Connection-backed features (Razorpay payments, Meta catalog id, Shopify /
 * WooCommerce) are intentionally NOT seeded — they need real per-tenant
 * credentials and correctly show as "not connected" until configured.
 */
class DemoFeaturesSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->seedProducts($now);
        $this->seedRoutingRule($now);
        $this->seedWebhook($now);
        $this->seedBirthdayField($now);
        $this->seedContactLanguage($now);
        $this->seedTemplatesAndFlows($now);
        CLI::write('DemoFeaturesSeeder: P1–P4 demo data seeded.', 'green');
    }

    /**
     * Seed approved templates (en + hi for the multi-language demo) and two
     * active sample flows so the messaging + automation path is clickable.
     */
    private function seedTemplatesAndFlows(string $now): void
    {
        $templates = [
            ['welcome_lead', 'en', 'Hi! Thanks for reaching out — our team will get back to you shortly. 🙌'],
            ['welcome_lead', 'hi', 'नमस्ते! संपर्क करने के लिए धन्यवाद — हमारी टीम जल्द ही आपसे संपर्क करेगी। 🙌'],
        ];
        $enTemplateId = 0;
        foreach ($templates as [$name, $lang, $body]) {
            $row = $this->db->table('templates')
                ->where('tenant_id', 1)->where('name', $name)->where('language', $lang)
                ->get()->getRowArray();
            if ($row) {
                if ($lang === 'en') { $enTemplateId = (int) $row['id']; }
                continue;
            }
            $this->db->table('templates')->insert([
                'tenant_id'   => 1, 'name' => $name, 'display_name' => 'Welcome Lead',
                'language'    => $lang, 'category' => 'utility', 'header_type' => 'none',
                'body'        => $body, 'meta_template_id' => 'demo_' . $name . '_' . $lang,
                'meta_status' => 'approved',
                'created_at'  => $now, 'updated_at' => $now,
            ]);
            if ($lang === 'en') { $enTemplateId = (int) $this->db->insertID(); }
        }

        if ($enTemplateId <= 0) {
            return;
        }

        // Sample flow 1: new lead → welcome template.
        $this->seedFlow($now, 'Welcome new leads', 'lead_created', null, [
            'nodes' => [
                ['id' => 't', 'type' => 'lead_created',  'data' => new \stdClass()],
                ['id' => 's', 'type' => 'send_template', 'data' => ['template_id' => $enTemplateId, 'template_name' => 'welcome_lead']],
            ],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 's', 'sourceHandle' => 'next']],
        ]);

        // Sample flow 2: birthday → welcome template (ties to the seeded birthday field).
        $this->seedFlow($now, 'Birthday greeting', 'date_reached',
            ['field_key' => 'birthday', 'days_before' => 0, 'recurring' => true], [
                'nodes' => [
                    ['id' => 't', 'type' => 'date_reached',  'data' => new \stdClass()],
                    ['id' => 's', 'type' => 'send_template', 'data' => ['template_id' => $enTemplateId, 'template_name' => 'welcome_lead']],
                ],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 's', 'sourceHandle' => 'next']],
            ]);
    }

    private function seedFlow(string $now, string $name, string $trigger, ?array $config, array $graph): void
    {
        if ($this->exists('flows', ['tenant_id' => 1, 'name' => $name])) {
            return;
        }
        $this->db->table('flows')->insert([
            'tenant_id'      => 1, 'name' => $name, 'status' => 'active',
            'trigger_type'   => $trigger,
            'trigger_config' => $config ? json_encode($config) : null,
            'graph'          => json_encode($graph),
            'reentry_policy' => 'once', 'version' => 1,
            'created_at'     => $now, 'updated_at' => $now,
        ]);
    }

    private function seedProducts(string $now): void
    {
        $products = [
            ['SKU-TSHIRT', 'Cotton T-Shirt', 'Soft 100% cotton tee', 49900],
            ['SKU-MUG',    'Ceramic Mug',    'Microwave-safe 350ml mug', 29900],
            ['SKU-BOTTLE', 'Steel Bottle',   'Insulated 750ml bottle', 79900],
        ];
        foreach ($products as [$sku, $name, $desc, $paise]) {
            if ($this->exists('products', ['tenant_id' => 1, 'retailer_id' => $sku])) {
                continue;
            }
            $this->db->table('products')->insert([
                'tenant_id' => 1, 'retailer_id' => $sku, 'name' => $name, 'description' => $desc,
                'price_paise' => $paise, 'currency' => 'INR', 'availability' => 'in_stock',
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedRoutingRule(string $now): void
    {
        if (! $this->exists('routing_rules', ['tenant_id' => 1, 'name' => 'Default — balance across agents'])) {
            $this->db->table('routing_rules')->insert([
                'tenant_id' => 1, 'name' => 'Default — balance across agents',
                'match_type' => 'any', 'strategy' => 'least_loaded',
                'sort_order' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedWebhook(string $now): void
    {
        if (! $this->exists('webhook_subscriptions', ['tenant_id' => 1, 'url' => 'https://example.com/travelpilot-webhook'])) {
            $this->db->table('webhook_subscriptions')->insert([
                'tenant_id' => 1, 'url' => 'https://example.com/travelpilot-webhook',
                'secret' => 'whsec_demo_' . bin2hex(random_bytes(10)),
                'events' => json_encode(['*']),
                'is_active' => 0, // inactive demo example — no delivery attempts
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedBirthdayField(string $now): void
    {
        $field = $this->db->table('custom_fields')
            ->where('tenant_id', 1)->where('field_key', 'birthday')->get()->getRowArray();

        if (! $field) {
            $this->db->table('custom_fields')->insert([
                'tenant_id' => 1, 'label' => 'Birthday', 'field_key' => 'birthday',
                'type' => 'date', 'is_required' => 0, 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $fieldId = (int) $this->db->insertID();
        } else {
            $fieldId = (int) $field['id'];
        }

        // Give a couple of demo contacts a birthday — one is TODAY so the
        // date_reached trigger demo fires on the next `flows:dates` run.
        $birthdays = [
            '+919999900101' => '1990-' . date('m-d'),   // today (recurring match)
            '+919999900102' => '1988-12-25',
        ];
        foreach ($birthdays as $wa => $value) {
            $contact = $this->db->table('contacts')
                ->where('tenant_id', 1)->where('wa_number', $wa)->get()->getRowArray();
            if (! $contact) {
                continue;
            }
            $cid = (int) $contact['id'];
            if ($this->exists('contact_field_values', ['contact_id' => $cid, 'custom_field_id' => $fieldId])) {
                continue;
            }
            $this->db->table('contact_field_values')->insert([
                'contact_id' => $cid, 'custom_field_id' => $fieldId, 'value' => $value,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedContactLanguage(string $now): void
    {
        // Demo a non-default language so multi-language template routing is visible.
        $this->db->table('contacts')
            ->where('tenant_id', 1)->where('wa_number', '+919999900103')
            ->update(['language' => 'hi', 'updated_at' => $now]);
    }

    private function exists(string $table, array $where): bool
    {
        $q = $this->db->table($table);
        foreach ($where as $k => $v) {
            $q->where($k, $v);
        }
        return $q->get()->getRow() !== null;
    }
}
