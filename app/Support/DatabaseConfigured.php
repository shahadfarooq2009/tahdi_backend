<?php

namespace App\Support;

use App\Exceptions\ServiceUnavailableException;
use Illuminate\Support\Facades\DB;

final class DatabaseConfigured
{
    public static function assert(): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new ServiceUnavailableException('قاعدة البيانات غير مُعدّة');
        }

        try {
            DB::select('select 1 as ok');
        } catch (\Throwable) {
            throw new ServiceUnavailableException('تعذر الاتصال بقاعدة البيانات');
        }
    }

    public static function check(): bool
    {
        if (config('database.default') !== 'pgsql') {
            return false;
        }

        try {
            DB::select('select 1 as ok');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
