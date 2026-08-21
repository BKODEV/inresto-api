<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pourboire laissé au serveur, distinct de la vente.
 *
 * Trois montants cohabitent désormais sur un paiement, et ils répondent à trois
 * questions différentes :
 *
 *   montant       ce qui SOLDE la note — la vente, donc le chiffre d'affaires
 *   pourboire     ce qui reste en tiroir sans appartenir à l'établissement
 *   montant_recu  le billet tendu, dont on déduit la monnaie à rendre
 *
 * Note de 7 500, le client pose 10 000 et laisse 1 000 : montant 7 500,
 * pourboire 1 000, montant_recu 10 000, monnaie rendue 1 500.
 *
 * Le pourboire DOIT entrer dans le montant théorique de la session — il est
 * physiquement dans le tiroir, et l'en exclure le ferait ressortir comme un
 * excédent de caisse, c'est-à-dire comme un signal de vente non enregistrée.
 * Il ne doit EN REVANCHE jamais compter comme recette : le chiffre d'affaires
 * se lit sur les lignes de commande, où un pourboire n'a rien à faire.
 *
 * Le bénéficiaire ne demande aucune colonne : c'est `commande.user_id`, le
 * serveur qui tient la note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table): void {
            $table->decimal('pourboire', 12, 2)->default(0)->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table): void {
            $table->dropColumn('pourboire');
        });
    }
};
