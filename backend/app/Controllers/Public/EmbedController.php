<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Models\WebFormModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Serves the JS embed snippet that injects the hosted form as an iframe.
 *
 * GET /embed/{token}.js
 */
class EmbedController extends Controller
{
    public function script(string $token): ResponseInterface
    {
        $form = (new WebFormModel())->findByToken($token);

        if ($form === null || ! (bool) $form['active']) {
            return $this->response->setStatusCode(404)
                ->setContentType('application/javascript')
                ->setBody('/* TravelPilot: form not found */');
        }

        $formUrl = base_url("forms/{$token}");
        $js      = $this->buildSnippet($formUrl, $token);

        return $this->response
            ->setHeader('Cache-Control', 'public, max-age=3600')
            ->setContentType('application/javascript')
            ->setBody($js);
    }

    private function buildSnippet(string $formUrl, string $token): string
    {
        return <<<JS
(function() {
  var id = 'lp-form-' + '{$token}';
  if (document.getElementById(id)) return;

  // Ad attribution: remember the FIRST ad click (gclid / fbclid / UTMs) for 90 days and hand it to the form,
  // so a lead who browses several pages before enquiring is still credited to the campaign that brought them.
  var KEYS = ['gclid','gbraid','wbraid','fbclid','utm_source','utm_medium','utm_campaign','utm_term','utm_content','utm_id'];
  var found = {}, store = {};
  try { store = JSON.parse(localStorage.getItem('tp_attr') || '{}'); } catch (e) {}
  var params = new URLSearchParams(location.search);
  KEYS.forEach(function(k) { if (params.get(k)) found[k] = params.get(k); });
  var fresh = Object.keys(found).length > 0;
  if (fresh && (!store.t || Date.now() - store.t > 90 * 864e5 || !(store.v && (store.v.gclid || store.v.fbclid)))) {
    store = { t: Date.now(), v: found, u: location.href.slice(0, 500) };
    try { localStorage.setItem('tp_attr', JSON.stringify(store)); } catch (e) {}
  }
  var attr = (store.v && Date.now() - store.t < 90 * 864e5) ? store.v : found;
  var ck = function(n) { var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; };
  if (ck('_fbp')) attr.fbp = ck('_fbp');
  if (ck('_fbc')) attr.fbc = ck('_fbc');
  attr.landing_url = (store.u || location.href).slice(0, 500);
  var qs = Object.keys(attr).map(function(k) { return encodeURIComponent(k) + '=' + encodeURIComponent(attr[k]); }).join('&');

  var iframe = document.createElement('iframe');
  iframe.id  = id;
  iframe.src = '{$formUrl}' + (qs ? '?' + qs : '');
  iframe.style.cssText = 'width:100%;border:none;min-height:420px;';
  iframe.setAttribute('scrolling', 'no');
  iframe.setAttribute('allowtransparency', 'true');

  var script = document.currentScript || document.querySelector('script[src*="{$token}"]');
  if (script && script.parentNode) {
    script.parentNode.insertBefore(iframe, script.nextSibling);
  } else {
    document.body.appendChild(iframe);
  }

  window.addEventListener('message', function(e) {
    if (e.data && e.data.lpHeight && e.data.lpToken === '{$token}') {
      iframe.style.height = e.data.lpHeight + 'px';
    }
  });
})();
JS;
    }
}
