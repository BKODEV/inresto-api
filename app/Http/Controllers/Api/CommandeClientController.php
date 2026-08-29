<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Statut;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeClient\EnvoyerEnCuisineRequest;
use App\Http\Requests\CommandeClient\IndexCommandeClientRequest;
use App\Http\Requests\CommandeClient\PretRequest;
use App\Http\Requests\CommandeClient\ServirRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientLigneRequest;
use App\Http\Requests\CommandeClient\StoreCommandeClientRequest;
use App\Http\Resources\CommandeClientResource;
use App\Http\Resources\LigneCommandeClientResource;
use App\Http\Resources\TableSalleResource;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\PointDeVente;
use App\Models\PosteCuisine;
use App\Services\CommandeClientService;
use App\Support\SitePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommandeClientController extends Controller
{
    /** Relations chargées pour une commande complète. */
    /**
     * `user` = le serveur qui a ouvert la note. Chargé pour le poste de caisse :
     * quand un serveur vient verser, le caissier filtre sur lui pour solder ses
     * tables.
     *
     * `encaissePar` = qui a clôturé la note. La colonne `encaisse_par` était
     * déjà servie, mais en identifiant : illisible sur un écran de caisse, là
     * où c'est justement la piste d'audit qu'on vient chercher.
     */
    private const RELATIONS = ['table', 'sessionCaisse', 'lignes.produit', 'user', 'encaissePar'];

    public function __construct(private readonly CommandeClientService $service) {}

    /**
     * Liste paginée des commandes, du plus récent au plus ancien.
     *
     * Paginée et filtrée en base, jamais côté client : sans cela, l'écran de
     * prise de commande retéléchargeait tout l'historique du restaurant —
     * lignes et produits compris — toutes les vingt secondes, pour n'en garder
     * que les quelques commandes vivantes.
     *
     * Filtres : `point_de_vente_id`, `table_id`, `session_caisse_id`,
     * `statut[]`, et une plage `date_debut`/`date_fin`.
     *
     * Le périmètre de site s'applique via la table, seul chemin entre une
     * commande et son site (commande → table → point de vente → site).
     */
    public function index(IndexCommandeClientRequest $request): JsonResponse
    {
        $filtres = $request->validated();
        $sites = SitePerimetre::resoudre($request->user(), null);

        $commandes = CommandeClient::query()
            ->with(self::RELATIONS)
            // Alimente `peut_etre_liberee` sans requête par ligne.
            ->withCount(['lignes', 'paiements'])
            ->when(
                $filtres['avec_paiements'] ?? false,
                fn (Builder $query) => $query->with('paiements.modePaiement')
            )
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereHas(
                    'table.pointDeVente',
                    fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
                )
            )
            ->when(
                isset($filtres['point_de_vente_id']),
                fn (Builder $query) => $query->whereHas(
                    'table',
                    fn (Builder $table) => $table->where('point_de_vente_id', $filtres['point_de_vente_id'])
                )
            )
            ->when(
                isset($filtres['table_id']),
                fn (Builder $query) => $query->where('table_id', $filtres['table_id'])
            )
            ->when(
                isset($filtres['session_caisse_id']),
                fn (Builder $query) => $query->where('session_caisse_id', $filtres['session_caisse_id'])
            )
            ->when(
                isset($filtres['statut']),
                fn (Builder $query) => $query->whereIn('statut', $filtres['statut'])
            )
            ->when(
                isset($filtres['date_debut']),
                fn (Builder $query) => $query->where('date_heure', '>=', Carbon::parse($filtres['date_debut'])->startOfDay())
            )
            ->when(
                isset($filtres['date_fin']),
                fn (Builder $query) => $query->where('date_heure', '<=', Carbon::parse($filtres['date_fin'])->endOfDay())
            )
            ->orderByDesc('id')
            ->paginate($filtres['per_page'] ?? 50);

        return ApiResponse::success([
            'commandes' => CommandeClientResource::collection($commandes->items()),
            'pagination' => [
                'total' => $commandes->total(),
                'par_page' => $commandes->perPage(),
                'page_courante' => $commandes->currentPage(),
                'derniere_page' => $commandes->lastPage(),
            ],
        ], 'Commandes client récupérées.');
    }

    public function store(StoreCommandeClientRequest $request): JsonResponse
    {
        $commande = $this->service->creer($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new CommandeClientResource($commande),
            'Commande client ouverte.'
        );
    }

    public function show(CommandeClient $commande): JsonResponse
    {
        $this->authorize('view', $commande);

        return ApiResponse::success(
            new CommandeClientResource(
                $commande->load(self::RELATIONS)->loadCount(['lignes', 'paiements'])
            ),
            'Commande client récupérée.'
        );
    }

    /**
     * Abandonne une commande ouverte que rien n'a jamais garnie.
     *
     * Gouverné par `commande_client.update` et non `.delete` : c'est le serveur
     * qui vient de taper la mauvaise table qui doit pouvoir défaire son geste,
     * or son rôle porte `update` (celle qui l'autorise déjà à ajouter et
     * retirer des lignes) et pas `delete`. Le garde-fou réel est dans le
     * service : une commande ayant porté quoi que ce soit est refusée en 409.
     */
    public function destroy(CommandeClient $commande): JsonResponse
    {
        $this->authorize('update', $commande);

        $this->service->liberer($commande);

        return ApiResponse::deleted('Table libérée.');
    }

    /**
     * Ajoute une ligne au prix de la carte active (commande ouverte uniquement).
     */
    public function ajouterLigne(StoreCommandeClientLigneRequest $request, CommandeClient $commande): JsonResponse
    {
        $ligne = $this->service->ajouterLigne($commande, $request->validated(), $request->user()->id);

        return ApiResponse::created(
            new LigneCommandeClientResource($ligne),
            'Ligne ajoutée à la commande.'
        );
    }

    /**
     * Envoie un tour en cuisine, panier compris.
     *
     * Geste unique de la prise de commande : les lignes du panier local partent
     * et sont expédiées en un seul appel, au lieu d'un aller-retour par plat.
     * Répétable — un repas se commande en plusieurs services.
     */
    public function envoyerEnCuisine(EnvoyerEnCuisineRequest $request, CommandeClient $commande): JsonResponse
    {
        $commande = $this->service->envoyerEnCuisine(
            $commande,
            $request->validated()['lignes'] ?? [],
            $request->user()->id,
        );

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Tour envoyé en cuisine.'
        );
    }

    /**
     * La cuisine annonce au pass : c'est prêt, la salle peut venir chercher.
     *
     * Ne déstocke rien et ne change pas le statut de la note — le client n'a
     * toujours rien devant lui. Voir CommandeClientService::marquerPret().
     */
    public function marquerPret(PretRequest $request, CommandeClient $commande): JsonResponse
    {
        $commande = $this->service->marquerPret($commande, $request->validated()['lignes'] ?? []);

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Plats annoncés prêts au pass.'
        );
    }

    /** Dévalide une annonce faite trop tôt : la ligne retourne en production. */
    public function annulerPret(PretRequest $request, CommandeClient $commande): JsonResponse
    {
        $commande = $this->service->annulerPret($commande, $request->validated()['lignes'] ?? []);

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Annonce dévalidée : retour en production.'
        );
    }

    /**
     * Transition envoyee_en_cuisine → servie (avec déstockage).
     */
    public function servir(ServirRequest $request, CommandeClient $commande): JsonResponse
    {
        $commande = $this->service->servir(
            $commande,
            $request->user()->id,
            $request->validated()['lignes'] ?? [],
        );

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Commande servie.'
        );
    }

    /**
     * Clôture (encaissement) : transition servie → encaissee, table à débarrasser.
     */
    public function encaisser(CommandeClient $commande): JsonResponse
    {
        $this->authorize('encaisser', $commande);

        $commande = $this->service->encaisser($commande, request()->user()->id);

        return ApiResponse::updated(
            new CommandeClientResource($commande),
            'Commande encaissée.'
        );
    }

    /**
     * KDS : file de production d'un point de vente, par tours.
     *
     * Interroge les LIGNES et non les commandes : une note vit plusieurs
     * services, et son statut ne dit pas ce qui reste à produire. Une ligne est
     * en production dès qu'elle est partie (`envoyee_le`) et tant qu'elle n'est
     * pas servie — les tours suivants d'une note déjà servie apparaissent donc
     * bien, ce que le filtre par statut de commande rendait impossible.
     *
     * Les lignes sont groupées par note puis par tour, du plus ancien au plus
     * récent : la cuisine produit dans l'ordre d'arrivée.
     *
     * La file s'arrête à l'ANNONCE, pas au service : une ligne annoncée prête
     * n'est plus le travail de la cuisine, elle attend la salle. Elle bascule
     * alors dans {@see pass()}. Y laisser les plats annoncés rendrait l'écran
     * illisible aux heures de rush, précisément quand il sert à quelque chose.
     */
    public function kds(PointDeVente $point_de_vente): JsonResponse
    {
        $this->authorize('viewAny', CommandeClient::class);

        $point_de_vente->loadMissing('site');
        SitePerimetre::resoudre(request()->user(), (int) $point_de_vente->site_id);

        $requete = LigneCommandeClient::query()
            ->where('statut', Statut::Actif->value)
            ->whereNotNull('envoyee_le')
            ->whereNull('prete_le')
            ->whereNull('servie_le')
            ->whereHas(
                'commande.table',
                fn (Builder $query) => $query->where('point_de_vente_id', $point_de_vente->id)
            )
            ->with(['produit.categorie', 'commande.table'])
            ->orderBy('envoyee_le')
            ->orderBy('id');

        $this->restreindreAuPoste(
            $requete,
            request()->integer('poste_cuisine_id') ?: null,
            (int) $point_de_vente->id,
        );

        $lignes = $requete->get();

        $tours = $lignes
            ->groupBy(fn (LigneCommandeClient $ligne): string => $ligne->commande_client_id.'|'.$ligne->envoyee_le?->toIso8601String())
            ->map(fn ($lignesDuTour): array => [
                'commande_id' => $lignesDuTour->first()->commande_client_id,
                'table' => new TableSalleResource($lignesDuTour->first()->commande->table),
                'envoyee_le' => $lignesDuTour->first()->envoyee_le?->toIso8601String(),
                'lignes' => LigneCommandeClientResource::collection($lignesDuTour->values()),
            ])
            ->values()
            ->all();

        return ApiResponse::success(
            ['tours' => $tours],
            'File KDS récupérée.'
        );
    }

    /**
     * Restreint une file au poste demandé, si un poste est demandé.
     *
     * RÈGLE DE SÛRETÉ, qui gouverne toute la fonction : un plat dont la
     * catégorie n'est rattachée à AUCUN poste — ou un produit sans catégorie —
     * reste visible de TOUS les écrans. Une configuration incomplète doit
     * dégrader vers « tout le monde le voit », jamais vers « personne ne le
     * voit ». Un plat invisible, c'est une table qu'on ne sert jamais, et
     * l'erreur ne se découvre qu'au moment où le client réclame.
     *
     * Corollaire heureux : un établissement qui ne déclare aucun poste retrouve
     * exactement l'écran d'avant, sans rien paramétrer.
     */
    private function restreindreAuPoste(Builder $lignes, ?int $posteId, int $pointDeVenteId): void
    {
        if ($posteId === null) {
            return;
        }

        /** @var PosteCuisine|null $poste */
        $poste = PosteCuisine::query()
            ->where('point_de_vente_id', $pointDeVenteId)
            ->find($posteId);

        // Poste inconnu, ou d'un autre pass : on ne filtre pas plutôt que de
        // renvoyer une file vide qui ferait croire à un service terminé.
        if ($poste === null) {
            return;
        }

        $categoriesDuPoste = $poste->categories()->pluck('categories_produit.id');

        // Les catégories rattachées à un poste QUELCONQUE de ce point de vente :
        // celles qui n'y figurent pas n'appartiennent à personne, donc à tous.
        $categoriesAffectees = DB::table('categorie_poste_cuisine')
            ->join('postes_cuisine', 'postes_cuisine.id', '=', 'categorie_poste_cuisine.poste_cuisine_id')
            ->where('postes_cuisine.point_de_vente_id', $pointDeVenteId)
            ->pluck('categorie_poste_cuisine.categorie_produit_id');

        $lignes->whereHas('produit', function (Builder $produit) use ($categoriesDuPoste, $categoriesAffectees): void {
            $produit->whereIn('categorie_id', $categoriesDuPoste)
                ->orWhereNull('categorie_id')
                ->orWhereNotIn('categorie_id', $categoriesAffectees);
        });
    }

    /**
     * Le PASS : ce qui est prêt et attend qu'on l'emporte en salle.
     *
     * Symétrique du KDS, mais pour l'autre métier — d'où le regroupement par
     * NOTE et non par tour. L'unité de travail du runner est un voyage vers une
     * table : deux tours prêts pour la table 12 se prennent en un seul
     * déplacement, les découper en deux tickets lui ferait faire le trajet deux
     * fois.
     *
     * Trié par annonce la plus ancienne : ce qui refroidit le plus longtemps
     * sous la lampe part en premier.
     */
    public function pass(PointDeVente $point_de_vente): JsonResponse
    {
        $this->authorize('viewAny', CommandeClient::class);

        $point_de_vente->loadMissing('site');
        SitePerimetre::resoudre(request()->user(), (int) $point_de_vente->site_id);

        $requete = LigneCommandeClient::query()
            ->where('statut', Statut::Actif->value)
            ->whereNotNull('prete_le')
            ->whereNull('servie_le')
            ->whereHas(
                'commande.table',
                fn (Builder $query) => $query->where('point_de_vente_id', $point_de_vente->id)
            )
            ->with(['produit.categorie', 'commande.table'])
            ->orderBy('prete_le')
            ->orderBy('id');

        $this->restreindreAuPoste(
            $requete,
            request()->integer('poste_cuisine_id') ?: null,
            (int) $point_de_vente->id,
        );

        $lignes = $requete->get();

        $services = $lignes
            ->groupBy(fn (LigneCommandeClient $ligne): int => $ligne->commande_client_id)
            ->map(fn ($lignesDeLaNote): array => [
                'commande_id' => $lignesDeLaNote->first()->commande_client_id,
                'table' => new TableSalleResource($lignesDeLaNote->first()->commande->table),
                // L'annonce la PLUS ANCIENNE de la note : c'est elle qui dit
                // depuis quand ça attend, donc ce qu'il faut emporter d'abord.
                'prete_le' => $lignesDeLaNote->first()->prete_le?->toIso8601String(),
                'lignes' => LigneCommandeClientResource::collection($lignesDeLaNote->values()),
            ])
            ->values()
            ->all();

        return ApiResponse::success(
            ['services' => $services],
            'File du pass récupérée.'
        );
    }
}
