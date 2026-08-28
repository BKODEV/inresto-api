<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * À qui ce versement éteint une dette.
 *
 * `remis_a` dit qui a EMPORTÉ l'argent — une société de collecte, un convoyeur,
 * un comptable. C'est du texte libre, et c'est très bien pour ça.
 *
 * Reverser un pourboire pose une autre question : de quel SOLDE cet argent se
 * retire-t-il ? Un nom écrit à la main ne permet pas d'y répondre — « Kouassi »,
 * « K. Aké » et « kouassi ake » ne se recoupent pas. D'où un vrai lien vers
 * l'utilisateur, qui seul rend calculable ce qui reste dû à chacun.
 *
 * Nullable, car la plupart des destinations n'ont aucun solde à diminuer : un
 * coffre ne doit rien à personne. La règle « obligatoire pour le personnel,
 * interdit ailleurs » vit dans le FormRequest, où un lien posé par erreur sur
 * un dépôt bancaire aurait silencieusement effacé la dette d'un serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versements', function (Blueprint $table): void {
            $table->foreignUuid('beneficiaire_id')->nullable()->after('user_id')
                ->constrained('users')->restrictOnDelete();
        });

        $this->reposerIndexVersements();
    }

    public function down(): void
    {
        Schema::table('versements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('beneficiaire_id');
        });

        $this->reposerIndexVersements();
    }

    /**
     * Ajouter une clé étrangère fait reconstruire la table sous SQLite, et la
     * reconstruction recrée les index depuis l'introspection de Laravel. L'index
     * de lecture n'a pas de clause à perdre, mais on le repose explicitement
     * plutôt que de dépendre de ce que le moteur aura bien voulu conserver.
     */
    private function reposerIndexVersements(): void
    {
        DB::statement('DROP INDEX IF EXISTS versements_caisse_id_date_heure_index');
        DB::statement('CREATE INDEX versements_caisse_id_date_heure_index ON versements (caisse_id, date_heure)');
    }
};
