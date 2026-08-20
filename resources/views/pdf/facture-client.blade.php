@php
    use App\Helpers\Devise;
    $devise = $parametre?->devise;
    $lignesActives = $commande->lignes->filter(fn ($ligne) => $ligne->estActive());
    $sousTotal = $commande->montantLignesActives();
    $base = $sousTotal;
    $paiementsParMode = $commande->paiements->groupBy(fn ($p) => $p->modePaiement?->libelle ?? 'Paiement');
@endphp

@extends('pdf.layouts.document', ['titre' => 'Facture'])

@section('entete-droite')
    <div class="muted">N° {{ $commande->id }}</div>
    <div class="muted">{{ optional($commande->date_heure)->format('d/m/Y H:i') }}</div>
    @if ($commande->table)
        <div class="muted">Table {{ $commande->table_numero ?? $commande->table->numero }}</div>
    @endif
@endsection

@section('contenu')
    @if ($commande->appartement_nom)
        <h2>Client</h2>
        <div><strong>{{ $commande->appartement_nom }}</strong></div>
        <hr>
    @endif

    <table class="lignes">
        <thead>
            <tr>
                <th>Produit</th>
                <th class="num">Qté</th>
                <th class="num">Prix unitaire</th>
                <th class="num">Total ligne</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lignesActives as $ligne)
                <tr>
                    <td>{{ $ligne->produit?->libelle }}</td>
                    <td class="num">{{ $ligne->quantite }}</td>
                    <td class="num">{{ Devise::formater((float) $ligne->prix_unitaire, $devise) }}</td>
                    <td class="num">{{ Devise::formater((float) $ligne->quantite * (float) $ligne->prix_unitaire, $devise) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr>
            <td class="label">Sous-total</td>
            <td class="num" style="width: 180px;">{{ Devise::formater($sousTotal, $devise) }}</td>
        </tr>
        @foreach ($commande->remises as $remise)
            <tr>
                <td class="label">
                    Remise{{ $remise->motif ? ' ('.$remise->motif.')' : '' }}
                    @if ($remise->type === \App\Enums\TypeRemise::Pourcentage)
                        — {{ rtrim(rtrim(number_format((float) $remise->valeur, 2, ',', ' '), '0'), ',') }}%
                    @endif
                </td>
                <td class="num">− {{ Devise::formater($remise->montantPour($base), $devise) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td class="label">Total dû</td>
            <td class="num">{{ Devise::formater($commande->montantDu(), $devise) }}</td>
        </tr>
        @foreach ($paiementsParMode as $mode => $paiements)
            <tr>
                <td class="label">Payé — {{ $mode }}</td>
                <td class="num">{{ Devise::formater((float) $paiements->sum('montant'), $devise) }}</td>
            </tr>
        @endforeach
        @php($reste = $commande->resteAPayer())
        <tr>
            <td class="label">{{ $reste >= 0 ? 'Reste à payer' : 'Monnaie à rendre' }}</td>
            <td class="num">{{ Devise::formater(abs($reste), $devise) }}</td>
        </tr>
    </table>
@endsection

@section('pied')
    @if ($commande->reference_facture_normalisee)
        <div>Réf. facture normalisée : {{ $commande->reference_facture_normalisee }}</div>
    @endif
    @unless ($parametre?->mentions_pied_page)
        <div>Document non fiscal — facture normalisée émise via la plateforme habilitée</div>
    @endunless
@endsection
