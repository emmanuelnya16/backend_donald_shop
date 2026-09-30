<?php

namespace App\DTO\Admin;

use App\Entity\Admin;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateAdminDTO
{
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(
        min: 2, max: 100,
        minMessage: 'Le prénom doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères.',
    )]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(
        min: 2, max: 100,
        minMessage: 'Le nom doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.',
    )]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire.')]
    #[Assert\Email(message: 'L\'adresse e-mail n\'est pas valide.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Le rôle est obligatoire.')]
    #[Assert\Choice(
        choices: [Admin::ROLE_SUPER_ADMIN, Admin::ROLE_MANAGER],
        message: 'Le rôle sélectionné n\'est pas valide.'
    )]
    public string $role = Admin::ROLE_MANAGER;
}
