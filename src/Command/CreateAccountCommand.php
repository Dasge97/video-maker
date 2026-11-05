<?php

declare(strict_types=1);

namespace App\Command;

use App\Account\AccountStore;
use App\Account\KeyCipher;
use App\Controller\SecurityController;
use App\Job\JobStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates an account from the console. The password is asked without showing it, or read from
 * the standard input with --contrasena-stdin. --adoptar gives the account the videos and images
 * made before there were accounts; --claves-del-entorno saves as its keys the ones of the
 * environment (FAL_KEY, ELEVENLABS_API_KEY, ANTHROPIC_API_KEY, OPENAI_API_KEY, AIHUB_*).
 */
#[AsCommand(name: 'app:cuenta:crear', description: 'Crea una cuenta')]
final class CreateAccountCommand extends Command
{
    private const ENV_KEYS = ['fal' => 'FAL_KEY', 'elevenlabs' => 'ELEVENLABS_API_KEY', 'anthropic' => 'ANTHROPIC_API_KEY', 'openai' => 'OPENAI_API_KEY', 'aihub' => 'AIHUB_API_KEY'];

    public function __construct(
        private readonly AccountStore $accounts,
        private readonly JobStore $jobs,
        private readonly KeyCipher $cipher,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $libraryDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Correo con el que se entra')
            ->addOption('nombre', null, InputOption::VALUE_REQUIRED, 'Nombre que se ve arriba', '')
            ->addOption('contrasena-stdin', null, InputOption::VALUE_NONE, 'Lee la contraseña de la entrada estándar')
            ->addOption('adoptar', null, InputOption::VALUE_NONE, 'Le da los vídeos e imágenes que no son de nadie')
            ->addOption('claves-del-entorno', null, InputOption::VALUE_NONE, 'Guarda como suyas las claves de las variables de entorno');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = AccountStore::normalEmail((string) $input->getArgument('email'));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('El correo no es válido.');

            return Command::INVALID;
        }
        if (null !== $this->accounts->byEmail($email)) {
            $io->error('Ya hay una cuenta con ese correo.');

            return Command::FAILURE;
        }
        $password = $input->getOption('contrasena-stdin')
            ? rtrim((string) stream_get_contents(\STDIN), "\r\n")
            : (string) $io->askHidden('Contraseña');
        if (mb_strlen($password) < SecurityController::MIN_PASSWORD) {
            $io->error(\sprintf('La contraseña necesita %d caracteres como mínimo.', SecurityController::MIN_PASSWORD));

            return Command::INVALID;
        }

        $account = $this->accounts->create($email, (string) $input->getOption('nombre'));
        $account->data['passwordHash'] = $this->hasher->hashPassword($account, $password);
        if ($input->getOption('claves-del-entorno')) {
            foreach (self::ENV_KEYS as $provider => $variable) {
                $value = trim((string) ($_SERVER[$variable] ?? $_ENV[$variable] ?? ''));
                if ('' !== $value) {
                    $account->data['keys'][$provider] = $this->cipher->encrypt($value);
                }
            }
            $account->data['aihubUrl'] = rtrim(trim((string) ($_SERVER['AIHUB_URL'] ?? $_ENV['AIHUB_URL'] ?? '')), '/');
            $io->writeln(\sprintf('Claves guardadas: %d.', \count($account->data['keys'])));
        }
        $this->accounts->save($account);

        if ($input->getOption('adoptar')) {
            $videos = 0;
            foreach ($this->jobs->all() as $job) {
                if (null === $job->owner()) {
                    $this->jobs->locked($job->id(), static function ($job) use ($account): void { $job->data['owner'] = $account->id(); });
                    ++$videos;
                }
            }
            $images = 0;
            $target = $this->libraryDir.'/'.$account->id();
            is_dir($target) || mkdir($target, 0775, true);
            foreach (glob($this->libraryDir.'/*.*') ?: [] as $file) {
                if (is_file($file) && rename($file, $target.'/'.basename($file)) && str_ends_with($file, '.json')) {
                    ++$images;
                }
            }
            $io->writeln(\sprintf('Pasan a la cuenta %d vídeos y %d imágenes.', $videos, $images));
        }
        $io->success(\sprintf('Cuenta creada: %s.', $email));

        return Command::SUCCESS;
    }
}
