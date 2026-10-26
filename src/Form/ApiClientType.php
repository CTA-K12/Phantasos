<?php

namespace App\Form;

use App\Entity\ApiClient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiClient> */
class ApiClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'help' => 'Shown to the client as `owner` in mediaInfo. One client per application environment, e.g. "Training-QA".',
            ])
            ->add('description', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3]])
            ->add('contactName', TextType::class, ['required' => false, 'label' => 'Contact name'])
            ->add('contactEmail', EmailType::class, ['required' => false, 'label' => 'Contact email']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // The controller always passes an ApiClient (new ones come from ApiKeyIssuer).
        $resolver->setDefaults(['data_class' => ApiClient::class]);
    }
}
