<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Agents do not see what a trip COSTS the agency or what it earns: supplier cost, margin and what has been paid to suppliers are removed from
 * every JSON answer on the travel routes unless the person is an owner or admin. One filter instead of a check in every controller, so a new
 * endpoint cannot forget it. Selling prices, GST, markup % and discounts stay: agents quote with them. (Customer-facing payloads are already
 * cost-free; the Home dashboard, departures, payables and reports have their own scoping.)
 */
class CostHideFilter implements FilterInterface
{
    /** Removed wherever they appear in the answer. */
    public const COST_KEYS = [
        'cost_total', 'margin_amount', 'margin_pct', 'gross_margin', 'cost_amount', 'unit_cost', 'unit_cost_fx', 'cost_currency', 'cost_fx',
        'cost_quoted_inr', 'fx_variance', 'supplier_paid', 'cost_pax', 'single_supplement_cost',
    ];
    /** Removed only inside a booking's `services` list (there `paid_amount` is what WE paid the supplier; on a booking it is what the customer paid). */
    public const SERVICE_KEYS = ['paid_amount', 'supplier_id', 'pay_by'];

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (CurrentUser::get() === null || CurrentUser::isPrivileged()) { return null; }
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300 || ! str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) { return null; }
        $data = json_decode((string) $response->getBody(), true);
        if (! is_array($data)) { return null; }
        return $response->setBody((string) json_encode(self::strip($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** @param array<mixed> $a */
    public static function strip(array $a, bool $inServices = false): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_string($k) && (in_array($k, self::COST_KEYS, true) || ($inServices && in_array($k, self::SERVICE_KEYS, true)))) { continue; }
            $out[$k] = is_array($v) ? self::strip($v, $inServices || $k === 'services') : $v;
        }
        return $out;
    }
}
