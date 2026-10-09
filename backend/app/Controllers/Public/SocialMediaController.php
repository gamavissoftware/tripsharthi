<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Social\SocialMediaStore;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Serves an uploaded creative to whoever asks — in practice, Meta.
 *
 * Public on purpose: Graph fetches the photo by URL with no session, so this
 * cannot sit behind the auth filter. Registered outside the api/v1 group for
 * that reason, but kept under /api/ because that is one of the few prefixes
 * production nginx actually hands to PHP.
 *
 * The filename is 32 random hex characters and is validated by
 * SocialMediaStore::pathFor(), so a request can only ever name a file already
 * inside the store.
 */
class SocialMediaController extends Controller
{
    public function show(string $tenant = '', string $name = ''): ResponseInterface
    {
        $path = SocialMediaStore::pathFor($tenant, $name);

        if ($path === null) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', SocialMediaStore::mimeFor($name))
            ->setHeader('Content-Length', (string) filesize($path))
            // Immutable: the name is a content-unique random token, so a URL
            // never points at different bytes later.
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setBody(file_get_contents($path));
    }
}
