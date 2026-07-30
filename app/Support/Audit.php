<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $action, Model $model, array $old = [], array $new = []): void
    {
        $sensitive = ['password', 'remember_token', 'api_token_hash', 'credential', 'pin', 'code'];
        $filter = fn (array $values) => collect($values)->reject(
            fn ($value, $key) => collect($sensitive)->contains(fn ($word) => str_contains(strtolower((string) $key), $word))
        )->all();

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $filter($old),
            'new_values' => $filter($new),
            'ip_address' => request()->ip(),
        ]);
    }
}
