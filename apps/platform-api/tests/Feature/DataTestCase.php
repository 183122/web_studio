<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppDefinitionService;
use Tests\TestCase;

abstract class DataTestCase extends TestCase
{
    protected User $owner;

    protected Workspace $ws;

    protected Project $project;

    public const MODEL = [
        'id' => 'm_reg', 'name' => 'registrations', 'label' => 'ผู้สมัคร', 'displayFieldId' => 'f_name',
        'fields' => [
            ['id' => 'f_name', 'name' => 'full_name', 'label' => 'ชื่อ', 'type' => 'text', 'required' => true, 'unique' => false, 'maxLength' => 120],
            ['id' => 'f_email', 'name' => 'email', 'label' => 'อีเมล', 'type' => 'email', 'required' => true, 'unique' => true],
            ['id' => 'f_seats', 'name' => 'seats', 'label' => 'ที่นั่ง', 'type' => 'integer', 'required' => false, 'unique' => false, 'min' => 1, 'max' => 100],
            ['id' => 'f_status', 'name' => 'status', 'label' => 'สถานะ', 'type' => 'select', 'required' => false, 'unique' => false, 'options' => ['pending', 'confirmed', 'cancelled']],
            ['id' => 'f_vip', 'name' => 'vip', 'label' => 'VIP', 'type' => 'boolean', 'required' => false, 'unique' => false],
        ],
    ];

    public const RULES = ['public' => ['create'], 'member' => ['read'], 'admin' => ['create', 'read', 'update', 'delete']];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->ws] = $this->tenant();
        $this->project = $this->project($this->owner, $this->ws);
        $this->applyCommands([
            ['type' => 'model.upsert', 'index' => 0, 'model' => self::MODEL],
            ['type' => 'permission.set', 'permission' => ['modelId' => 'm_reg', 'rules' => self::RULES]],
        ]);
    }

    /** Applies commands through the real service (validation included). */
    protected function applyCommands(array $commands): int
    {
        $svc = app(AppDefinitionService::class);
        $head = $svc->head($this->project);
        $objects = json_decode(json_encode($commands), false);

        return $svc->applyCommands($this->project, $head['revision'], $objects, $this->owner)['revision'];
    }

    protected function records(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}/data/m_reg/records{$suffix}";
    }

    protected function createRecord(array $data): array
    {
        return $this->actingAs($this->owner)->postJson($this->records(), ['data' => $data])->assertCreated()->json('data');
    }
}
