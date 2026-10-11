<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Email\EmailService;
use App\Services\Marketing\EmailBody;
use App\Services\Partner\PartnerInviteMail;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Developer tool: renders every customer-facing e-mail design into writable/email-preview/*.html (+ index.html) so they can be reviewed in a browser. Sends nothing. */
class EmailPreview extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'email:preview';
    protected $description = 'Render sample HTML of every e-mail design into writable/email-preview/ (nothing is sent).';

    public function run(array $params): void
    {
        $dir = WRITEPATH . 'email-preview'; if (! is_dir($dir)) { mkdir($dir, 0775, true); }
        $made = [];
        EmailService::$capture = static function (string $to, string $subject, string $html) use (&$made, $dir): void {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $subject) ?? '', '-')) ?: 'mail'; file_put_contents("{$dir}/{$slug}.html", $html); $made[$slug] = $subject;
        };
        try {
            EmailService::sendWelcome('a@example.com', 'Priya Sharma', 'https://app.tripsarthi.com');
            EmailService::sendPasswordReset('a@example.com', 'Priya Sharma', 'https://app.tripsarthi.com/#/reset-password?token=abc123');
            EmailService::sendCampaignComplete('a@example.com', 'Priya Sharma', 'Diwali offer', 188, 4);
            EmailService::sendWabaFlagged('a@example.com', 'Priya Sharma', '+91 98765 43210');
            EmailService::sendTest('a@example.com');
            PartnerInviteMail::send(['name' => 'Diksha Shukla', 'email' => 'd@example.com', 'code' => 'DIKSHA20', 'commission_pct' => 20, 'commission_months' => 12], 'https://partners.tripsarthi.com/#/set-password?token=0123456789abcdef0123456789abcdef0123456789abcdef', false);
            PartnerInviteMail::send(['name' => 'Diksha Shukla', 'email' => 'd@example.com'], 'https://partners.tripsarthi.com/#/set-password?token=0123456789abcdef0123456789abcdef0123456789abcdef', true);
            $body = "# Diwali offer for TripSarthi customers\n\nHi {{first_name}},\n\nThis Diwali, upgrade to any annual plan and get 20% off. Here is what you get:\n\n- Unlimited quotes and bookings\n- WhatsApp automation that runs on its own\n- Payment reminders that collect for you\n\n> Offer valid until 31 October. Use the code DIWALI20 at checkout.\n\n[[button: Claim my 20% off | https://app.tripsarthi.com/#/settings/billing]]\n\nWarm regards,\nTeam TripSarthi";
            $r = EmailBody::render($body, 'Priya', 'https://app.tripsarthi.com/api/v1/public/email/unsubscribe?e=x&t=y', 'Diwali offer');
            file_put_contents("{$dir}/campaign-sample.html", $r['html']); $made['campaign-sample'] = 'Marketing campaign (sample)';
        } finally { EmailService::$capture = null; }
        $li = ''; foreach ($made as $slug => $label) { $li .= '<li><a href="' . htmlspecialchars($slug) . '.html">' . htmlspecialchars($label) . '</a></li>'; }
        file_put_contents("{$dir}/index.html", '<!doctype html><meta charset="utf-8"><title>E-mail designs</title><body style="font-family:sans-serif;padding:24px"><h2>TripSarthi e-mail designs</h2><ul>' . $li . '</ul>');
        CLI::write('Rendered ' . count($made) . " e-mails into {$dir}", 'green');
    }
}
