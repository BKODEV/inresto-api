<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrichit la table `roles` de Spatie avec une hiérarchie de sécurité
     * (`niveau`), une description humaine et un marqueur `is_system` (rôle livré,
     * non supprimable). Altération pure : aucun impact sur Spatie Permission.
     */
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->text('description')->nullable()->after('guard_name');
            $table->integer('niveau')->default(0)->after('description');
            $table->boolean('is_system')->default(false)->after('niveau');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn(['description', 'niveau', 'is_system']);
        });
    }

    private function table(): string
    {
        return config('permission.table_names.roles', 'roles');
    }
};
