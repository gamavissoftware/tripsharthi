<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use Dompdf\Dompdf;
use Dompdf\Options;

/** The only place dompdf is configured. The engine is locked down: it never fetches URLs, reads files or runs code. */
final class PdfRenderer
{
    public static function render(string $html, string $footerLeft = '', string $title = 'Document'): string
    {
        $o = new Options();
        $o->set('isRemoteEnabled', false);        // images are embedded as data: URIs by SafeImage — the engine fetches nothing
        $o->set('isPhpEnabled', false);
        $o->set('isJavascriptEnabled', false);
        $o->set('chroot', WRITEPATH);              // belt and braces: even a rogue file:// path cannot leave writable/
        $o->set('defaultFont', 'DejaVu Sans');
        $o->set('dpi', 96);
        $o->set('fontCache', WRITEPATH . 'cache/');
        $o->set('tempDir', WRITEPATH . 'cache/');

        $d = new Dompdf($o);
        $d->setPaper('A4');
        $d->loadHtml($html, 'UTF-8');
        $d->render();

        $canvas = $d->getCanvas();
        $font = $d->getFontMetrics()->getFont('DejaVu Sans');
        $w = $canvas->get_width(); $h = $canvas->get_height();
        $canvas->page_text(40, $h - 28, $footerLeft, $font, 7.5, [0.55, 0.55, 0.6]);
        $canvas->page_text($w - 40 - 58, $h - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.55, 0.55, 0.6]);
        $d->addInfo('Title', $title);
        $d->addInfo('Creator', 'TravelPilot');
        return (string) $d->output();
    }
}
