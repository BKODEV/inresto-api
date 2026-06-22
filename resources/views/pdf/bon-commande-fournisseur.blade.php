@php
    use App\Helpers\Devise;
    $devise = $parametre?->devise;
@endphp

@extends('pdf.layouts.document', ['titre' => 'Bon de commande'])

@section('entete-droite')
    <div class="muted">N° {{ $commande->numero }}</div>
    <div class="muted">{{ optional($commande->date_commande)->format('d/m/Y') }}</div>
    <div class="muted">Statut : {{ ucfirst(str_replace('_', ' ', $commande->statut->value)) }}</div>
@endsection

@section('contenu')
    @if ($commande->fournisseur)
        <h2>Fournisseur</h2>
        <div><strong>{{ $commande->fournisseur->nom }}</strong></div>
        @php
            $coordsFournisseur = array_filter([
                $commande->fournisseur->telephone ? 'Tél. '.$commande->fournisseur->telephone : null,
                $commande->fournisseur->email,
                $commande->fournisseur->adresse,
            ]);
        @endphp
        @if ($coordsFournisseur)
            <div class="muted">{{ implode(' · ', $coordsFournisseur) }}</div>
        @endif
        <hr>
    @endif

    <table class="lignes">
        <thead>
            <tr>
                <th>Matière</th>
                <th class="num">Quantité</th>
                <th>Unité</th>
                <th class="num">Prix prév. unitaire</th>
                <th class="num">Total ligne</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($commande->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->matierePremiere?->libelle }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $ligne->quantite_commandee, 3, ',', ' '), '0'), ',') }}</td>
                    <td>{{ $ligne->unite?->libelle }}</td>
                    <td class="num">{{ Devise::formater((float) $ligne->prix_unitaire_previsionnel, $devise) }}</td>
                    <td class="num">{{ Devise::formater($ligne->sousTotal(), $devise) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr class="grand">
            <td class="label">Total prévisionnel</td>
            <td class="num" style="width: 160px;">{{ Devise::formater((float) $commande->montant_previsionnel, $devise) }}</td>
        </tr>
    </table>
@endsection
