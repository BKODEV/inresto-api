@php
    use App\Helpers\Devise;

    $session = $rapport['session'];
    $devise = $parametre?->devise;
    $caisse = $session->caisse;

    // Ce qui reste dans le tiroir, ventilé, et ce qui n'y passe jamais.
    $enTiroir = collect($rapport['modes'])->filter(fn (array $m): bool => $m['compte_en_caisse']);
    $horsTiroir = collect($rapport['modes'])->reject(fn (array $m): bool => $m['compte_en_caisse']);

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

    {{-- Le rapprochement proprement dit : ce que le tiroir devait contenir face
         à ce qu'on y a compté. --}}
    <div class="section">EN TIROIR</div>
    <table>
        <tr>
            <td>Fonds initial</td>
            <td class="num">{{ Devise::formater((float) $session->fonds_initial, $devise) }}</td>
        </tr>
        @foreach ($enTiroir as $mode)
            <tr>
                <td>{{ $mode['mode'] }}</td>
                <td class="num">{{ Devise::formater($mode['montant'], $devise) }}</td>
            </tr>
            @if ($mode['pourboire'] > 0)
                <tr>
                    <td class="muted">&nbsp;&nbsp;dont pourboires</td>
                    <td class="num muted">{{ Devise::formater($mode['pourboire'], $devise) }}</td>
                </tr>
            @endif
        @endforeach
        <tr class="total">
            <td>THÉORIQUE</td>
            <td class="num">{{ Devise::formater((float) $session->montant_theorique, $devise) }}</td>
        </tr>
        <tr>
            <td>Compté</td>
            <td class="num">{{ Devise::formater((float) $session->montant_reel, $devise) }}</td>
        </tr>
        <tr class="total">
            <td>{{ $libelleEcart }}</td>
            <td class="num">{{ Devise::formater((float) $session->ecart, $devise) }}</td>
        </tr>
    </table>

    @if ($horsTiroir->isNotEmpty())
        <div class="sep"></div>
        {{-- Encaissé, mais jamais dans la main du caissier : c'est ce qui
             explique qu'un tiroir presque vide puisse être juste. --}}
        <div class="section">HORS TIROIR</div>
        <table>
            @foreach ($horsTiroir as $mode)
                <tr>
                    <td>{{ $mode['mode'] }}</td>
                    <td class="num">{{ Devise::formater($mode['montant'], $devise) }}</td>
                </tr>
            @endforeach
        </table>
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
