<x-mail::message>
# Bienvenue sur votre console de gestion

Un compte vient d'être créé pour vous.

**Identifiant :** {{ $identifiant }}
**Mot de passe provisoire :** {{ $motDePasse }}

Pour des raisons de sécurité, nous vous invitons à changer ce mot de passe dès votre première connexion.

<x-mail::button :url="$loginUrl">
Se connecter
</x-mail::button>

Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.

Cordialement,<br>
L'équipe Innov Immobilier
</x-mail::message>
