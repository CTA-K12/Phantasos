<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pre-creates a management-site user by email. Google sign-in links the account on first
 * login and fills in the real name and photo. Needed to bootstrap the first admin on a
 * fresh database; after that, use Users in the UI.
 */
#[AsCommand(name: 'app:user:create', description: 'Create (or update) a management-site user who signs in with Google')]
class CreateUserCommand extends Command
{
    private const ROLES = ['ROLE_ADMIN', 'ROLE_SUPERVISOR', 'ROLE_STAFF'];

    public function __construct(
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Google Workspace email')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, implode(' | ', self::ROLES), 'ROLE_ADMIN')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name until first sign-in (defaults to the email name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $role  = (string) $input->getOption('role');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error(sprintf('"%s" is not an email address.', $email));
            return Command::INVALID;
        }
        if (!in_array($role, self::ROLES, true)) {
            $io->error(sprintf('Role must be one of %s.', implode(', ', self::ROLES)));
            return Command::INVALID;
        }

        $user    = $this->users->findByEmail($email);
        $created = $user === null;
        if ($user === null) {
            $user = (new User())->setEmail($email);
            $name = $input->getOption('name');
            $user->setDisplayName(is_string($name) && $name !== '' ? $name : (strstr($email, '@', true) ?: $email));
        }
        $user->setRoles([$role])->setIsActive(true);
        $this->users->save($user, true);

        $io->success(sprintf('%s %s (%s, active).', $created ? 'Created' : 'Updated', $email, $role));
        return Command::SUCCESS;
    }
}
