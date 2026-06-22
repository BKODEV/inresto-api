{{--
    Layout commun des documents PDF. Tout l'en-tête/pied est lu depuis la fiche
    $parametre (parametres_etablissement). Aucune donnée d'établissement n'est
    codée en dur. CSS2.1 uniquement (tables, pas de flex/grid) pour dompdf.

    Variables attendues :
      - $parametre    : ParametreEtablissement|null
      - $logoDataUri  : string|null (data URI du logo, optionnel)
      - $titre        : titre du document
      - section('contenu') : corps du document
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titre ?? 'Document' }}</title>
    <style>
        @page { margin: 24px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        h2 { font-size: 14px; margin: 0 0 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        .entete td { vertical-align: top; }
        .entete .logo { width: 90px; }
        .entete .logo img { max-width: 80px; max-height: 80px; }
        .etab-nom { font-size: 16px; font-weight: bold; }
        .etab-slogan { font-style: italic; color: #555; }
        .muted { color: #555; }
        .bandeau-warning {
            border: 1px solid #b58900; background: #fdf6e3; color: #8a6d00;
            padding: 6px 8px; margin-bottom: 10px; font-size: 11px;
        }
        .doc-titre { text-align: right; }
        .doc-titre h1 { text-transform: uppercase; }
        hr { border: none; border-top: 1px solid #ccc; margin: 10px 0; }
        table.lignes th, table.lignes td { border: 1px solid #ccc; padding: 5px 6px; }
        table.lignes th { background: #f2f2f2; text-align: left; }
        .num { text-align: right; }
        .totaux { margin-top: 8px; }
        .totaux td { padding: 3px 6px; }
        .totaux .label { text-align: right; }
        .totaux .grand { font-weight: bold; font-size: 13px; border-top: 2px solid #333; }
        .pied {
            margin-top: 24px; border-top: 1px solid #ccc; padding-top: 6px;
            font-size: 10px; color: #555;
        }
    </style>
</head>
<body>
    @if ($parametre === null)
        <div class="bandeau-warning">Paramètres établissement non configurés</div>
    @endif

    <table class="entete">
        <tr>
            @if ($logoDataUri ?? null)
                <td class="logo"><img src="{{ $logoDataUri }}" alt=""></td>
            @endif
            <td>
                @if ($parametre)
                    <div class="etab-nom">{{ $parametre->nom_commercial }}</div>
                    @if ($parametre->slogan)
                        <div class="etab-slogan">{{ $parametre->slogan }}</div>
                    @endif
                    @if ($parametre->adresse)
                        <div class="muted">{{ $parametre->adresse }}</div>
                    @endif
                    @php
                        $contacts = array_filter([
                            $parametre->telephone ? 'Tél. '.$parametre->telephone : null,
                            $parametre->email,
                            $parametre->site_web,
                        ]);
                    @endphp
                    @if ($contacts)
                        <div class="muted">{{ implode(' · ', $contacts) }}</div>
                    @endif
                @endif
            </td>
            <td class="doc-titre">
                <h1>{{ $titre ?? '' }}</h1>
                @yield('entete-droite')
            </td>
        </tr>
    </table>

    <hr>

    @yield('contenu')

    <div class="pied">
        @hasSection('pied')
            @yield('pied')
        @endif
        @if ($parametre)
            @php
                $legal = array_filter([
                    $parametre->raison_sociale,
                    $parametre->ncc ? 'NCC : '.$parametre->ncc : null,
                    $parametre->rccm ? 'RCCM : '.$parametre->rccm : null,
                    $parametre->regime_fiscal,
                ]);
            @endphp
            @if ($legal)
                <div>{{ implode(' — ', $legal) }}</div>
            @endif
            @if ($parametre->mentions_pied_page)
                <div>{{ $parametre->mentions_pied_page }}</div>
            @endif
        @endif
    </div>
</body>
</html>
