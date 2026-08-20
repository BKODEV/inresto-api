@php
    use App\Helpers\Devise;
    $devise = $parametre?->devise;
    $lignesActives = $commande->lignes->filter(fn ($ligne) => $ligne->estActive());
    $reste = $commande->resteAPayer();
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
            <td>TOTAL</td>
            <td class="num">{{ Devise::formater($commande->montantDu(), $devise) }}</td>
        </tr>
        @foreach ($commande->paiements as $paiement)
            <tr>
                <td>{{ $paiement->modePaiement?->libelle ?? 'Paiement' }}</td>
                <td class="num">{{ Devise::formater((float) $paiement->montant, $devise) }}</td>
            </tr>
        @endforeach
        <tr>
            <td>{{ $reste >= 0 ? 'Reste' : 'Monnaie' }}</td>
            <td class="num">{{ Devise::formater(abs($reste), $devise) }}</td>
        </tr>
    </table>

    <div class="sep"></div>
    <div class="center">Merci de votre visite</div>
</body>
</html>
