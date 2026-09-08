<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Trusted extension since Postgres 13 — installable without superuser
        // as long as the connecting user has CREATE on the schema.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Speeds up both `ILIKE '%term%'` and trigram similarity() lookups
        // used to match free-text shopping-list items to product names.
        DB::statement('CREATE INDEX products_name_trgm_idx ON products USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
