<?php

namespace App\DTO\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final class ActivateAdminDTO
{
    #[Assert\NotBlank(message: 'Le token d\'activation est obligatoire.')]
    public string $token = '';

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    #[Assert\Length(
        min: 8,
        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
    )]
    #[Assert\Regex(
        pattern: '/^(?=.*[A-Za-z])(?=.*\d).+$/',
        message: 'Le mot de passe doit contenir au moins une lettre et un chiffre.',
    )]
    public string $password = '';
}
