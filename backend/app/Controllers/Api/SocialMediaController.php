<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Social\SocialMediaStore;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Upload a creative for a social post.
 *
 * Graph fetches a photo by URL, so whatever we store has to be reachable by
 * Meta's servers without a session. The upload itself is authenticated; the
 * public half lives in Public\SocialMediaController and is addressed by an
 * unguessable random filename. Anything posted here is on its way to a public
 * Facebook Page anyway.
 */
class SocialMediaController extends ResourceController
{
    protected $format = 'json';

    // POST /api/v1/social/media
    public function upload(): ResponseInterface
    {
        $file = $this->request->getFile('file');

        if (! $file || ! $file->isValid()) {
            return $this->fail('No valid file uploaded.', 422);
        }

        try {
            $stored = (new SocialMediaStore())->store($file, CurrentUser::tenantId());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            log_message('error', '[social media] store failed: ' . $e->getMessage());

            return $this->fail('Could not save that file. Please try again.', 500);
        }

        return $this->respond(['success' => true, 'data' => $stored]);
    }
}
