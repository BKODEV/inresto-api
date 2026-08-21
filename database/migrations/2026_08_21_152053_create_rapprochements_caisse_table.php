<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapprochement de fermeture, mode de paiement par mode de paiement.
 *
 * Un mode de paiement désigne un CONTENANT : les espèces vont dans le tiroir,
 * la carte dans le terminal, les chèques dans la pochette, le virement à la
 * banque. Rapprocher, c'est comparer ce que le système dit que le contenant
 * devrait contenir à ce que quelqu'un y constate.
 *
 * `sessions_caisse.montant_reel` ne portait qu'UN contenant, le tiroir. Une
 * transaction tapée sur le terminal sans note ouverte — ou l'inverse — ne
 * touchait donc pas l'écart de caisse et ne se voyait qu'au relevé bancaire,
 * des jours plus tard.
 *
 * `montant_constate` nullable : tous les contenants ne sont pas vérifiables le
 * soir même. Un virement arrive à la banque quand il arrive ; prétendre le
 * constater fabriquerait un faux écart chaque soir. Null = non rapproché, et
 * le rapport le dit au lieu d'inventer un chiffre.
 *
 * Une ligne par mode ACTIF, et non par mode ayant servi : sans cela, une carte
 * encaissée sur le terminal mais jamais enregistrée n'aurait aucune ligne où
 * apparaître — précisément l'erreur qu'on cherche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapprochements_caisse', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_caisse_id')->constrained('sessions_caisse')->cascadeOnDelete();
            $table->foreignId('mode_de_paiement_id')->constrained('modes_de_paiement')->restrictOnDelete();

            // Figés à la fermeture, comme les montants de la session : un
            // rapprochement est une pièce d'audit, il ne se recalcule pas.
            $table->decimal('montant_theorique', 12, 2)->default(0);
            $table->decimal('montant_constate', 12, 2)->nullable();
            $table->decimal('ecart', 12, 2)->nullable();

            $table->timestamps();

            $table->unique(['session_caisse_id', 'mode_de_paiement_id'], 'rapprochement_unique_par_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapprochements_caisse');
    }
};
