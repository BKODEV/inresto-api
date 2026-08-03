{{--
    Planche A4 des QR d'un point de vente : impression en lot, une vignette à
    découper par table.

    Grille construite en <table> (et non en flex/grid) : dompdf ne gère que
    CSS2.1. 3 colonnes ; les lignes se répartissent sur autant de pages A4 que
    nécessaire. L'identité vient de $parametre : aucune donnée en dur.

    Variables attendues :
      - $pointDeVente : PointDeVente
      - $parametre    : ParametreEtablissement|null
      - $tables       : Collection<TableSalle> (toutes porteuses d'un jeton)
      - $qrParTable   : Collection<int, string> (data URI du QR, indexé par table_id)
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Planche QR — {{ $pointDeVente->nom }}</title>
    <style>
        @page { margin: 14px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
        .entete { margin-bottom: 10px; }
        .entete h1 { font-size: 15px; margin: 0; }
        .entete .muted { color: #666; font-size: 10px; }
        table.grille { width: 100%; border-collapse: separate; border-spacing: 6px; }
        table.grille td {
            width: 33.33%; border: 1px dashed #bbb; padding: 10px 6px;
            text-align: center; vertical-align: top;
        }
        .vignette-etab { font-size: 10px; font-weight: bold; }
        .vignette-qr img { width: 118px; height: 118px; margin: 6px 0 4px 0; }
        .vignette-invite { font-size: 9px; color: #444; }
        .vignette-table { margin-top: 4px; font-size: 12px; font-weight: bold; }
        .vide { border: none !important; }
        .aucune { color: #666; font-style: italic; padding: 20px 0; }
    </style>
</head>
<body>
    <div class="entete">
        <h1>{{ $parametre?->nom_commercial ?? 'Planche de QR codes' }} — {{ $pointDeVente->nom }}</h1>
        <div class="muted">
            {{ $tables->count() }} table(s) · Découpez chaque vignette et posez-la sur la table correspondante.
        </div>
    </div>

    @if ($tables->isEmpty())
        <div class="aucune">Aucune table équipée d'un QR sur ce point de vente.</div>
    @else
        <table class="grille">
            @foreach ($tables->chunk(3) as $ligne)
                <tr>
                    @foreach ($ligne as $table)
                        <td>
                            @if ($parametre)
                                <div class="vignette-etab">{{ $parametre->nom_commercial }}</div>
                            @endif
                            <div class="vignette-qr"><img src="{{ $qrParTable[$table->id] }}" alt=""></div>
                            <div class="vignette-invite">Scannez pour voir le menu</div>
                            <div class="vignette-table">Table {{ $table->numero }}</div>
                        </td>
                    @endforeach

                    {{-- Cases de complément sur la dernière ligne : sans bordure,
                         pour ne pas suggérer une vignette à découper. --}}
                    @for ($i = $ligne->count(); $i < 3; $i++)
                        <td class="vide"></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
