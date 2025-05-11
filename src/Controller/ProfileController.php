<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Persistence\ManagerRegistry;
use App\Form\ProfileType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/profile')]

final class ProfileController extends AbstractController
{
    #[Route('', name: 'profile_edit')]
    public function index(Request $request, ManagerRegistry $doctrine, UserPasswordHasherInterface $hasher): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Empêche les comptes non validés
        if (!$user->isApproved()) {
            $this->addFlash('warning', 'Votre compte doit d’abord être validé.');
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = $form->get('password')->getData();
            /** @var UploadedFile|null $photoFile */
            $photoFile = $form->get('photoFile')->getData();

            if ($plain) {
                $user->setPassword($hasher->hashPassword($user, $plain));

                // ➜ flash spécifique pour JS
                $this->addFlash('relog', 'Votre mot de passe a changé, vous allez être déconnecté.');
            } else {
                $this->addFlash('success', 'Profil mis à jour !');
            }

            $em = $doctrine->getManager();
            $em->persist($user);
            $em->flush();

            if ($photoFile) {
                $user->setPhotoFile(null);
            }
            return $this->redirectToRoute('profile_edit');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
