<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TagModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class TagsController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $model = (new TagModel())->setTenant(CurrentUser::tenantId());
        $tags  = $model->findAll();

        // The UI shows a per-tag contact count; without it every tag reads
        // "0 contacts" no matter how many contacts carry it.
        $counts = [];
        if ($tags !== []) {
            $rows = db_connect()->table('contact_tags ct')
                ->select('ct.tag_id, COUNT(DISTINCT ct.contact_id) AS c')
                ->join('contacts c', 'c.id = ct.contact_id AND c.deleted_at IS NULL', 'inner')
                ->whereIn('ct.tag_id', array_map(static fn ($t) => (int) $t['id'], $tags))
                ->groupBy('ct.tag_id')
                ->get()
                ->getResultArray();
            $counts = array_column($rows, 'c', 'tag_id');
        }

        foreach ($tags as &$tag) {
            $tag['contact_count'] = (int) ($counts[$tag['id']] ?? 0);
        }
        unset($tag);

        return $this->respond(['success' => true, 'data' => $tags]);
    }

    public function create(): ResponseInterface
    {
        $model = (new TagModel())->setTenant(CurrentUser::tenantId());

        $rules = ['name' => 'required|max_length[50]', 'color' => 'permit_empty|max_length[7]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $id = $model->insert([
            'name'  => $this->request->getJsonVar('name'),
            'color' => $this->request->getJsonVar('color') ?? '#6366f1',
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = (new TagModel())->setTenant(CurrentUser::tenantId());
        $tag   = $model->find((int) $id);
        if (! $tag) {
            return $this->failNotFound("Tag #{$id} not found.");
        }

        $payload = array_filter([
            'name'  => $this->request->getJsonVar('name'),
            'color' => $this->request->getJsonVar('color'),
        ], static fn ($v) => $v !== null);

        $model->setTenant(CurrentUser::tenantId())->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = (new TagModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Tag #{$id} not found.");
        }
        $model->setTenant(CurrentUser::tenantId())->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }
}
