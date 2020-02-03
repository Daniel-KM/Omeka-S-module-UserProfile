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
                'name' => 'guestprofile_field_research',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Research subject', // @translate
                ],
                'attributes' => [
                    'id' => 'guestprofile_field_research',
                ],
            ])
        ;
    }
}
