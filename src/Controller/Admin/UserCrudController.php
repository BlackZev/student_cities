<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\DBAL\Types\DateImmutableType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Collection\{FieldCollection, FilterCollection};
use EasyCorp\Bundle\EasyAdminBundle\Dto\{EntityDto, SearchDto};
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use Symfony\Component\PropertyAccess\PropertyAccess;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;



class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function createEntity(string $entityFqcn)
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setIsApproved(false);
        return $user;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $qb->andWhere('entity.id != :current')
            ->setParameter('current', $currentUser?->getId() ?? 0);

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        $password = TextField::new('password')
            ->setLabel("Mot de passe")
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('empty_data', '')
            ->hideOnIndex()
            ->hideOnDetail();

        return [
            IdField::new('id')->onlyOnIndex(),
            EmailField::new('email'),
            TextField::new('pseudo'),
            DateField::new('createdAt', 'Date de création')->hideOnForm(),
            $password,
            BooleanField::new('isApproved', 'Compte approuvé')
                ->renderAsSwitch(true)
                ->setFormTypeOption('disabled', false)
                ->setColumns('auto'),
            ChoiceField::new('roles')
                ->allowMultipleChoices()
                ->setChoices([
                    'User' => 'ROLE_USER',
                    'Admin' => 'ROLE_ADMIN',
                ])
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $revoke = Action::new('revokeUser', 'Révoquer', 'fa fa-ban')
            ->linkToCrudAction('toggleRevoke')
            ->addCssClass('btn btn-warning revoke-link')
            ->displayIf(fn(User $u) =>  $u->isApproved() && !$u->isRevoked());

        $restore = Action::new('restoreUser', 'Rétablir', 'fa fa-undo')
            ->linkToCrudAction('toggleRevoke')
            ->addCssClass('btn btn-success revoke-link')
            ->displayIf(fn(User $u) =>  $u->isApproved() &&  $u->isRevoked());

        return $actions
            ->add(Crud::PAGE_INDEX, $revoke)
            ->add(Crud::PAGE_INDEX, $restore)
            ->add(Crud::PAGE_DETAIL, $revoke)
            ->add(Crud::PAGE_DETAIL, $restore);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInPlural('Utilisateurs')
            ->setEntityLabelInSingular('Utilisateur')
            ->setSearchFields(['email', 'pseudo'])
            ->overrideTemplates([
                'crud/index' => 'admin/user_index.html.twig',
            ]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('isApproved', 'Approuvé'))
            ->add(BooleanFilter::new('isRevoked', 'Révoqué'));
    }

    private function updateUserFlag(
        AdminContext     $ctx,
        ManagerRegistry  $doctrine,
        string           $property,
        ?bool            $newValue,
        string           $msgIfTrue,
        string           $msgIfFalse,
        callable|null    $extra = null
    ): RedirectResponse {
        /** @var User $u */
        $u = $ctx->getEntity()->getInstance();

        $accessor = PropertyAccess::createPropertyAccessor();
        $current  = $accessor->getValue($u, $property);
        $value    = $newValue ?? !$current;
        $accessor->setValue($u, $property, $value);

        if ($extra) {
            $extra($u, $value);
        }

        $doctrine->getManager()->flush();
        $this->addFlash('success', $value ? $msgIfTrue : $msgIfFalse);

        $referrer = $ctx->getReferrer();
        if (!$referrer) {
            /** @var AdminUrlGenerator $urlGen */
            $urlGen = $this->container->get(AdminUrlGenerator::class);
            $referrer = $urlGen
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl();
        }
        return $this->redirect($referrer);
    }


    public function approveUser(AdminContext $ctx, ManagerRegistry $doctrine): RedirectResponse
    {
        return $this->updateUserFlag(
            $ctx,
            $doctrine,
            'isApproved',
            true,
            'Utilisateur approuvé.',
            'Opération annulée.',
            function (User $u) {
                $u->setRoles(['ROLE_USER']);
            }
        );
    }
    public function revokeUser(AdminContext $ctx, ManagerRegistry $doctrine): RedirectResponse
    {
        return $this->updateUserFlag(
            $ctx,
            $doctrine,
            'isApproved',
            false,
            'Utilisateur approuvé.',
            'Utilisateur révoqué.'
        );
    }

    public function toggleApproval(AdminContext $ctx, ManagerRegistry $doctrine): RedirectResponse
    {
        return $this->updateUserFlag(
            $ctx,
            $doctrine,
            'isApproved',
            null,                    // null = bascule
            'Utilisateur approuvé.',
            'Utilisateur désapprouvé.'
        );
    }

    public function toggleRevoke(AdminContext $ctx, ManagerRegistry $doctrine): RedirectResponse
    {
        /** @var User $user */
        $user = $ctx->getEntity()->getInstance();
        $user->setIsRevoked(!$user->isRevoked());

        $doctrine->getManager()->flush();

        $this->addFlash(
            $user->isRevoked() ? 'warning' : 'success',
            $user->isRevoked() ? 'Utilisateur révoqué.' : 'Utilisateur rétabli.'
        );

        /* retour propre */
        /** @var AdminUrlGenerator $url */
        $url = $this->container->get(AdminUrlGenerator::class);
        return $this->redirect(
            $ctx->getReferrer() ??
                $url->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl()
        );
    }
}
