<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit léger : trace l'administrateur ayant créé / modifié un utilisateur.
     * Les deux colonnes sont nullables et passent à NULL si l'auteur est supprimé
     * (on ne casse pas la ligne auditée).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('created_by')->nullable()->after('last_login_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
