<?php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Rendu des QR codes destinés à l'impression.
 *
 * PNG en data URI plutôt que SVG : dompdf n'a pas de moteur SVG fiable, et un
 * data URI évite tout stockage de fichier intermédiaire.
 */
class QrCodeService
{
    /**
     * @param  string  $contenu  Ce qu'encode le QR — l'URL publique COMPLÈTE du
     *                           menu : un jeton nu scanné n'ouvrirait aucune page.
     * @param  int  $taille  Côté du QR en pixels (le PDF le redimensionne ensuite).
     */
    public function dataUri(string $contenu, int $taille = 300): string
    {
        return (new Builder(
            writer: new PngWriter,
            data: $contenu,
            // Correction haute : un QR collé sur une table encaisse les taches,
            // rayures et reflets ; il doit rester lisible malgré l'usure.
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $taille,
            margin: 8,
        ))->build()->getDataUri();
    }
}
