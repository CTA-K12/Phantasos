<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<User> */
class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label'       => 'Display Name',
                'attr'        => ['placeholder' => 'Jane Smith'],
                'empty_data'  => '',
                'constraints' => [new Assert\NotBlank(message: 'Display name is required.')],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Email Address',
                'attr'        => ['placeholder' => 'jane@mesd.k12.or.us'],
                'empty_data'  => '',
                'constraints' => [
                    new Assert\NotBlank(message: 'Email is required.'),
                    new Assert\Email(message: 'Please enter a valid email address.'),
                ],
            ])
            ->add('role', ChoiceType::class, [
                'label'    => 'Role',
                'mapped'   => false,
                'choices'  => [
                    'Staff'      => 'ROLE_STAFF',
                    'Supervisor' => 'ROLE_SUPERVISOR',
                    'Admin'      => 'ROLE_ADMIN',
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'Active — user can log in',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
