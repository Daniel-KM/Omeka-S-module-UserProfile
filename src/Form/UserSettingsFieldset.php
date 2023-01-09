<?php declare(strict_types=1);

namespace UserProfile\Form;

use Laminas\Form\Fieldset;

class UserSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'User Profile'; // @translate

    protected $elementGroups = [
        'profile' => 'Profile', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'user-profile')
            ->setOption('element_groups', $this->elementGroups)
        ;
    }
}
