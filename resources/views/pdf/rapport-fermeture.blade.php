@php
    use App\Helpers\Devise;

    $session = $rapport['session'];
    $devise = $parametre?->devise;
    $caisse = $session->caisse;

    $sens = [
        'non_rapproche' => 'non rapproché',
        'juste' => 'juste',
        'tolere' => 'toléré',
        'excedent' => 'excédent',
        'manquant' => 'manquant',
    ];

    $libelleEcart = [
        'juste' => 'Caisse juste',
        'tolere' => 'Écart toléré',
        'excedent' => 'Excédent',
        'manquant' => 'Manquant',
    ][$rapport['ecart_sens']] ?? '';

    $nom = fn (?object $user): string => $user
        ? trim($user->prenom.' '.$user->nom)
        : '—';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport de fermeture</title>
    <style>
        @page { size: 80mm auto; margin: 4px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #000; margin: 0; width: 72mm; }
        .center { text-align: center; }
        .nom { font-size: 13px; font-weight: bold; }
        .sep { border-top: 1px dashed #000; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .num { text-align: right; }
        .total { font-size: 12px; font-weight: bold; }
        .titre { font-size: 12px; font-weight: bold; letter-spacing: 1px; margin-bottom: 2px; }
        .section { font-weight: bold; margin-top: 2px; }
        .muted { color: #444; }
    </style>
</head>
<body>
    <div class="center">
        @if ($parametre)
            <div class="nom">{{ $parametre->nom_commercial }}</div>
        @else
            <div class="nom">Paramètres établissement non configurés</div>
        @endif
    </div>

    <div class="sep"></div>

    <div class="center titre">RAPPORT DE FERMETURE</div>
    <div class="center muted">
        {{ $caisse?->libelle ?? 'Caisse' }} · Session #{{ $session->id }}
    </div>
    <div>Ouverte le {{ optional($session->ouvert_le)->format('d/m/Y H:i') }}</div>
    <div>Fermée le {{ optional($session->ferme_le)->format('d/m/Y H:i') }}</div>
    <div>Ouverte par : {{ $nom($session->user) }}</div>
    <div>Fermée par : {{ $nom($session->fermePar) }}</div>

    <div class="sep"></div>

    {{-- Ce qu'on a vendu. Lu sur les lignes : ni les pourboires ni le mode de
         règlement n'y entrent. --}}
    <table>
        <tr>
            <td>Notes encaissées</td>
            <td class="num">{{ $rapport['notes_encaissees'] }}</td>
        </tr>
        <tr class="total">
            <td>CHIFFRE D'AFFAIRES</td>
            <td class="num">{{ Devise::formater($rapport['chiffre_affaires'], $devise) }}</td>
        </tr>
    </table>

    <div class="sep"></div>

    {{-- Le rapprochement, contenant par contenant. Un mode de paiement désigne
         un endroit où l'argent s'accumule : le tiroir, le terminal, la pochette
         de chèques. Chacun se compare à ce qu'on y constate. --}}
    <div class="section">RAPPROCHEMENT</div>
    @foreach ($rapport['rapprochements'] as $ligne)
        <table>
            <tr class="bold">
                <td colspan="2">
                    {{ $ligne->modePaiement?->libelle ?? 'Mode supprimé' }}
                    @unless ($ligne->modePaiement?->compte_en_caisse)
                        <span class="muted">(hors tiroir)</span>
                    @endunless
                </td>
            </tr>
            <tr>
                <td>Théorique</td>
                <td class="num">{{ Devise::formater((float) $ligne->montant_theorique, $devise) }}</td>
            </tr>
            @if ($ligne->estRapproche())
                <tr>
                    <td>Constaté</td>
                    <td class="num">{{ Devise::formater((float) $ligne->montant_constate, $devise) }}</td>
                </tr>
                <tr class="total">
                    <td>{{ $sens[$ligne->sensEcart($rapport['tolerance'])] ?? '' }}</td>
                    <td class="num">{{ Devise::formater((float) $ligne->ecart, $devise) }}</td>
                </tr>
            @else
                {{-- Un virement arrive à la banque quand il arrive : rien à
                     constater ce soir, et on n'invente pas d'écart. --}}
                <tr>
                    <td colspan="2" class="muted">Non rapproché</td>
                </tr>
            @endif
        </table>
        <div class="sep"></div>
    @endforeach

    {{-- L'écart de caisse au sens strict : la part tiroir, fonds compris. --}}
    <table>
        <tr class="total">
            <td>ÉCART DE CAISSE</td>
            <td class="num">
                @if ($session->ecart === null)
                    non rapproché
                @else
                    {{ Devise::formater((float) $session->ecart, $devise) }} {{ $libelleEcart }}
                @endif
            </td>
        </tr>
    </table>


    @php($versements = collect($rapport['versements']))
    @if ($versements->isNotEmpty())
        {{-- Argent sorti du tiroir : c'est ce qui explique qu'on y trouve moins
             que ce qu'on a encaissé. --}}
        <div class="section">VERSEMENTS</div>
        <table>
            @foreach ($versements as $versement)
                <tr>
                    <td>
                        {{ optional($versement->date_heure)->format('H:i') }}
                        {{ $versement->destination->libelle() }}
                        @if ($versement->apres_fermeture)
                            <span class="muted">(après clôture)</span>
                        @endif
                    </td>
                    <td class="num">− {{ Devise::formater((float) $versement->montant, $devise) }}</td>
                </tr>
                @if ($versement->remis_a)
                    <tr>
                        <td colspan="2" class="muted">&nbsp;&nbsp;remis à {{ $versement->remis_a }}</td>
                    </tr>
                @endif
            @endforeach
        </table>
        <div class="sep"></div>
    @endif

    @if ($session->ecart_ouverture !== null && (float) $session->ecart_ouverture !== 0.0)
        {{-- Le tiroir n'a pas repris ce que la session précédente y avait
             laissé, versements déduits. Signalé, jamais bloquant. --}}
        <div class="section">TRANSITION À L'OUVERTURE</div>
        <table>
            <tr class="total">
                <td>Écart non justifié</td>
                <td class="num">{{ Devise::formater((float) $session->ecart_ouverture, $devise) }}</td>
            </tr>
        </table>
        <div class="sep"></div>
    @endif

    @if (! empty($rapport['pourboires_par_serveur']))
        <div class="sep"></div>
        {{-- Cet argent est dans le tiroir mais n'appartient pas à
             l'établissement : c'est la liste qu'on lit pour reverser. --}}
        <div class="section">POURBOIRES À REVERSER</div>
        <table>
            @foreach ($rapport['pourboires_par_serveur'] as $part)
                <tr>
                    <td>{{ $part['serveur'] }}</td>
                    <td class="num">{{ Devise::formater($part['montant'], $devise) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>TOTAL</td>
                <td class="num">{{ Devise::formater($rapport['total_pourboires'], $devise) }}</td>
            </tr>
        </table>
    @endif

    <div class="sep"></div>

    {{-- Les trois façons connues de faire sortir de la valeur sans que la
         caisse le voie. On les relit quand un écart interroge. --}}
    <div class="section">GESTES DU SERVICE</div>
    <table>
        <tr>
            <td>Remises ({{ $rapport['gestes']['remises']['nombre'] }})</td>
            <td class="num">− {{ Devise::formater($rapport['gestes']['remises']['montant'], $devise) }}</td>
        </tr>
        <tr>
            <td>Notes annulées</td>
            <td class="num">{{ $rapport['gestes']['annulations'] }}</td>
        </tr>
        <tr>
            <td>Documents réimprimés</td>
            <td class="num">{{ $rapport['gestes']['reimpressions'] }}</td>
        </tr>
    </table>

    @if ($session->notes)
        <div class="sep"></div>
        <div class="section">NOTES</div>
        <div>{{ $session->notes }}</div>
    @endif
</body>
</html>
