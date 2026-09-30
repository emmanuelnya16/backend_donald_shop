<?php

namespace App\Command;

use App\Entity\Admin;
use App\Repository\AdminRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-super-admin',
    description: 'Crée le compte Super Administrateur principal de Donald Gros.',
)]
class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly AdminRepository             $adminRepository,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Création du Super Administrateur — Donald Gros');

        // Vérifier si un Super Admin existe déjà
        $existing = $this->adminRepository->findOneBy([
            'role' => Admin::ROLE_SUPER_ADMIN,
        ]);

        if ($existing) {
            $io->warning(sprintf(
                'Un Super Admin existe déjà : %s (%s)',
                $existing->getFullName(),
                $existing->getEmail()
            ));
            return Command::SUCCESS;
        }

        // Demander les informations interactivement
        $firstName = $io->ask('Prénom du Super Admin', 'Eva');
        $lastName  = $io->ask('Nom du Super Admin', 'Gros');
        $email     = $io->ask('Email du Super Admin', 'Eva@donaldgros.com');
        $password  = $io->askHidden('Mot de passe (masqué)');

        if (strlen($password) < 8) {
            $io->error('Le mot de passe doit contenir au moins 8 caractères.');
            return Command::FAILURE;
        }

        // Créer le Super Admin
        $admin = new Admin();
        $admin->setFirstName($firstName);
        $admin->setLastName($lastName);
        $admin->setEmail($email);
        $admin->setRole(Admin::ROLE_SUPER_ADMIN);
        $admin->setIsActive(true);              // actif immédiatement
        $admin->setMustChangePassword(false);   // pas de changement forcé
        $admin->setPassword(
            $this->hasher->hashPassword($admin, $password)
        );

        $this->adminRepository->save($admin, flush: true);

        $io->success(sprintf(
            'Super Admin créé avec succès ! Email : %s',
            $email
        ));

        $io->note('Commande pour tester la connexion :');
        $io->text(sprintf(
            'curl -X POST http://localhost:8000/api/admin/auth/login -H "Content-Type: application/json" -d \'{"email":"%s","password":"VOTRE_MOT_DE_PASSE"}\'',
            $email
        ));

        return Command::SUCCESS;
    }
}