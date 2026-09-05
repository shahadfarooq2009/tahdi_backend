<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $subjects = DB::table('subjects')
            ->select(['id', 'name'])
            ->whereRaw('name <> TRIM(name)')
            ->get();

        foreach ($subjects as $subject) {
            $trimmed = trim((string) $subject->name);

            if ($trimmed === '' || $trimmed === $subject->name) {
                continue;
            }

            $conflict = DB::table('subjects')
                ->where('id', '!=', $subject->id)
                ->where('name', $trimmed)
                ->exists();

            if ($conflict) {
                continue;
            }

            DB::table('subjects')
                ->where('id', $subject->id)
                ->update([
                    'name' => $trimmed,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-reversible data cleanup.
    }
};
