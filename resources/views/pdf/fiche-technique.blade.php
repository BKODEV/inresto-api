@php
    use App\Helpers\Devise;
    $devise = $parametre?->devise;
@endphp

@extends('pdf.layouts.document', ['titre' => 'Fiche technique'])

@section('entete-droite')
    <div class="muted">Version {{ $fiche->version }}</div>
    <div class="muted">Statut : {{ ucfirst($fiche->statut->value) }}</div>
@endsection

@section('contenu')
    <h2>{{ $fiche->produit?->libelle }}</h2>
    <div class="muted">Coût matière de référence : {{ Devise::formater((float) $fiche->cout_matiere, $devise) }}</div>
    <hr>

    @if ($fiche->lignesMatiere->isNotEmpty())
        <h2>Matières premières</h2>
        <table class="lignes">
            <thead>
                <tr>
                    <th>Matière</th>
                    <th class="num">Quantité</th>
                    <th>Unité</th>
                    <th class="num">Coût unitaire réf.</th>
                    <th class="num">Contribution</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fiche->lignesMatiere as $ligne)
                    @php
                        $coutUnitaire = (float) ($coutUnitaireParMatiere[$ligne->matiere_premiere_id] ?? 0);
                        $contribution = (float) $ligne->quantite * $coutUnitaire;
                    @endphp
                    <tr>
                        <td>{{ $ligne->matierePremiere?->libelle }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $ligne->quantite, 3, ',', ' '), '0'), ',') }}</td>
                        <td>{{ $ligne->unite?->libelle }}</td>
                        <td class="num">{{ Devise::formater($coutUnitaire, $devise) }}</td>
                        <td class="num">{{ Devise::formater($contribution, $devise) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($fiche->lignesSousRecette->isNotEmpty())
        <h2 style="margin-top: 12px;">Sous-recettes</h2>
        <table class="lignes">
            <thead>
                <tr>
                    <th>Sous-recette</th>
                    <th class="num">Quantité</th>
                    <th class="num">Contribution</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fiche->lignesSousRecette as $ligne)
                    @php
                        $qteProduite = (float) ($ligne->sousRecette?->quantite_produite ?? 0);
                        $contribution = $qteProduite > 0
                            ? (float) $ligne->sousRecette->cout_matiere / $qteProduite * (float) $ligne->quantite
                            : 0.0;
                    @endphp
                    <tr>
                        <td>{{ $ligne->sousRecette?->libelle }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $ligne->quantite, 3, ',', ' '), '0'), ',') }}</td>
                        <td class="num">{{ Devise::formater($contribution, $devise) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totaux">
        <tr class="grand">
            <td class="label">Total coût matière</td>
            <td class="num" style="width: 180px;">{{ Devise::formater((float) $fiche->cout_matiere, $devise) }}</td>
        </tr>
    </table>

    {{--
        La méthodologie vient APRÈS le coût : la fiche sert d'abord à chiffrer un
        plat, et c'est la partie qu'on emporte à l'atelier. Les points critiques
        sont placés en tête de bloc, avant les étapes — on ne les lit pas une
        fois, on les garde à l'œil pendant toute la production.
    --}}
    @if ($fiche->pointsCritiques->isNotEmpty() || $fiche->materiels->isNotEmpty() || $fiche->etapes->isNotEmpty())
        <hr>
        <h2>Méthodologie</h2>

        @if ($fiche->materiels->isNotEmpty())
            <div class="muted" style="margin-bottom: 8px;">
                <strong>Matériel :</strong>
                {{ $fiche->materiels->pluck('libelle')->implode(' · ') }}
            </div>
        @endif

        @if ($fiche->pointsCritiques->isNotEmpty())
            <table class="lignes">
                <thead>
                    <tr>
                        <th colspan="2">Points critiques</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fiche->pointsCritiques as $point)
                        <tr>
                            <td style="width: 140px;"><strong>{{ $point->type->libelle() }}</strong></td>
                            <td>{{ $point->description }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($fiche->etapes->isNotEmpty())
            @php $dureeTotale = $fiche->dureeTotaleMinutes(); @endphp
            <table class="lignes">
                <thead>
                    <tr>
                        <th style="width: 32px;">#</th>
                        <th>Étape</th>
                        <th class="num" style="width: 90px;">Durée</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fiche->etapes as $etape)
                        <tr>
                            <td>{{ $etape->ordre }}</td>
                            <td>{{ $etape->description }}</td>
                            {{-- Une étape sans durée n'affiche rien : « 0 min » se
                                 lirait comme un geste instantané. --}}
                            <td class="num">{{ $etape->duree_minutes ? $etape->duree_minutes . ' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($dureeTotale !== null)
                <div class="muted">Durée cumulée des étapes chiffrées : {{ $dureeTotale }} min</div>
            @endif
        @endif
    @endif
@endsection
