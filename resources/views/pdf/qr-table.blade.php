{{--
    Chevalet QR d'une table (A6), à poser sur la table elle-même.

    Document destiné au CLIENT : pas d'en-tête administratif, pas de mentions
    légales — juste l'identité de l'établissement, le QR et l'invitation à
    scanner. Tout provient de $parametre (parametres_etablissement) : aucune
    donnée d'établissement en dur. CSS2.1 uniquement (dompdf).

    Variables attendues :
      - $table        : TableSalle
      - $parametre    : ParametreEtablissement|null
      - $logoDataUri  : string|null
      - $qrDataUri    : string (PNG data URI encodant l'URL publique du menu)
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>QR menu — table {{ $table->numero }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif; margin: 0; color: #1a1a1a;
            text-align: center;
        }
        /* Le bloc est calé en haut de l'A6 plutôt que centré : dompdf ne sait
           pas centrer verticalement (ni flex, ni height:100% fiable) et la
           découpe se fait de toute façon au format du chevalet. */
        .cadre { padding: 24px 20px; }
        .logo { max-width: 70px; max-height: 70px; }
        .etab-nom { font-size: 17px; font-weight: bold; margin-top: 6px; }
        .etab-slogan { font-style: italic; color: #666; font-size: 10px; margin-top: 2px; }
        .qr { margin: 14px 0 10px 0; }
        .qr img { width: 170px; height: 170px; }
        .invite { font-size: 14px; font-weight: bold; }
        .invite-sous { font-size: 10px; color: #666; margin-top: 3px; }
        .table-num {
            margin-top: 12px; border-top: 1px solid #ddd; padding-top: 8px;
            font-size: 12px; color: #444;
        }
        .table-num strong { font-size: 15px; }
    </style>
</head>
<body>
    <div class="cadre">
        @if ($logoDataUri)
            <img class="logo" src="{{ $logoDataUri }}" alt="">
        @endif

        @if ($parametre)
            <div class="etab-nom">{{ $parametre->nom_commercial }}</div>
            @if ($parametre->slogan)
                <div class="etab-slogan">{{ $parametre->slogan }}</div>
            @endif
        @endif

        <div class="qr"><img src="{{ $qrDataUri }}" alt=""></div>

        <div class="invite">Scannez pour voir le menu</div>
        <div class="invite-sous">Avec l'appareil photo de votre téléphone</div>

        <div class="table-num">Table <strong>{{ $table->numero }}</strong></div>
    </div>
</body>
</html>
