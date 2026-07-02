<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module Utilisateurs : violations de la hiérarchie de
 * sécurité (anti-escalade), du périmètre site, garde-fous (dernier super-admin)
 * et prérequis email/SMTP.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class UserException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function hierarchieInsuffisante(): self
    {
        return new self(
            'Vous ne pouvez gérer que des utilisateurs dont le rôle est de niveau strictement inférieur au vôtre.',
            403,
        );
    }

    public static function horsPerimetre(): self
    {
        return new self(
            "Cet utilisateur n'appartient pas à votre périmètre de sites.",
            403,
        );
    }

    public static function roleNiveauTropEleve(): self
    {
        return new self(
            'Vous ne pouvez assigner que des rôles de niveau strictement inférieur au vôtre.',
            403,
        );
    }

    public static function siteHorsPerimetre(): self
    {
        return new self(
            "Vous ne pouvez rattacher un utilisateur qu'à vos propres sites.",
            403,
        );
    }

    public static function dernierSuperAdmin(): self
    {
        return new self(
            "Action impossible : ce compte est le dernier super-admin actif de l'instance.",
            409,
        );
    }

    public static function emailRequis(): self
    {
        return new self(
            "Un email est requis lorsqu'aucun mot de passe n'est fourni (le mot de passe provisoire est envoyé par email).",
            422,
        );
    }

    public static function emailNonConfigure(): self
    {
        return new self(
            "L'envoi d'email n'est pas configuré sur cette instance. Configurez le SMTP (MAIL_*) ou saisissez un mot de passe manuellement.",
            422,
        );
    }

    public static function emailRequisPourLien(): self
    {
        return new self(
            "Cet utilisateur n'a pas d'email : impossible d'envoyer un lien de réinitialisation.",
            422,
        );
    }
}
