<?php
namespace GuestProfile\Form;

use Zend\Form\Element;
use Zend\Form\Fieldset;

class UserSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Guest Profile'; // @translate

    public function init()
    {
        $this
            ->add([
                'name' => 'guestprofile_field_1',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Statut', // @translate
                    'empty_option' => 'Choisissez votre statut…', // @translate
                    'value_options' => [
                        'particulier' => 'Particulier',
                        'auteur' => 'Auteur',
                        'chercheur' => 'Chercheur',
                        'étudiant' => 'Étudiant',
                        'éditeur' => 'Éditeur',
                        'revue' => 'Revue',
                        'magazine' => 'Magazine',
                        'bibliothèque' => 'Bibliothèque',
                        'institut' => 'Institut',
                        'autre' => 'Autre',
                    ],
                ],
                'attributes' => [
                    'id' => 'guestprofile_field_1',
                ],
            ])
            ->add([
                'name' => 'guestprofile_field_2',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Autre statut…', // @translate
                ],
                'attributes' => [
                    'id' => 'guestprofile_field_2',
                    'placeholder' => 'Indiquez votre statut…', // @translate
                ],
            ])
        ;
    }
}
