<?php
require __DIR__.'/vendor/autoload.php';
use App\Kernel;
use Symfony\Component\HttpFoundation\File\UploadedFile;

$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$cloudinaryService = $container->get('App\Service\Media\CloudinaryService');

// Create a dummy image file
$dummyImagePath = __DIR__ . '/dummy.jpg';
if (!file_exists($dummyImagePath)) {
    $img = imagecreatetruecolor(100, 100);
    imagejpeg($img, $dummyImagePath);
}

$file = new UploadedFile($dummyImagePath, 'dummy.jpg', 'image/jpeg', null, true);

try {
    $result = $cloudinaryService->uploadProductImage($file, 1);
    echo "SUCCESS: " . print_r($result, true);
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
