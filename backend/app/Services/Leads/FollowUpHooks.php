<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Resolves the one phrase that makes a follow-up worth sending: the specific
 * problem we raised with this specific prospect.
 *
 * It is written to sit inside the sentence
 *
 *     "I wrote because {{2}} is where most owners we meet lose the most time."
 *
 * so every phrase here has to read as a noun phrase in the subject position.
 *
 * The lookup is deliberately ordered. A template that named ONE problem wins,
 * because that is literally what the prospect read, and a follow-up echoing
 * something else sounds like a different company wrote it. A template that
 * pitched everything — the carousels, the general opener — is left out of the
 * mapping entirely, so the lookup falls through to the contact's industry,
 * which in that case tells us more than the send did. The generic line is the
 * last resort, for a prospect we never categorised at all.
 *
 * Keeping this as data rather than nine separate approved templates is what
 * makes the whole thing practical: one Meta approval covers every category, and
 * changing a phrase is an edit here rather than a fresh approval cycle.
 */
final class FollowUpHooks
{
    public const GENERIC = 'the day-to-day running of your business';

    /** Order matters only for readability; lookup is exact-match. */
    private const BY_TEMPLATE = [
        'gamavis_manufacturing_no_software' => 'tracking an order from production plan to dispatch',
        'gamavis_apparel_no_software'       => 'style, size and colour-wise stock across your outlets',
        'gamavis_trading_no_software'       => 'what stock actually sits in each branch, and who owes you what',
        'gamavis_realestate_no_software'    => 'which unit is available and who followed up with which buyer',
        'gamavis_services_it_no_software'   => 'who is working on which project, and what is billable',
        'gamavis_pharma_no_software'        => 'batch and expiry tracking across your distributors',
        'gamavis_logistics_no_software'     => 'where each consignment has reached, without ringing the driver',
        'gamavis_associations_no_software'  => 'member records, dues and renewals in one place',
        'gamavis_erp_manufacturing'         => 'tracking an order from production plan to dispatch',
        // Deliberately ABSENT: gamavis_general_no_software, the two carousels
        // and gamavis_custom_software_promo. Those openers pitch the whole
        // range rather than one problem, so knowing which of them a prospect
        // received says nothing about what actually hurts. Leaving them out
        // drops the lookup through to the contact's industry, which in that
        // case is strictly more informative — and still lands on GENERIC if we
        // never categorised them. Mapping them to GENERIC here threw that away.
    ];

    /**
     * Fallback by imported company category. These are the exact tag names the
     * CRM import created — a near-miss here silently degrades to the generic
     * line, so they are spelled to match, punctuation included.
     */
    private const BY_CATEGORY = [
        'Automobile & Auto Components'         => 'tracking an order from production plan to dispatch',
        'Steel, Metals & Metal Products'       => 'what stock sits in each godown, and who owes you what',
        'Apparel, Textiles & Leather'          => 'style, size and colour-wise stock across your outlets',
        'Engineering & Industrial Machinery'   => 'tracking a job from production plan to dispatch',
        'RWAs, Associations & NGOs'            => 'member records, dues and renewals in one place',
        'Haryana Associations'                 => 'member records, dues and renewals in one place',
        'Chemicals, Paints & Coatings'         => 'batch tracking and stock across your plant',
        'Real Estate – Brokers & Consultants'  => 'which unit is available and who followed up with which buyer',
        'Real Estate – Builders & Developers'  => 'which unit is available and who followed up with which buyer',
        'IT, Software & Telecom'               => 'who is working on which project, and what is billable',
        'Professional & Business Services'     => 'who is working on which client, and what is billable',
        'Manufacturing – General'              => 'tracking an order from production plan to dispatch',
        'Logistics, Warehousing & Transport'   => 'where each consignment has reached, without ringing the driver',
        'Pharma, Healthcare & Medical'         => 'batch and expiry tracking across your distributors',
        'Paper, Printing & Packaging'          => 'costing and tracking each print job',
        'Food, Agro & Beverages'               => 'batch, expiry and stock across your distributors',
        'Electrical & Electronics'             => 'tracking an order from production plan to dispatch',
        'Plastics, Rubber & Polymers'          => 'tracking an order from production plan to dispatch',
        'Export & Import Houses'               => 'order, documentation and shipment status in one place',
        'Trading, Retail & Distribution'       => 'what stock actually sits in each branch, and who owes you what',
        'Construction & Building Materials'    => 'site-wise material, cost and billing',
        'Furniture, Interiors & Handicrafts'   => 'tracking a custom order from design to delivery',
        'Education & Training'                 => 'admissions, fees and follow-ups in one place',
        'Energy, Power & Environment'          => 'project, service and billing tracking',
        'Hospitality, Travel & Leisure'        => 'bookings, follow-ups and billing in one place',
        'FMCG & Consumer Products'             => 'stock across your distributors, and who owes you what',
        'Banking, Finance & Insurance'         => 'client follow-ups, renewals and documentation',
        'Government & Public Sector'           => 'records, approvals and follow-ups in one place',
        'Other / Not Identifiable'             => self::GENERIC,
    ];

    /**
     * @param  string|null $templateName The marketing template that went unanswered.
     * @param  string[]    $categories   The contact's tag names, any order.
     */
    public static function resolve(?string $templateName, array $categories = []): string
    {
        $name = trim((string) $templateName);
        if ($name !== '' && isset(self::BY_TEMPLATE[$name])) {
            return self::BY_TEMPLATE[$name];
        }

        foreach ($categories as $category) {
            $key = trim((string) $category);
            if ($key !== '' && isset(self::BY_CATEGORY[$key])) {
                return self::BY_CATEGORY[$key];
            }
        }

        return self::GENERIC;
    }

    /** Category names this mapping knows, for tests and tooling. */
    public static function knownCategories(): array
    {
        return array_keys(self::BY_CATEGORY);
    }

    public static function knownTemplates(): array
    {
        return array_keys(self::BY_TEMPLATE);
    }
}
