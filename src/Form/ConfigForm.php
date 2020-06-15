<?php
namespace UserProfile\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class ConfigForm extends Form
{
    public function init()
    {
        $placeholder = <<<'TXT'
elements.userprofile_phone.name = "userprofile_phone"
elements.userprofile_phone.type = "Tel"
elements.userprofile_phone.options.label = "Phone"
elements.userprofile_phone.attributes.id = "userprofile_phone"

elements.userprofile_organisation.name = "userprofile_organisation"
elements.userprofile_organisation.type = "Select"
elements.userprofile_organisation.options.label = "Organisation"
elements.userprofile_organisation.options.value_options.none = "None"
elements.userprofile_organisation.options.value_options.Alpha = "Alpha"
elements.userprofile_organisation.options.value_options.Beta = "Beta"
elements.userprofile_organisation.options.value_options.Gamma’s Delta = "Gamma’s Delta"
elements.userprofile_organisation.attributes.id = "userprofile_organisation"
elements.userprofile_organisation.attributes.class = "chosen-select"
elements.userprofile_organisation.attributes.data-placeholder = "Select an organisation…"
TXT;

        $this
            ->add([
                'name' => 'userprofile_elements',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'List of fields', // @translate
                    'info' => "List all input elements as an ini list, like the config of Omeka themes. It is recommended to prepend “userprofile_” to field names. No vertical apostrophe “'” in keys (use “’” instead).", // @translate
                ],
                'attributes' => [
                    'id' => 'userprofile_elements',
                    'required' => false,
                    'autofocus' => 'autofocus',
                    'rows' => '30',
                    'placeholder' => $placeholder,
                ],
            ])
        ;
    }
}
