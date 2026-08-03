<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * URL publique d'un média stocké (logo d'établissement, photo de produit).
 *
 * Le champ en base est volontairement libre : soit une URL absolue déjà
 * publique — valeurs historiques ou visuels hébergés ailleurs —, soit un chemin
 * relatif sur le disque `public`. Les deux formes coexistent, d'où cette règle
 * unique partagée par les resources plutôt que dupliquée dans chacune.
 */
final class MediaUrl
{
    public static function pour(?string $chemin): ?string
    {
        if ($chemin === null || $chemin === '') {
            return null;
        }

        if (Str::startsWith($chemin, ['http://', 'https://'])) {
            return $chemin;
        }

        return Storage::disk('public')->url($chemin);
    }
}
