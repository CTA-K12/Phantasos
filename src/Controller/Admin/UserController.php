<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $this->userRepository->findAllOrderedByName(),
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $user->setIsActive(true); // Default to active when admin creates a user

        $form = $this->createForm(UserType::class, $user);
        $form->get('role')->setData('ROLE_STAFF'); // Default role

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $role = $form->get('role')->getData();
            if (is_string($role) && $role !== '') {
                $user->setRoles([$role]);
            }

            $this->userRepository->save($user, true);

            $this->auditLogger->log('user.created', 'User', $user->getId()->toRfc4122(), [
                'email' => $user->getEmail(),
                'role'  => $role ?? 'ROLE_STAFF',
            ]);

            $this->addFlash('success', sprintf('User "%s" created successfully.', $user->getDisplayName()));

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $user = $this->findUserOr404($id);

        $form = $this->createForm(UserType::class, $user);
        $form->get('role')->setData($this->getPrimaryRole($user));

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $role = $form->get('role')->getData();
            if (is_string($role) && $role !== '') {
                $user->setRoles([$role]);
            }

            $this->userRepository->save($user, true);

            $this->auditLogger->log('user.updated', 'User', $user->getId()->toRfc4122(), [
                'email' => $user->getEmail(),
                'role'  => $role ?? 'ROLE_STAFF',
            ]);

            $this->addFlash('success', sprintf('User "%s" updated successfully.', $user->getDisplayName()));

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/toggle', name: 'admin_user_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        $user = $this->findUserOr404($id);

        if (!$this->isCsrfTokenValid('toggle-user-' . $id, $request->request->getString('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');

            return $this->redirectToRoute('admin_user_index');
        }

        $user->setIsActive(!$user->isActive());
        $this->userRepository->save($user, true);

        $this->auditLogger->log('user.updated', 'User', $user->getId()->toRfc4122(), [
            'email'    => $user->getEmail(),
            'isActive' => $user->isActive(),
        ]);

        $this->addFlash(
            'success',
            sprintf('"%s" has been %s.', $user->getDisplayName(), $user->isActive() ? 'activated' : 'deactivated')
        );

        return $this->redirectToRoute('admin_user_index');
    }

    private function findUserOr404(string $id): User
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('User not found.');
        }

        $user = $this->userRepository->find($uuid);
        if ($user === null) {
            throw $this->createNotFoundException('User not found.');
        }

        return $user;
    }

    private function getPrimaryRole(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return 'ROLE_ADMIN';
        }
        if (in_array('ROLE_SUPERVISOR', $roles, true)) {
            return 'ROLE_SUPERVISOR';
        }

        return 'ROLE_STAFF';
    }
}
