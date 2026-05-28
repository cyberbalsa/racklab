<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Attributes\Untenanted;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $display_name
 * @property string $locale
 */
#[Untenanted(reason: 'user profile follows identity; tenant membership is modeled separately')]
#[Fillable(['user_id', 'display_name', 'locale'])]
class UserProfile extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
