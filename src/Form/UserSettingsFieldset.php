<?php declare(strict_types=1);
namespace UserProfile\Form;

use Laminas\Form\Fieldset;

class UserSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'User Profile'; // @translate

    public function init(): void
    {
    }
}
