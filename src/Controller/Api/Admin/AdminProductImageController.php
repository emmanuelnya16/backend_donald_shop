<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\Entity\ProductImage;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Service\Media\CloudinaryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/products/{productId}/images', name: 'api_admin_product_images_')]
#[IsGranted('ROLE_ADMIN')]
class AdminProductImageController extends AbstractApiController
{
    public function __construct(
        private readonly CloudinaryService      $cloudinaryService,
        private readonly ProductRepository      $productRepository,
        private readonly ProductImageRepository $imageRepository,
        private readonly EntityManagerInterface $em,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── POST /api/admin/products/{productId}/images ───────────────────────
    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(int $productId, Request $request): JsonResponse
    {
        $product = $this->productRepository->find($productId);
        if (!$product) return $this->notFound('Produit introuvable.');

        $file = $request->files->get('image');
        if (!$file) {
            return $this->error(
                'Aucun fichier reçu. Envoyez le fichier dans le champ "image".',
                Response::HTTP_BAD_REQUEST
            );
        }

        // ── Debug temporaire — à retirer après confirmation ───────────────
        // Décommente ces lignes si tu veux vérifier que le fichier arrive bien
        // dd([
        //     'originalName' => $file->getClientOriginalName(),
        //     'mimeType'     => $file->getMimeType(),
        //     'size'         => $file->getSize(),
        //     'isValid'      => $file->isValid(),
        // ]);

        try {
            $uploadResult = $this->cloudinaryService->uploadProductImage($file, $productId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $existingImages = $this->imageRepository->findByProduct($productId);
        $position       = count($existingImages);

        $isMain = filter_var(
            $request->request->get('isMain', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );
        $color = $request->request->get('color');

        // Première image → automatiquement principale
        if ($position === 0) {
            $isMain = true;
        }

        if ($isMain) {
            $this->unsetMainImage($productId);
        }

        $image = new ProductImage();
        $image->setProduct($product);
        $image->setFilename($uploadResult['publicId']);
        $image->setUrl($uploadResult['url']);
        $image->setPosition($position);
        $image->setIsMain($isMain);
        $image->setColor($color ?: null);

        // ✅ Persist + flush via EntityManager injecté
        $this->em->persist($image);
        $this->em->flush();

        return $this->created([
            'id'       => $image->getId(),
            'url'      => $image->getUrl(),
            'filename' => $image->getFilename(),
            'position' => $image->getPosition(),
            'isMain'   => $image->isMain(),
            'color'    => $image->getColor(),
            'cloudinary' => [
                'width'  => $uploadResult['width'],
                'height' => $uploadResult['height'],
                'format' => $uploadResult['format'],
                'size'   => $uploadResult['size'],
            ],
        ], 'Image uploadée avec succès.');
    }

    // ── PATCH /api/admin/products/{productId}/images/{imageId}/main ───────
    #[Route('/{imageId}/main', name: 'set_main', methods: ['PATCH'])]
    public function setMain(int $productId, int $imageId): JsonResponse
    {
        $product = $this->productRepository->find($productId);
        if (!$product) return $this->notFound('Produit introuvable.');

        $image = $this->imageRepository->find($imageId);
        if (!$image || $image->getProduct()->getId() !== $productId) {
            return $this->notFound('Image introuvable.');
        }

        $this->unsetMainImage($productId);

        $image->setIsMain(true);
        $this->em->flush();

        return $this->success([
            'id'     => $image->getId(),
            'isMain' => true,
        ], 'Image principale définie.');
    }

    // ── PATCH /api/admin/products/{productId}/images/reorder ─────────────
    #[Route('/reorder', name: 'reorder', methods: ['PATCH'])]
    public function reorder(int $productId, Request $request): JsonResponse
    {
        $product = $this->productRepository->find($productId);
        if (!$product) return $this->notFound('Produit introuvable.');

        $body  = json_decode($request->getContent(), true);
        $order = $body['order'] ?? [];

        if (empty($order) || !is_array($order)) {
            return $this->error(
                'Envoyez un tableau "order" avec les IDs des images.',
                Response::HTTP_BAD_REQUEST
            );
        }

        foreach ($order as $position => $imageId) {
            $image = $this->imageRepository->find((int) $imageId);
            if ($image && $image->getProduct()->getId() === $productId) {
                $image->setPosition((int) $position);
            }
        }

        // ✅ Un seul flush pour toutes les modifications
        $this->em->flush();

        return $this->success(null, 'Ordre des images mis à jour.');
    }

    // ── DELETE /api/admin/products/{productId}/images/{imageId} ──────────
    #[Route('/{imageId}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $productId, int $imageId): JsonResponse
    {
        $product = $this->productRepository->find($productId);
        if (!$product) return $this->notFound('Produit introuvable.');

        $image = $this->imageRepository->find($imageId);
        if (!$image || $image->getProduct()->getId() !== $productId) {
            return $this->notFound('Image introuvable.');
        }

        $wasMain  = $image->isMain();
        $publicId = $image->getFilename();

        // 1. Supprime sur Cloudinary
        $this->cloudinaryService->deleteImage($publicId);

        // 2. Supprime en base
        $this->em->remove($image);
        $this->em->flush();

        // 3. Repositionne les images restantes
        $this->imageRepository->reorderForProduct($productId);

        // 4. Si c'était l'image principale → définit la première restante
        if ($wasMain) {
            $remaining = $this->imageRepository->findByProduct($productId);
            if (!empty($remaining)) {
                $remaining[0]->setIsMain(true);
                $this->em->flush();
            }
        }

        return $this->success(null, 'Image supprimée.');
    }

    // ── GET /api/admin/products/{productId}/images ────────────────────────
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(int $productId): JsonResponse
    {
        $product = $this->productRepository->find($productId);
        if (!$product) return $this->notFound('Produit introuvable.');

        $images = $this->imageRepository->findByProduct($productId);

        return $this->success(
            array_map(fn(ProductImage $img) => [
                'id'       => $img->getId(),
                'url'      => $img->getUrl(),
                'filename' => $img->getFilename(),
                'position' => $img->getPosition(),
                'isMain'   => $img->isMain(),
                'color'    => $img->getColor(),
            ], $images)
        );
    }

    // ── Helper privé ──────────────────────────────────────────────────────
    private function unsetMainImage(int $productId): void
    {
        $images = $this->imageRepository->findByProduct($productId);
        foreach ($images as $img) {
            if ($img->isMain()) {
                $img->setIsMain(false);
            }
        }
        // ✅ Pas de flush ici — le flush est fait par l'appelant
    }
}