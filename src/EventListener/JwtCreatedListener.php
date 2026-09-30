<?php

namespace App\EventListener;

use App\Entity\Admin;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

/**
 * Enrichit le payload JWT avec des informations supplémentaires.
 *
 * Payload par défaut de Lexik : { "iat": ..., "exp": ..., "roles": [...], "username": "..." }
 *
 * Après cet écouteur, le payload contient aussi :
 * {
 *   "id":   42,
 *   "type": "user"  | "admin",
 *   "firstName": "Jean",
 *   "phone": "+237699123456"   (pour les users)
 *   "role": "ROLE_SUPER_ADMIN" (pour les admins)
 * }
 *
 * Cela permet au frontend React de décoder le JWT et d'afficher
 * le prénom de l'utilisateur sans faire une requête API supplémentaire.
 */
class JwtCreatedListener
{
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $user    = $event->getUser();

        if ($user instanceof User) {
            $payload['type']      = 'user';
            $payload['id']        = $user->getId();
            $payload['firstName'] = $user->getFirstName();
            $payload['phone']     = $user->getPhone();
            $payload['city']      = $user->getCity();
        }

        if ($user instanceof Admin) {
            $payload['type']      = 'admin';
            $payload['id']        = $user->getId();
            $payload['firstName'] = $user->getFirstName();
            $payload['role']      = $user->getRole();
            $payload['email']     = $user->getEmail();
        }

        $event->setData($payload);
    }
}