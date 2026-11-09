<?php declare(strict_types=1);

namespace UserProfile\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    /**
     * @var string[]
     */
    protected $impossibleFormats = [];

    /**
     * @var string
     */
    protected $rawMessage = '';

    /**
     * Set the explanation displayed when the list cannot be edited as a form.
     */
    public function setRawMessage(string $rawMessage): self
    {
        $this->rawMessage = $rawMessage;
        return $this;
    }

    /**
     * Set the formats to which the current list of fields cannot be converted.
     */
    public function setImpossibleFormats(array $impossibleFormats): self
    {
        $this->impossibleFormats = $impossibleFormats;
        return $this;
    }

    public function init(): void
    {
        $placeholder = <<<'TXT'
            userprofile_phone:
              label: Phone
              type: tel
            userprofile_organisation:
              label: Organisation
              type: select
              values:
                none: None
                Alpha: Alpha
                Beta: Beta
                Gamma’s Delta: Gamma’s Delta
              attributes:
                class: chosen-select
                data-placeholder: Select an organisation…
            TXT;

        $this
            ->add([
                'name' => 'userprofile_elements',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'List of fields', // @translate
                    'info' => 'List all input elements in the simple syntax, editable as a form, or as Laminas specifications in yaml, ini, json or xml. It is recommended to prepend “userprofile_” to field names.', // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-UserProfile#Usage',
                ],
                'attributes' => [
                    'id' => 'userprofile_elements',
                    // The editor of module Common edits the simple syntax as a
                    // form, but not the full Laminas specifications.
                    'class' => 'common-fields-textarea',
                    'data-raw-keys' => 'elements',
                    'data-raw-message' => $this->rawMessage,
                    'data-enable-form' => '1',
                    'data-enable-yaml' => '1',
                    'data-enable-preview' => '1',
                    'data-default-display' => 'form',
                    'required' => false,
                    'autofocus' => 'autofocus',
                    'rows' => '30',
                    'placeholder' => $placeholder,
                ],
            ])
            ->add([
                'name' => 'userprofile_convert_format',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Convert the list of fields when saving', // @translate
                    'info' => 'The comments are not kept. A format is disabled when a key or a value of the current list cannot be stored in it.', // @translate
                    'value_options' => ['' => 'Keep the current format'] // @translate
                        + $this->formatOptions(),
                ],
                'attributes' => [
                    'id' => 'userprofile_convert_format',
                    'required' => false,
                    'value' => '',
                ],
            ])
        ;
    }

    protected function formatOptions(): array
    {
        $options = [];
        $labels = [
            'fields' => 'simple syntax, editable as a form', // @translate
        ];
        foreach (['fields', 'yaml', 'ini', 'json', 'xml'] as $format) {
            $options[$format] = [
                'value' => $format,
                'label' => $labels[$format] ?? $format,
                'disabled' => in_array($format, $this->impossibleFormats, true),
            ];
        }
        return $options;
    }
}
