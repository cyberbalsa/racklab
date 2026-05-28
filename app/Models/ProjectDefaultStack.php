<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $stack_definition_id
 * @property string|null $active_deployment_id
 */
#[Fillable([
    'tenant_id',
    'project_id',
    'stack_definition_id',
    'active_deployment_id',
])]
class ProjectDefaultStack extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<StackDefinition, $this>
     */
    public function stackDefinition(): BelongsTo
    {
        return $this->belongsTo(StackDefinition::class);
    }
}
