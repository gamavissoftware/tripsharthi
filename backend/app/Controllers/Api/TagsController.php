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

        $name  = trim((string) $this->request->getJsonVar('name'));
        $color = $this->request->getJsonVar('color') ?? '#6366f1';
        // (tenant, name) is unique, including tags that were deleted (soft delete keeps the row): a plain insert answered 500.
        $same = db_connect()->table('tags')->select('id, deleted_at')->where('tenant_id', CurrentUser::tenantId())->where('name', $name)->get()->getRowArray();
        if ($same) {
            if ($same['deleted_at'] === null) {
                return $this->fail(['name' => "A tag called \"{$name}\" already exists."], 409);
            }
            db_connect()->table('tags')->where('id', (int) $same['id'])->update(['deleted_at' => null, 'color' => $color]);   // bring the deleted tag back
            return $this->respondCreated(['success' => true, 'data' => $model->find((int) $same['id'])]);
        }
        $id = $model->insert(['name' => $name, 'color' => $color], true);

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

        if (isset($payload['name'])) {
            $payload['name'] = trim((string) $payload['name']);
            $clash = db_connect()->table('tags')->select('id')->where('tenant_id', CurrentUser::tenantId())->where('name', $payload['name'])->where('id !=', (int) $id)->get()->getRowArray();
            if ($clash) { return $this->fail(['name' => "A tag called \"{$payload['name']}\" already exists."], 409); }
        }

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
