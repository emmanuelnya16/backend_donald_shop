<?php

namespace App\Controller\Api\Public;

use App\Entity\PageVisit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * TrackingController — Donald Gros E-commerce
 *
 * Reçoit les événements de visite de page depuis le frontend React
 * (donaldgrosonline.com) et les enregistre en base de données.
 *
 * Routes :
 *   POST /api/track  → enregistrer une visite (PUBLIC — sans JWT)
 *
 * Body JSON attendu :
 *   { "page": "/products/chaussure-xyz", "referrer": "https://facebook.com" }
 */
#[Route('/api/track', name: 'api_track_')]
class TrackingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * POST /api/track
     *
     * Enregistre une visite de page.
     * - page      : chemin de la page visitée (obligatoire)
     * - referrer  : URL de provenance (optionnel, vide = accès direct)
     *
     * Réponse 201 :
     * { "success": true }
     */
    #[Route('', name: 'record', methods: ['POST'])]
    public function record(Request $request): JsonResponse
    {
        // Décode le body JSON
        $body = [];
        $content = $request->getContent();
        if (!empty($content)) {
            try {
                $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = [];
            }
        }

        $page     = trim($body['page']     ?? '');
        $referrer = trim($body['referrer'] ?? '');

        // Valide que la page est présente et non vide
        if (empty($page)) {
            return $this->json([
                'success' => false,
                'message' => 'Le champ page est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Tronque les valeurs trop longues pour éviter les erreurs DB
        $page      = mb_substr($page,      0, 500);
        $referrer  = mb_substr($referrer,  0, 500);
        $userAgent = mb_substr($request->headers->get('User-Agent', ''), 0, 500);

        // Récupère l'IP réelle (gère les reverse proxies / Cloudflare)
        $ip = $request->getClientIp();

        // Crée et persiste la visite
        $visit = new PageVisit();
        $visit->setPage($page);
        $visit->setIp($ip);
        $visit->setReferrer($referrer ?: null);
        $visit->setUserAgent($userAgent ?: null);
        // city = null pour l'instant (GeoLite2 plus tard)

        $this->em->persist($visit);
        $this->em->flush();

        return $this->json(['success' => true], Response::HTTP_CREATED);
    }
}
