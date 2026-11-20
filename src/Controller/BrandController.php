<?php

declare(strict_types=1);

namespace App\Controller;

use App\Account\AccountStore;
use App\Account\Brands;
use App\Account\CurrentAccount;
use App\Library\ImageLibrary;
use App\Render\VideoFonts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Mis marcas»: lo que no hay que repetir en cada anuncio. El logo, los colores, la tipografía,
 * las palabras prohibidas y los elementos de la marca (sus productos y sus lugares), que el
 * asistente de un anuncio nuevo pone solos al elegir la marca.
 */
final class BrandController extends AbstractController
{
    private const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly Brands $brands,
        private readonly CurrentAccount $current,
        private readonly AccountStore $accounts,
        private readonly ImageLibrary $library,
    ) {
    }

    #[Route('/marcas', name: 'brand_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('brands/index.html.twig', ['brands' => $this->current->require()->brands()]);
    }

    #[Route('/marcas/nueva', name: 'brand_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('brands/edit.html.twig', ['brand' => null, 'fonts' => array_keys(VideoFonts::FAMILIES), 'kinds' => Brands::ELEMENT_KINDS, 'max' => Brands::MAX_ELEMENTS]);
    }

    #[Route('/marcas/{id}', name: 'brand_edit', requirements: ['id' => '[0-9a-z]{26}'], methods: ['GET'])]
    public function edit(string $id): Response
    {
        $brand = $this->current->require()->brand($id) ?? throw $this->createNotFoundException('Esa marca no existe.');

        return $this->render('brands/edit.html.twig', ['brand' => $brand, 'fonts' => array_keys(VideoFonts::FAMILIES), 'kinds' => Brands::ELEMENT_KINDS, 'max' => Brands::MAX_ELEMENTS]);
    }

    #[Route('/marcas/guardar', name: 'brand_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $form = $request->request;
        $name = mb_substr(trim($form->getString('name')), 0, 80);
        if ('' === $name) {
            return new JsonResponse(['errors' => ['name' => 'Escribe el nombre de la marca.']], 422);
        }
        $id = $form->getString('id');
        if ('' !== $id && null === $this->current->require()->brand($id)) {
            return new JsonResponse(['errors' => ['' => 'Esa marca ya no existe.']], 404);
        }
        $logo = $this->image($request, 'logo');
        if (false === $logo) {
            return new JsonResponse(['errors' => ['logo' => 'El logo debe ser JPEG, PNG o WebP y ocupar como mucho 20 MB.']], 422);
        }
        $colors = array_values(array_filter([self::hex($form->getString('color1')), self::hex($form->getString('color2'))]));
        $forbidden = array_values(array_filter(array_map(static fn (string $w) => mb_substr(trim($w), 0, 40), explode(',', $form->getString('forbiddenWords'))), static fn (string $w) => '' !== $w));
        try {
            $brand = $this->brands->save($this->current->require(), $name, $colors, $form->getString('font'), $forbidden, $logo, '' === $id ? null : $id);
        } catch (\RangeException $e) {
            return new JsonResponse(['errors' => ['' => $e->getMessage()]], 422);
        }

        return new JsonResponse(['url' => $this->generateUrl('brand_edit', ['id' => $brand['id']])]);
    }

    #[Route('/marcas/{id}/borrar', name: 'brand_delete', requirements: ['id' => '[0-9a-z]{26}'], methods: ['POST'])]
    public function delete(string $id): JsonResponse
    {
        return $this->brands->delete($this->current->require(), $id)
            ? new JsonResponse(['url' => $this->generateUrl('brand_index')])
            : new JsonResponse(['errors' => ['' => 'Esa marca ya no existe.']], 404);
    }

    #[Route('/marcas/{id}/logo', name: 'brand_logo', requirements: ['id' => '[0-9a-z]{26}'], methods: ['GET'])]
    public function logo(string $id): Response
    {
        return new BinaryFileResponse($this->brands->logo($this->current->require(), $id) ?? throw $this->createNotFoundException());
    }

    /** Un elemento nuevo: su imagen subida o una de «Imágenes» (también la recién generada). */
    #[Route('/marcas/{id}/elementos', name: 'brand_element_add', requirements: ['id' => '[0-9a-z]{26}'], methods: ['POST'])]
    public function addElement(Request $request, string $id): JsonResponse
    {
        $form = $request->request;
        $kind = $form->getString('kind');
        $name = mb_substr(trim($form->getString('name')), 0, 60);
        $errors = [];
        if (!isset(Brands::ELEMENT_KINDS[$kind])) {
            $errors['kind'] = 'Elige si es un producto o un lugar.';
        }
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Ponle un nombre corto, como «Botella Brisa» o «La tienda».';
        }
        $image = $this->image($request, 'image');
        if (null === $image || false === $image) {
            $errors['image'] = 'Sube su imagen, elige una de «Imágenes» o genérala.';
        }
        if ([] !== $errors) {
            return new JsonResponse(['errors' => $errors], 422);
        }
        try {
            $element = $this->brands->addElement($this->current->require(), $id, $kind, $name, (string) $image, $form->getBoolean('cards'));
        } catch (\RangeException $e) {
            return new JsonResponse(['errors' => ['' => $e->getMessage()]], 422);
        }

        return new JsonResponse(['element' => $element]);
    }

    #[Route('/marcas/{id}/elementos/{element}/borrar', name: 'brand_element_delete', requirements: ['id' => '[0-9a-z]{26}', 'element' => '[0-9a-z]{26}'], methods: ['POST'])]
    public function deleteElement(string $id, string $element): JsonResponse
    {
        return $this->brands->deleteElement($this->current->require(), $id, $element)
            ? new JsonResponse(['ok' => true])
            : new JsonResponse(['errors' => ['' => 'Ese elemento ya no existe.']], 404);
    }

    #[Route('/marcas/{id}/elementos/{element}', name: 'brand_element_image', requirements: ['id' => '[0-9a-z]{26}', 'element' => '[0-9a-z]{26}'], methods: ['GET'])]
    public function elementImage(string $id, string $element): Response
    {
        $found = $this->brands->element($this->current->require(), $id, $element) ?? throw $this->createNotFoundException();

        return new BinaryFileResponse($found['path']);
    }

    /**
     * La imagen de un campo: el fichero subido o la de «Imágenes» elegida (campo + «Library»).
     *
     * @return string|false|null la ruta, false si el fichero no vale, null si no hay ninguna
     */
    private function image(Request $request, string $field): string|false|null
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get($field);
        if (null !== $file) {
            $ok = $file->isValid() && \in_array(strtolower($file->getClientOriginalExtension()), self::IMAGE_TYPES, true) && $file->getSize() <= 20 * 1024 * 1024;

            return $ok ? $file->getPathname() : false;
        }
        $entry = $this->library->get($request->request->getString($field.'Library'));

        return null === $entry ? null : $this->library->path($entry);
    }

    private static function hex(string $value): ?string
    {
        return 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : null;
    }
}
