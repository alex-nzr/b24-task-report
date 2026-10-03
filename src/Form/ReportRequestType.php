<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ReportRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['employees'] as $employee)
        {
            $choices[$employee['name'].' (#'.$employee['id'].')'] = $employee['id'];
        }
        $builder->add('employeeId', ChoiceType::class, ['label' => 'Сотрудник', 'choices' => $choices, 'placeholder' => 'Выберите сотрудника', 'constraints' => [new NotBlank()]])
            ->add('from', DateType::class, ['label' => 'С даты', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]])
            ->add('to', DateType::class, ['label' => 'По дату включительно', 'widget' => 'single_text', 'input' => 'string', 'constraints' => [new NotBlank()]])
            ->add('collectNow', CheckboxType::class, ['label' => 'Сформировать сразу', 'required' => false]);
        $builder->get('collectNow')->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void
        {
            if ($event->getData() !== null && $event->getData() !== '1')
            {
                $event->getForm()->addError(new FormError('Некорректное значение флага немедленного сбора.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['employees' => [], 'csrf_token_id' => 'report_create']);
    }
}
