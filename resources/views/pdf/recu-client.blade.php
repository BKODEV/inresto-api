@php
    use App\Enums\TypeImpression;
    use App\Helpers\Devise;
    $devise = $parametre?->devise;
    $lignesActives = $commande->lignes->filter(fn ($ligne) => $ligne->estActive());
    $reste = $commande->resteAPayer();

    // Deux états d'un seul ticket. L'addition DEMANDE le paiement, le reçu le
    // PROUVE : c'est le même papier à deux moments du service, pas deux
    // gabarits à maintenir en parallèle.
    $estAddition = $type === TypeImpression::Addition;

    // Ce que le client a effectivement tendu. `montant` est ce qui RESTE en
    // tiroir ; `montant_recu` le billet posé sur le comptoir. À défaut du
    // second, le premier fait foi — c'est le cas d'une carte, et celui des
    // paiements enregistrés avant que la colonne n'existe, où un surpaiement
    // portait la monnaie.
    $totalTendu = $commande->paiements->sum(fn ($p) => (float) ($p->montant_recu ?? $p->montant));

    // Ce que le client a laissé au serveur : dans le tiroir, mais pas rendu.
    $pourboire = round((float) $commande->paiements->sum('pourboire'), 2);

    // Positive uniquement quand il y a eu de la monnaie à rendre. N'a de sens
    // que sur le reçu : une addition qui annoncerait la monnaie serait absurde.
    //
    // Le pourboire se retranche du tendu, sans quoi le ticket annoncerait au
    // client une monnaie qu'il ne recevra pas — il l'a laissée.
    $monnaie = round($totalTendu - $commande->montantDu() - $pourboire, 2);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu</title>
    <style>
        @page { size: 80mm auto; margin: 4px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #000; margin: 0; width: 72mm; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .nom { font-size: 13px; font-weight: bold; }
        .sep { border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .num { text-align: right; }
        .total { font-size: 12px; font-weight: bold; }
        .titre { font-size: 12px; font-weight: bold; letter-spacing: 1px; margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="center">
        @if ($parametre)
            <div class="nom">{{ $parametre->nom_commercial }}</div>
            @if ($parametre->adresse)
                <div>{{ $parametre->adresse }}</div>
            @endif
            @if ($parametre->telephone)
                <div>Tél. {{ $parametre->telephone }}</div>
            @endif
        @else
            <div class="nom">Paramètres établissement non configurés</div>
        @endif
    </div>

    <div class="sep"></div>

    <div class="center titre">{{ $estAddition ? 'ADDITION' : 'REÇU' }}</div>
    <div>N° {{ $commande->id }}</div>
    <div>{{ optional($commande->date_heure)->format('d/m/Y H:i') }}</div>
    @if ($commande->table)
        <div>Table : {{ $commande->table_numero ?? $commande->table->numero }}</div>
    @endif
    @if ($commande->user)
        <div>Serveur : {{ trim($commande->user->prenom.' '.$commande->user->nom) }}</div>
    @endif

    <div class="sep"></div>

    <table>
        @foreach ($lignesActives as $ligne)
            <tr>
                <td>{{ $ligne->quantite }} × {{ $ligne->produit?->libelle }}</td>
                <td class="num">{{ Devise::formater((float) $ligne->quantite * (float) $ligne->prix_unitaire, $devise) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="sep"></div>

    <table>
        <tr class="total">
            <td>{{ $estAddition ? 'TOTAL À PAYER' : 'TOTAL' }}</td>
            <td class="num">{{ Devise::formater($commande->montantDu(), $devise) }}</td>
        </tr>

        {{-- Une addition ne montre pas les paiements : elle précède l'argent. --}}
        @unless ($estAddition)
            @foreach ($commande->paiements as $paiement)
                <tr>
                    <td>{{ $paiement->modePaiement?->libelle ?? 'Paiement' }}</td>
                    <td class="num">{{ Devise::formater((float) $paiement->montant, $devise) }}</td>
                </tr>
            @endforeach

            @if ($pourboire > 0)
                <tr>
                    <td>Dont pourboire</td>
                    <td class="num">{{ Devise::formater($pourboire, $devise) }}</td>
                </tr>
            @endif

            @if ($monnaie > 0)
                <tr>
                    <td>Reçu</td>
                    <td class="num">{{ Devise::formater($totalTendu, $devise) }}</td>
                </tr>
                <tr class="total">
                    <td>MONNAIE</td>
                    <td class="num">{{ Devise::formater($monnaie, $devise) }}</td>
                </tr>
            @endif

            @if ($reste > 0)
                <tr>
                    <td>Reste</td>
                    <td class="num">{{ Devise::formater($reste, $devise) }}</td>
                </tr>
            @endif
        @endunless
    </table>

    <div class="sep"></div>
    {{-- On ne remercie pas d'une visite qu'on n'a pas encore réglée. --}}
    <div class="center">{{ $estAddition ? 'Règlement au comptoir' : 'Merci de votre visite' }}</div>
</body>
</html>
