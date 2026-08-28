<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argent SORTI du tiroir : le versement au coffre, à la banque, à la direction.
 *
 * Le théorique d'une session valait `fonds + encaissé`, sans aucun terme de
 * sortie. Porter une partie de la recette au coffre en plein service creusait
 * donc un manquant fantôme exactement égal à la somme sortie ; et entre deux
 * sessions, le mouvement ne laissait aucune trace du tout.
 *
 * `session_caisse_id` désigne toujours une session — celle qui était ouverte,
 * ou la dernière fermée quand le versement a lieu entre deux services. Les deux
 * cas ne comptent pas au même endroit :
 *
 *   pendant le service  → se retranche du théorique de cette session
 *   après la fermeture  → explique l'écart d'ouverture de la session suivante
 *
 * `apres_fermeture` enregistre lequel des deux, au lieu de le redéduire en
 * comparant `date_heure` à `ferme_le` : ces colonnes sont à la seconde, et un
 * caissier qui verse dans la foulée de sa clôture tombait alors du mauvais côté
 * — le versement n'était ni retranché du théorique déjà figé, ni compté dans la
 * transition. Le service, lui, sait sans ambiguïté si la session était ouverte.
 *
 * Table en ajout seul : un mouvement d'argent constaté ne se réécrit pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('caisse_id')->constrained('caisses')->restrictOnDelete();
            $table->foreignId('session_caisse_id')->constrained('sessions_caisse')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            $table->decimal('montant', 12, 2);
            $table->boolean('apres_fermeture')->default(false);
            $table->enum('destination', ['coffre', 'banque', 'direction', 'autre']);
            // Qui a reçu l'argent. Utile surtout pour « direction », où le
            // coffre ne signe pas de reçu.
            $table->string('remis_a')->nullable();
            // Bordereau de dépôt bancaire, pendant de `paiements.reference_externe`.
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('date_heure')->useCurrent();
            $table->timestamps();

            $table->index(['caisse_id', 'date_heure']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements');
    }
};
