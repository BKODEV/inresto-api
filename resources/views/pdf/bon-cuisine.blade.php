@php
    use App\Enums\TypeBonCuisine;

    /**
     * Bons de cuisine 80mm — un bon par page, pour que l'imprimante coupe
     * entre deux postes et que le serveur dépose chaque morceau au bon endroit.
     *
     * Lu debout, à un mètre, les mains prises : le POSTE en tête sur fond noir
     * (c'est ce qui trie les papiers tant qu'ils sortent tous à la caisse), les
     * quantités et les remarques en gros. Aucun prix : la cuisine n'en a pas
     * l'usage. Les plats servis ou annulés avant l'impression n'y figurent pas.
     *
     * @var \Illuminate\Support\Collection<int, \App\Models\BonCuisine> $bons
     * @var array<int, bool> $duplicatas  identifiants des bons déjà imprimés
     */
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bon de cuisine</title>
    <style>
        @page { size: 80mm auto; margin: 4px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #000; margin: 0; width: 72mm; }
        .bon { page-break-after: always; }
        .bon:last-child { page-break-after: auto; }
        .poste { background: #000; color: #fff; text-align: center; font-size: 18px; font-weight: bold; padding: 4px 2px; text-transform: uppercase; }
        .alerte { text-align: center; font-size: 16px; font-weight: bold; border: 2px solid #000; padding: 2px; margin-top: 4px; }
        .duplicata { text-align: center; font-size: 12px; font-weight: bold; letter-spacing: 2px; margin-top: 4px; }
        .entete { margin-top: 4px; }
        .entete td { padding: 1px 0; }
        .grand { font-size: 15px; font-weight: bold; }
        .droite { text-align: right; }
        .sep { border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        .qte { width: 12mm; font-size: 16px; font-weight: bold; vertical-align: top; }
        .plat { font-size: 14px; font-weight: bold; vertical-align: top; padding-bottom: 3px; }
        .barre { text-decoration: line-through; }
        .remarque { font-size: 13px; font-weight: bold; padding: 0 0 4px 12mm; }
        .pied { text-align: center; font-size: 9px; margin-top: 4px; }
    </style>
</head>
<body>
@foreach ($bons as $bon)
    @php
        $estAnnulation = $bon->type === TypeBonCuisine::Annulation;
        $serveur = $bon->commande?->user ? trim($bon->commande->user->prenom.' '.$bon->commande->user->nom) : null;
    @endphp
    <div class="bon">
        <div class="poste">{{ $bon->poste_libelle }}</div>

        {{-- Un ajout ou une annulation se reconnaît sans lire le détail : la
             table mange déjà, ou il faut arrêter quelque chose. --}}
        @if ($bon->type !== TypeBonCuisine::Envoi)
            <div class="alerte">*** {{ mb_strtoupper($bon->type->libelle()) }} ***</div>
        @endif

        {{-- Un bon ressorti ne doit pas être pris pour une nouvelle commande. --}}
        @if ($duplicatas[$bon->id] ?? false)
            <div class="duplicata">— DUPLICATA —</div>
        @endif

        <table class="entete">
            <tr>
                <td class="grand">Table {{ $bon->commande?->table_numero ?? '—' }}</td>
                <td class="grand droite">N° {{ $bon->numero }}</td>
            </tr>
            <tr>
                <td>{{ $bon->emis_le?->format('H:i') }}@if ($serveur) · {{ $serveur }}@endif</td>
                {{-- Le poste sait que la table attend aussi d'autres préparations. --}}
                <td class="droite">@if ($bon->parties > 1) Bon {{ $bon->partie }}/{{ $bon->parties }} @endif</td>
            </tr>
        </table>

        <div class="sep"></div>

        <table>
            @foreach ($bon->lignesAImprimer as $ligne)
                <tr>
                    <td class="qte">{{ $ligne->quantite }}×</td>
                    <td class="plat {{ $estAnnulation ? 'barre' : '' }}">{{ $ligne->produit_libelle }}</td>
                </tr>
                @if ($ligne->commentaire)
                    <tr>
                        <td class="remarque" colspan="2">» {{ $ligne->commentaire }}</td>
                    </tr>
                @endif
            @endforeach
        </table>

        <div class="sep"></div>

        <div class="pied">{{ $bon->emis_le?->format('d/m/Y H:i') }} · note {{ $bon->commande_client_id }}</div>
    </div>
@endforeach
</body>
</html>
