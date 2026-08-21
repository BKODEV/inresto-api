<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutSessionCaisse;
use Database\Factories\SessionCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session de caisse : période entre l'ouverture (fonds initial) et la fermeture
 * (rapprochement réel/théorique). `montant_theorique`, `montant_reel`, `ecart`
 * et `ferme_le` sont figés par le SessionCaisseService à la fermeture.
 *
 * Le rapprochement ne porte que sur le TIROIR : seuls les paiements dont le
 * mode est marqué `compte_en_caisse` entrent dans le théorique, puisque seuls
 * ceux-là se retrouvent dans l'argent qu'on compte.
 */
class SessionCaisse extends Model
{
    /** @use HasFactory<SessionCaisseFactory> */
    use HasFactory;

    protected $table = 'sessions_caisse';

    protected $fillable = [
        'caisse_id',
        'user_id',
        'fonds_initial',
        'notes',
    ];

    protected $attributes = [
        'fonds_initial' => 0,
        'statut' => StatutSessionCaisse::Ouverte->value,
    ];

    protected $casts = [
        'ouvert_le' => 'datetime',
        'ferme_le' => 'datetime',
        'fonds_initial' => 'decimal:2',
        'montant_theorique' => 'decimal:2',
        'montant_reel' => 'decimal:2',
        'ecart' => 'decimal:2',
        'statut' => StatutSessionCaisse::class,
    ];

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estOuverte(): bool
    {
        return $this->statut === StatutSessionCaisse::Ouverte;
    }

    /**
     * Argent de la session qui atterrit dans le TIROIR, et lui seul. Alimente
     * `montant_theorique` à la fermeture.
     *
     * Anciennement `montantPaye()`, qui additionnait tous les modes : le
     * théorique comptait alors les virements et les chèques, que personne ne
     * retrouve en comptant le tiroir. L'écart affichait un manquant à chaque
     * fermeture, exactement égal au total des règlements hors tiroir. Le nom a
     * changé avec le calcul — le garder aurait laissé le piège en place.
     *
     * Ne pas confondre avec {@see CommandeClient::montantPaye()}, qui doit
     * continuer d'additionner TOUS les modes : une note réglée par virement est
     * réglée, et l'y filtrer rendrait les notes inclôturables.
     */
    public function montantEnCaisse(): float
    {
        return $this->sommePaiements(true);
    }

    /**
     * Le complément : encaissé sur la session, mais jamais passé par le tiroir.
     * Sert à expliquer, à la fermeture, l'écart entre ce qui a été encaissé et
     * ce qui est compté.
     */
    public function montantHorsCaisse(): float
    {
        return $this->sommePaiements(false);
    }

    /**
     * Qualifie l'écart de fermeture, tolérance comprise.
     *
     * Le signe seul ne suffit pas à décider quoi faire : un MANQUANT est de
     * l'argent parti (erreur de rendu, paiement non saisi, vol), un EXCÉDENT
     * signale plutôt une vente encaissée sans note ouverte. Les afficher du même
     * rouge revenait à ne rien dire.
     *
     * @param  float  $tolerance  Montant sous lequel l'écart est réputé normal.
     *                            0 = règle désactivée.
     * @return 'juste'|'tolere'|'excedent'|'manquant'
     */
    public function sensEcart(float $tolerance = 0.0): string
    {
        $ecart = round((float) $this->ecart, 2);

        if ($ecart === 0.0) {
            return 'juste';
        }

        if ($tolerance > 0 && abs($ecart) <= $tolerance) {
            return 'tolere';
        }

        return $ecart > 0 ? 'excedent' : 'manquant';
    }

    private function sommePaiements(bool $enCaisse): float
    {
        return round((float) Paiement::query()
            ->whereHas('commande', fn ($query) => $query->where('session_caisse_id', $this->id))
            ->whereHas('modePaiement', fn ($query) => $query->where('compte_en_caisse', $enCaisse))
            ->sum('montant'), 2);
    }
}
