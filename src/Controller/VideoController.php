<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\PlanAgent;
use App\Agent\PlanChecks;
use App\Agent\SchemaValidator;
use App\Job\Estimate;
use App\Job\Job;
use App\Job\JobStore;
use App\Library\ImageLibrary;
use App\Providers\VideoCatalog;
use App\Render\VideoFonts;
use App\Sound\SoundCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The screens of a video (Subir, Propuesta, Vídeo), «Mis vídeos» and the JSON the screens ask
 * for. The web never calls a paid provider: it writes files and changes the status of job.json,
 * and the worker does the rest.
 */
final class VideoController extends AbstractController
{
    public const DURATIONS = [10, 15, 20, 30];
    private const VIDEO_TYPES = ['mp4', 'mov', 'webm'];
    private const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp'];
    /** Step → what the screen says while the worker does it. */
    public const STEP_LABELS = [
        'check' => 'Comprobando los ficheros',
        'frames' => 'Leyendo la referencia',
        'transcribe' => 'Transcribiendo',
        'plan' => 'El agente prepara la propuesta',
        'revise' => 'El agente aplica tu cambio',
        'voice' => 'Voz',
        'images' => 'Imágenes',
        'clips' => 'Clips',
        'music' => 'Música',
        'assemble' => 'Montaje',
    ];
    public const STATUS_LABELS = [
        'analysing' => ['Preparando la propuesta', 'info'],
        'revising' => ['Aplicando tu cambio', 'info'],
        'proposal' => ['Propuesta lista', 'primary'],
        'generating' => ['Generando', 'info'],
        'ready' => ['Listo', 'success'],
        'failed' => ['Con error', 'danger'],
    ];

    public function __construct(
        private readonly JobStore $store,
        private readonly ImageLibrary $library,
        private readonly SchemaValidator $schema,
        private readonly PlanAgent $agent,
        private readonly Estimate $estimate,
        private readonly VideoCatalog $videos,
        private readonly SoundCatalog $sound,
    ) {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        return $this->redirectToRoute([] === $this->store->all() ? 'video_new' : 'video_list');
    }

    #[Route('/videos', name: 'video_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $groups = ['todos' => null, 'en-curso' => ['analysing', 'revising', 'generating'], 'propuestas' => ['proposal'], 'listos' => ['ready'], 'con-error' => ['failed']];
        $tab = isset($groups[$request->query->getString('estado')]) ? $request->query->getString('estado') : 'todos';
        $jobs = $this->store->all();
        $counts = [];
        foreach ($groups as $key => $statuses) {
            $counts[$key] = \count(null === $statuses ? $jobs : array_filter($jobs, static fn (Job $j) => \in_array($j->status(), $statuses, true)));
        }
        $shown = null === $groups[$tab] ? $jobs : array_values(array_filter($jobs, static fn (Job $j) => \in_array($j->status(), $groups[$tab], true)));

        return $this->render('videos/list.html.twig', ['jobs' => $shown, 'tab' => $tab, 'counts' => $counts, 'labels' => self::STATUS_LABELS]);
    }

    #[Route('/videos/nuevo', name: 'video_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('videos/new.html.twig', [
            'durations' => self::DURATIONS,
            'fonts' => array_keys(VideoFonts::FAMILIES),
            'models' => $this->videos->all(),
            'defaultVoice' => (string) $this->sound->voice()['defaultVoiceId'],
            'library' => $this->library->all(),
        ]);
    }

    #[Route('/videos/nuevo', name: 'video_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $form = $request->request;
        $errors = [];
        $url = trim($this->text($form->getString('referenceUrl')));
        /** @var UploadedFile|null $reference */
        $reference = $request->files->get('reference');
        if (null === $reference && '' === $url) {
            $errors['reference'] = 'Sube el vídeo de referencia o pega su enlace.';
        } elseif (null !== $reference && !$this->acceptable($reference, self::VIDEO_TYPES, 200)) {
            $errors['reference'] = 'El vídeo debe ser mp4, mov o webm y ocupar como mucho 200 MB.';
        } elseif (null === $reference && 1 !== preg_match('#^https://\S+$#', $url)) {
            $errors['reference'] = 'El enlace debe empezar por https://.';
        }
        $person = $this->image($request, 'person', $errors, 'Sube la foto de la persona o elige una de tus imágenes.', true);
        $logo = $this->image($request, 'logo', $errors, '', false);
        $mode = $form->getString('mode');
        if (!\in_array($mode, ['talking_person', 'voice_over'], true)) {
            $errors['mode'] = 'Elige si la persona habla a cámara o hay voz en off.';
        }
        $duration = $form->getInt('durationSeconds');
        if (!\in_array($duration, self::DURATIONS, true)) {
            $errors['durationSeconds'] = 'Elige una duración.';
        }
        $product = trim($this->text($form->getString('product')));
        if (mb_strlen($product) < 10 || mb_strlen($product) > 1000) {
            $errors['product'] = 'Cuenta qué se anuncia en entre 10 y 1.000 caracteres.';
        }
        $voice = trim($form->getString('voiceId'));
        if (1 !== preg_match('/^[A-Za-z0-9]{8,40}$/', $voice)) {
            $errors['voiceId'] = 'Elige una voz.';
        }
        if ([] !== $errors) {
            return new JsonResponse(['errors' => $errors], 422);
        }

        $colors = implode(', ', array_filter([$this->hex($form->getString('color1')), $this->hex($form->getString('color2'))]));
        $job = $this->store->create([
            'mode' => $mode,
            'durationSeconds' => $duration,
            'product' => $product,
            'note' => mb_substr(trim($this->text($form->getString('note'))), 0, 1000),
            'voiceId' => $voice,
            'model' => \in_array($form->getString('model'), array_column($this->videos->all(), 'id'), true) ? $form->getString('model') : null,
            'brand' => [
                'name' => mb_substr(trim($this->text($form->getString('brandName'))), 0, 80),
                'colors' => $colors,
                'font' => VideoFonts::resolve($form->getString('font')) ?? VideoFonts::DEFAULT,
                'forbiddenWords' => array_values(array_filter(array_map(static fn (string $w) => mb_substr(trim($w), 0, 40), explode(',', $this->text($form->getString('forbiddenWords')))), static fn (string $w) => '' !== $w)),
            ],
            'referenceFile' => null,
            'referenceUrl' => null === $reference ? $url : null,
            'personFile' => null,
            'logoFile' => null,
        ]);
        if (null !== $reference) {
            $name = 'reference-upload.'.strtolower($reference->getClientOriginalExtension());
            $reference->move($job->path('input'), $name);
            $job->data['input']['referenceFile'] = 'input/'.$name;
        }
        $job->data['input']['personFile'] = $this->keep($job, $person, 'person-upload');
        $job->data['input']['logoFile'] = null === $logo ? null : $this->keep($job, $logo, 'logo-upload');
        $this->store->save($job);
        $job->log('Vídeo creado desde la web');

        return new JsonResponse(['url' => $this->generateUrl('video_show', ['id' => $job->id()])]);
    }

    #[Route('/videos/{id}', name: 'video_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $job = $this->job($id);
        $generating = \in_array($job->status(), ['generating', 'ready'], true) || ('failed' === $job->status() && 'generating' === $job->data['failedIn']);

        return $this->redirectToRoute($generating ? 'video_video' : 'video_proposal', ['id' => $id]);
    }

    #[Route('/videos/{id}/propuesta', name: 'video_proposal', methods: ['GET'])]
    public function proposal(string $id): Response
    {
        $job = $this->job($id);
        $frames = is_file($job->path('reference/frames.json')) ? json_decode((string) file_get_contents($job->path('reference/frames.json')), true) : [];

        return $this->render('videos/proposal.html.twig', [
            'job' => $job,
            'plan' => $job->plan(),
            'frames' => $frames,
            'labels' => self::STEP_LABELS,
            'status' => self::STATUS_LABELS,
            'maxWordsPerSecond' => PlanChecks::MAX_WORDS_PER_SECOND,
        ]);
    }

    #[Route('/videos/{id}/video', name: 'video_video', methods: ['GET'])]
    public function video(string $id): Response
    {
        $job = $this->job($id);

        return $this->render('videos/video.html.twig', ['job' => $job, 'labels' => self::STEP_LABELS, 'status' => self::STATUS_LABELS]);
    }

    #[Route('/videos/{id}/estado', name: 'video_state', methods: ['GET'])]
    public function state(string $id): JsonResponse
    {
        $job = $this->job($id);
        $status = $job->status();
        $phase = 'failed' === $status ? (string) $job->data['failedIn'] : $status;
        $steps = [];
        $current = 'failed' === $status ? ($job->data['error']['step'] ?? null) : $job->step();
        $passed = true;
        foreach (Job::STEPS[$phase] ?? [] as $step) {
            if ($step === $current) {
                $passed = false;
            }
            $steps[] = ['name' => $step, 'label' => self::STEP_LABELS[$step], 'state' => $step === $current ? ('failed' === $status ? 'failed' : 'current') : ($passed ? 'done' : 'pending')];
        }
        if (\in_array($status, ['proposal', 'ready'], true)) {
            $steps = array_map(static fn (array $s) => ['state' => 'done'] + $s, $steps);
        }
        $images = [];
        foreach ($job->data['outputs']['images'] ?? [] as $piece => $files) {
            foreach ($files as $file) {
                $images[] = $this->generateUrl('video_file', ['id' => $id, 'path' => $file]);
            }
        }

        return new JsonResponse([
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status][0] ?? $status,
            'step' => $job->step(),
            'steps' => $steps,
            'error' => $job->data['error']['message'] ?? null,
            'waiting' => ($job->data['waitUntil'] ?? 0) > time(),
            'planVersion' => $job->data['plan']['version'] ?? null,
            'clips' => $job->data['outputs']['clipsProgress'] ?? null,
            'images' => $images,
            'warnings' => $job->data['warnings'] ?? [],
        ]);
    }

    #[Route('/videos/{id}/plan', name: 'video_plan_save', methods: ['POST'])]
    public function savePlan(string $id, Request $request): JsonResponse
    {
        $plan = json_decode($request->getContent(), true);
        if (!\is_array($plan)) {
            return new JsonResponse(['errors' => ['' => 'El plan no se ha podido leer.']], 400);
        }
        $result = null;
        $this->store->locked($id, function (Job $job) use ($plan, &$result): void {
            if ('proposal' !== $job->status()) {
                $result = new JsonResponse(['errors' => ['' => 'Ahora no se puede cambiar el plan: espera a que termine el paso en curso.']], 409);

                return;
            }
            $errors = $this->schema->errors($plan);
            if ([] === $errors) {
                $errors = PlanChecks::errors($plan, $this->agent->checkData($job));
            }
            if ([] !== $errors) {
                $result = new JsonResponse(['errors' => $errors], 422);

                return;
            }
            $version = $job->savePlan($plan, 'person');
            $job->data['estimate'] = $this->estimate->of($plan, $job->input()['model'] ?? null);
            $job->log('Plan editado a mano: versión '.$version);
            $result = new JsonResponse(['version' => $version, 'estimate' => $job->data['estimate']]);
        });

        return $result ?? new JsonResponse(['errors' => ['' => 'El vídeo no existe.']], 404);
    }

    #[Route('/videos/{id}/cambio', name: 'video_revise', methods: ['POST'])]
    public function revise(string $id, Request $request): JsonResponse
    {
        $change = trim($request->request->getString('change'));
        if (mb_strlen($change) < 3 || mb_strlen($change) > 600) {
            return new JsonResponse(['errors' => ['change' => 'Escribe el cambio en entre 3 y 600 caracteres.']], 422);
        }

        return $this->move($id, ['proposal'], static function (Job $job) use ($change): void {
            $job->data['revision'] = ['request' => $change, 'doneInVersion' => null];
            $job->start('revising');
        });
    }

    #[Route('/videos/{id}/generar', name: 'video_generate', methods: ['POST'])]
    public function generate(string $id): JsonResponse
    {
        return $this->move($id, ['proposal'], static function (Job $job): void {
            $job->data['result'] = null;
            $job->data['warnings'] = [];
            $job->start('generating');
        }, 'video_video');
    }

    #[Route('/videos/{id}/editar', name: 'video_edit', methods: ['POST'])]
    public function edit(string $id): JsonResponse
    {
        return $this->move($id, ['ready', 'failed'], static function (Job $job): void {
            $job->data['status'] = 'proposal';
            $job->data['step'] = null;
            $job->data['error'] = null;
            $job->data['failedIn'] = null;
        }, 'video_proposal');
    }

    #[Route('/videos/{id}/reintentar', name: 'video_retry', methods: ['POST'])]
    public function retry(string $id): JsonResponse
    {
        return $this->move($id, ['failed'], static fn (Job $job) => $job->retry());
    }

    #[Route('/videos/{id}/borrar', name: 'video_delete', methods: ['POST'])]
    public function delete(string $id): JsonResponse
    {
        if (!$this->store->delete($id)) {
            return new JsonResponse(['errors' => ['' => 'No se puede borrar un vídeo mientras se está preparando o generando.']], 409);
        }

        return new JsonResponse(['url' => $this->generateUrl('video_list')]);
    }

    /**
     * A file of the video folder: frames, images, the video and its poster. Only those folders.
     */
    #[Route('/videos/{id}/fichero/{path}', name: 'video_file', requirements: ['path' => '(reference|images|out|input)/[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function download(string $id, string $path, Request $request): Response
    {
        $job = $this->job($id);
        $file = $job->path($path);
        if (!is_file($file)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($file);
        if ($request->query->getBoolean('descargar') && 'out/video.mp4' === $path) {
            // The download always keeps its .mp4: before, it came without extension.
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, \sprintf('anuncio-%s.mp4', date('Y-m-d-His', (int) filemtime($file))));
        }

        return $response;
    }

    /**
     * @param list<string>          $from
     * @param callable(Job): void   $change
     */
    private function move(string $id, array $from, callable $change, string $then = 'video_show'): JsonResponse
    {
        $result = null;
        $this->store->locked($id, function (Job $job) use ($from, $change, &$result): void {
            if (!\in_array($job->status(), $from, true)) {
                $result = false;

                return;
            }
            $change($job);
            $result = true;
        });
        if (true !== $result) {
            return new JsonResponse(['errors' => ['' => null === $result ? 'El vídeo no existe.' : 'Ahora no se puede hacer: el vídeo ha cambiado de estado. Recarga la página.']], null === $result ? 404 : 409);
        }

        return new JsonResponse(['url' => $this->generateUrl($then, ['id' => $id])]);
    }

    private function job(string $id): Job
    {
        return $this->store->get($id) ?? throw $this->createNotFoundException('Ese vídeo no existe.');
    }

    /**
     * An uploaded image, or one of the library chosen instead.
     *
     * @param array<string, string> $errors
     *
     * @return UploadedFile|string|null the upload, the path of the library image, or null
     */
    private function image(Request $request, string $field, array &$errors, string $missing, bool $required): UploadedFile|string|null
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get($field);
        if (null !== $file) {
            if (!$this->acceptable($file, self::IMAGE_TYPES, 20)) {
                $errors[$field] = 'La imagen debe ser JPEG, PNG o WebP y ocupar como mucho 20 MB.';
            }

            return $file;
        }
        $entry = $this->library->get($request->request->getString($field.'Library'));
        if (null !== $entry) {
            return $this->library->path($entry);
        }
        if ($required) {
            $errors[$field] = $missing;
        }

        return null;
    }

    private function keep(Job $job, UploadedFile|string $image, string $name): string
    {
        if ($image instanceof UploadedFile) {
            $file = $name.'.'.strtolower($image->getClientOriginalExtension());
            $image->move($job->path('input'), $file);
        } else {
            $file = $name.'.'.pathinfo($image, \PATHINFO_EXTENSION);
            copy($image, $job->path('input/'.$file));
        }

        return 'input/'.$file;
    }

    /**
     * @param list<string> $extensions
     */
    private function acceptable(UploadedFile $file, array $extensions, int $maxMb): bool
    {
        return $file->isValid() && \in_array(strtolower($file->getClientOriginalExtension()), $extensions, true) && $file->getSize() <= $maxMb * 1024 * 1024;
    }

    /** A client that does not send UTF-8 (a Windows console) would break job.json. */
    private function text(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function hex(string $value): ?string
    {
        return 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : null;
    }
}
