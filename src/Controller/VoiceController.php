<?php

declare(strict_types=1);

namespace App\Controller;

use App\Job\StepFailed;
use App\Providers\ElevenLabsVoiceGateway;
use App\Providers\ProviderUnavailable;
use App\Providers\VideoRejected;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The voices of ElevenLabs for the upload form, Spanish ones first, and a sample of each.
 */
final class VoiceController extends AbstractController
{
    public function __construct(private readonly ElevenLabsVoiceGateway $voices)
    {
    }

    #[Route('/voces', name: 'voice_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $voices = $this->voices->voices();
        } catch (StepFailed|ProviderUnavailable|VideoRejected $e) {
            return new JsonResponse(['errors' => ['' => $e->getMessage()]], 502);
        }
        $spanish = static fn (array $v): bool => 1 === preg_match('/\b(es|spanish|español|castellano|spain|mexican|latin)\b/iu', implode(' ', $v['labels']).' '.$v['description'].' '.$v['name']);
        usort($voices, static fn (array $a, array $b) => [$spanish($b), $a['name']] <=> [$spanish($a), $b['name']]);

        return new JsonResponse(array_map(static fn (array $v) => [
            'id' => $v['id'],
            'name' => $v['name'],
            'spanish' => $spanish($v),
            'detail' => implode(' · ', array_filter([$v['labels']['gender'] ?? null, $v['labels']['accent'] ?? null, $v['labels']['age'] ?? null])),
        ], $voices));
    }

    #[Route('/voces/{id}/muestra', name: 'voice_sample', requirements: ['id' => '[A-Za-z0-9]{8,40}'], methods: ['GET'])]
    public function sample(string $id): Response
    {
        try {
            $sample = $this->voices->sample($id);
        } catch (StepFailed|ProviderUnavailable|VideoRejected $e) {
            return new JsonResponse(['errors' => ['' => $e->getMessage()]], 502);
        }
        $response = new BinaryFileResponse($sample['path']);
        $response->headers->set('Content-Type', $sample['contentType']);
        $response->deleteFileAfterSend();

        return $response;
    }
}
