<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use App\Enums\StatutCommandeClient;
use App\Enums\StatutSessionCaisse;
use Database\Factories\SessionCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        'ecart_ouverture' => 'decimal:2',
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

    /** Qui a signé la fermeture — pas forcément qui a ouvert (relève d'équipe). */
    public function fermePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ferme_par');
    }

    /**
     * Notes rattachées à cette session. Le lien n'existait que dans l'autre
     * sens (`CommandeClient::sessionCaisse()`), ce qui obligeait à écrire un
     * `where('session_caisse_id', ...)` partout.
     */
    public function commandes(): HasMany
    {
        return $this->hasMany(CommandeClient::class);
    }

    /**
     * Notes servies dont le règlement manque encore. Elles bloquent la
     * fermeture : le client a consommé et n'a pas payé, laisser filer sans
     * décision effacerait l'impayé.
     *
     * @return Collection<int, CommandeClient>
     */
    public function notesServiesNonReglees(): Collection
    {
        return $this->commandes()
            ->where('statut', StatutCommandeClient::Servie->value)
            ->with('table')
            ->orderBy('id')
            ->get();
    }

    /**
     * Notes encore en cours de service, qui n'ont donc rien à régler. Elles ne
     * bloquent pas : une table qui mange encore à l'heure de la relève est
     * normale, et sa note suivra sur la session suivante.
     *
     * @return Collection<int, CommandeClient>
     */
    public function notesEnCoursDeService(): Collection
    {
        return $this->commandes()
            ->whereIn('statut', [
                StatutCommandeClient::Ouverte->value,
                StatutCommandeClient::EnvoyeeEnCuisine->value,
            ])
            ->get();
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
        return round($this->sommePaiements(true) - $this->montantVerseEnService(), 2);
    }

    /** Versements rattachés à cette session, en service comme après fermeture. */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class, 'session_caisse_id');
    }

    /**
     * Argent sorti du tiroir PENDANT le service. Se retranche du théorique :
     * il a quitté la caisse, personne ne le retrouvera en comptant.
     */
    public function montantVerseEnService(): float
    {
        return round((float) $this->versements()->where('apres_fermeture', false)->sum('montant'), 2);
    }

    /**
     * Argent sorti APRÈS la fermeture, en route vers la session suivante. Ne
     * touche pas au rapprochement de celle-ci — il explique celui de l'autre.
     */
    public function montantVerseApresFermeture(): float
    {
        return round((float) $this->versements()->where('apres_fermeture', true)->sum('montant'), 2);
    }

    /** Rapprochements figés à la fermeture, un par mode de paiement actif. */
    public function rapprochements(): HasMany
    {
        return $this->hasMany(RapprochementCaisse::class, 'session_caisse_id');
    }

    /**
     * Ce que chaque contenant devrait contenir, mode par mode.
     *
     * Tous les modes ACTIFS y figurent, y compris ceux qui n'ont rien encaissé :
     * une carte passée sur le terminal sans note ouverte doit avoir une ligne où
     * apparaître, sinon l'erreur reste invisible.
     *
     * Le fonds initial est de l'argent liquide : il revient au premier mode en
     * tiroir, dans l'ordre d'affichage — « Espèces » en pratique. Le rattacher à
     * un mode plutôt qu'au tiroir en bloc permet de comparer chaque contenant à
     * ce qu'on y compte, le caissier ne pouvant pas séparer le fonds du reste
     * des espèces qu'il a en main.
     *
     * Les versements faits en service s'en retranchent, au même endroit et pour
     * la même raison : cet argent est sorti du tiroir, on ne le comptera pas.
     *
     * @return array<int, array{mode: ModeDePaiement, theorique: float}>
     */
    public function theoriqueParMode(): array
    {
        $modes = ModeDePaiement::query()
            ->where('statut', Statut::Actif->value)
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        $encaisse = Paiement::query()
            ->whereHas('commande', fn ($query) => $query->where('session_caisse_id', $this->id))
            ->groupBy('mode_de_paiement_id')
            ->selectRaw('mode_de_paiement_id, SUM(montant + pourboire) as total')
            ->pluck('total', 'mode_de_paiement_id');

        $porteurDuFonds = $modes->first(fn (ModeDePaiement $mode): bool => $mode->compte_en_caisse)?->id;

        return $modes->map(fn (ModeDePaiement $mode): array => [
            'mode' => $mode,
            'theorique' => round(
                (float) ($encaisse[$mode->id] ?? 0)
                + ($mode->id === $porteurDuFonds
                    ? (float) $this->fonds_initial - $this->montantVerseEnService()
                    : 0.0),
                2
            ),
        ])->all();
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
     * @return 'non_rapproche'|'juste'|'tolere'|'excedent'|'manquant'
     */
    public function sensEcart(float $tolerance = 0.0): string
    {
        // Personne n'a compté le tiroir : on n'a rien à qualifier. Renvoyer
        // « juste » laisserait croire à une vérification qui n'a pas eu lieu.
        if ($this->ecart === null) {
            return 'non_rapproche';
        }

        $ecart = round((float) $this->ecart, 2);

        if ($ecart === 0.0) {
            return 'juste';
        }

        if ($tolerance > 0 && abs($ecart) <= $tolerance) {
            return 'tolere';
        }

        return $ecart > 0 ? 'excedent' : 'manquant';
    }

    /**
     * Pourboires de la session, par serveur bénéficiaire.
     *
     * Ils sont dans le tiroir et comptent donc dans le théorique, mais ils
     * n'appartiennent pas à l'établissement : c'est la liste qu'on lit à la
     * fermeture pour savoir combien reverser, et à qui.
     *
     * @return array<int, array{serveur: string, montant: float}>
     */
    public function pourboiresParServeur(): array
    {
        return Paiement::query()
            ->join('commandes_client as cc', 'cc.id', '=', 'paiements.commande_client_id')
            ->join('users as u', 'u.id', '=', 'cc.user_id')
            ->where('cc.session_caisse_id', $this->id)
            ->where('paiements.pourboire', '>', 0)
            ->groupBy('u.id', 'u.prenom', 'u.nom')
            ->orderByDesc('montant')
            ->selectRaw("TRIM(CONCAT(u.prenom, ' ', u.nom)) as serveur, SUM(paiements.pourboire) as montant")
            ->get()
            ->map(fn ($ligne): array => [
                'serveur' => (string) $ligne->serveur,
                'montant' => round((float) $ligne->montant, 2),
            ])
            ->all();
    }

    /** Total des pourboires de la session, tous serveurs confondus. */
    public function totalPourboires(): float
    {
        return round((float) Paiement::query()
            ->whereHas('commande', fn ($query) => $query->where('session_caisse_id', $this->id))
            ->sum('pourboire'), 2);
    }

    /**
     * Le pourboire est ajouté au montant : il est physiquement dans le tiroir.
     * L'en exclure le ferait ressortir comme un excédent de caisse à la
     * fermeture, c'est-à-dire comme un signal de vente non enregistrée. Il ne
     * devient pas pour autant de la recette — le chiffre d'affaires se lit sur
     * les lignes de commande, jamais ici.
     */
    private function sommePaiements(bool $enCaisse): float
    {
        return round((float) Paiement::query()
            ->whereHas('commande', fn ($query) => $query->where('session_caisse_id', $this->id))
            ->whereHas('modePaiement', fn ($query) => $query->where('compte_en_caisse', $enCaisse))
            ->sum(DB::raw('montant + pourboire')), 2);
    }
}
