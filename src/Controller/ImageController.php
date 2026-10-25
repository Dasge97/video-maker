<?php

declare(strict_types=1);

namespace App\Controller;

use App\Job\StepFailed;
use App\Library\ImageLibrary;
use App\Providers\OpenAiImages;
use App\Reference\InputRejected;
use App\Render\LogoColors;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Crear imagen»: a prompt and an image made with OpenAI that can be used as the photo of the
 * person or the logo of a video. The only screen that calls a paid provider itself: it is one
 * request of a few seconds, and going through the worker would only make the screen slower.
 */
final class ImageController extends AbstractController
{
    public function __construct(private readonly ImageLibrary $library)
    {
    }

    #[Route('/imagenes', name: 'image_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $kind = $request->query->getString('tipo');
        $kind = isset(ImageLibrary::KINDS[$kind]) ? $kind : null;
        $all = $this->library->all();
        $counts = ['todas' => \count($all)];
        foreach (array_keys(ImageLibrary::KINDS) as $k) {
            $counts[$k] = \count(array_filter($all, static fn (array $e) => $e['kind'] === $k));
        }

        return $this->render('images/index.html.twig', [
            'images' => null === $kind ? $all : array_values(array_filter($all, static fn (array $e) => $e['kind'] === $kind)),
            'kinds' => ImageLibrary::KINDS,
            'kind' => $kind,
            'counts' => $counts,
            'price' => OpenAiImages::ESTIMATED_USD,
            'preset' => $request->query->getString('para'),
        ]);
    }

    #[Route('/imagenes', name: 'image_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $source */
        $source = $request->files->get('source');
        if (null !== $source && (!$source->isValid() || $source->getSize() > 20 * 1024 * 1024)) {
            return new JsonResponse(['errors' => ['source' => 'La imagen de partida debe ser JPEG, PNG o WebP de 20 MB como mucho.']], 422);
        }
        set_time_limit(240);
        try {
            $entry = $this->library->create($request->request->getString('kind'), $request->request->getString('prompt'), $source?->getPathname());
        } catch (InputRejected $e) {
            return new JsonResponse(['errors' => ['prompt' => $e->getMessage()]], 422);
        } catch (StepFailed $e) {
            return new JsonResponse(['errors' => ['' => $e->getMessage()]], 502);
        }

        return new JsonResponse(['image' => $entry + ['url' => $this->generateUrl('image_file', ['hash' => $entry['hash']])]]);
    }

    #[Route('/imagenes/lista', name: 'image_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(array_map(fn (array $e) => $e + ['url' => $this->generateUrl('image_file', ['hash' => $e['hash']])], $this->library->all()));
    }

    #[Route('/imagenes/{hash}', name: 'image_file', requirements: ['hash' => '[0-9a-f]{24}'], methods: ['GET'])]
    public function image(string $hash): Response
    {
        $entry = $this->library->get($hash) ?? throw $this->createNotFoundException();

        return new BinaryFileResponse($this->library->path($entry));
    }

    #[Route('/imagenes/{hash}/borrar', name: 'image_delete', requirements: ['hash' => '[0-9a-f]{24}'], methods: ['POST'])]
    public function delete(string $hash): JsonResponse
    {
        return $this->library->delete($hash) ? new JsonResponse(['ok' => true]) : new JsonResponse(['errors' => ['' => 'Esa imagen ya no existe.']], 404);
    }

    /** The two main colours of a logo, to propose them as the colours of the brand. */
    #[Route('/colores-logo', name: 'logo_colors', methods: ['POST'])]
    public function logoColors(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('logo');
        $path = $file?->isValid() ? $file->getPathname() : null;
        if (null === $path) {
            $entry = $this->library->get($request->request->getString('hash'));
            $path = null === $entry ? null : $this->library->path($entry);
        }

        return new JsonResponse(['colors' => null === $path ? [] : LogoColors::main($path)]);
    }
}
