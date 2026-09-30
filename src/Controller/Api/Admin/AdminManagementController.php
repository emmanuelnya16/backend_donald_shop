<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\DTO\Admin\CreateAdminDTO;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/admin/users', name: 'api_admin_users_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminManagementController extends AbstractApiController
{
    public function __construct(
        private readonly AdminRepository             $adminRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface             $mailer,
        ValidatorInterface                           $validator,
        SerializerInterface                          $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, CreateAdminDTO::class);
        if (!$dto) {
            return $this->error('Corps JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->validate($dto);
        if ($errors) {
            return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
        }

        // Vérifier si l'email existe déjà
        $existingAdmin = $this->adminRepository->findByEmail($dto->email);
        if ($existingAdmin) {
            return $this->error('Cette adresse email est déjà utilisée.', Response::HTTP_CONFLICT);
        }

        // Créer l'administrateur
        $admin = new Admin();
        $admin->setFirstName($dto->firstName);
        $admin->setLastName($dto->lastName);
        $admin->setEmail($dto->email);
        $admin->setRole($dto->role);
        $admin->setIsActive(false); // Inactif jusqu'à l'activation par mail
        $admin->setMustChangePassword(true); // Obligation de configurer le mot de passe
        $admin->generateActivationToken();

        // Définir un mot de passe temporaire aléatoire
        $tempPassword = bin2hex(random_bytes(16));
        $admin->setPassword(
            $this->hasher->hashPassword($admin, $tempPassword)
        );

        $this->adminRepository->save($admin, flush: true);

        // Envoyer l'email d'activation
        $mailSent = false;
        try {
            $email = (new Email())
                ->from('no-reply@donaldgros.com')
                ->to($admin->getEmail())
                ->subject('Activation de votre compte Administrateur — Donald Gros')
                ->html(sprintf(
                    '<p>Bonjour %s,</p>' .
                    '<p>Un compte administrateur avec le rôle <strong>%s</strong> a été créé pour vous.</p>' .
                    '<p>Veuillez activer votre compte et configurer votre mot de passe en utilisant le jeton d\'activation suivant :</p>' .
                    '<pre>%s</pre>' .
                    '<p>Ce jeton est valide pendant 24 heures.</p>',
                    $admin->getFullName(),
                    $admin->getRole(),
                    $admin->getActivationToken()
                ));

            $this->mailer->send($email);
            $mailSent = true;
        } catch (\Exception $e) {
            // Logger ou ignorer silencieusement pour éviter de planter la création
        }

        return $this->created([
            'id'              => $admin->getId(),
            'firstName'       => $admin->getFirstName(),
            'lastName'        => $admin->getLastName(),
            'email'           => $admin->getEmail(),
            'role'            => $admin->getRole(),
            'isActive'        => $admin->isActive(),
            'activationToken' => $admin->getActivationToken(), // Renvoyé pour faciliter les tests en local
            'mailSent'        => $mailSent
        ], 'Compte administrateur créé avec succès. Un e-mail d\'activation a été envoyé.');
    }
}
