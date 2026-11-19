<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait GeneratesStableCode
{
    protected static function bootGeneratesStableCode(): void
    {
        static::creating(function (self $model): void {
            $field = static::CODE_FIELD;
            if (filled($model->{$field})) {
                return;
            }
            $base = substr(Str::slug((string) $model->{static::CODE_LABEL}, '_'), 0, 180) ?: 'item';
            $code = $base;
            $number = 2;
            while (static::where($field, $code)->exists()) {
                $code = $base.'_'.$number++;
            }
            $model->{$field} = $code;
        });
        static::updating(function (self $model): void {
            if ($model->isDirty(static::CODE_FIELD)) {
                throw ValidationException::withMessages([static::CODE_FIELD => 'Mã kỹ thuật được giữ ổn định sau khi tạo.']);
            }
        });
    }
}
