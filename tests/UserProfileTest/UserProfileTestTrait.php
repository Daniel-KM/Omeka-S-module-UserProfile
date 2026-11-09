<?php declare(strict_types=1);

namespace UserProfileTest;

use Laminas\ServiceManager\ServiceLocatorInterface;

trait UserProfileTestTrait
{
    /**
     * @var int[]
     */
    protected $createdUsers = [];

    protected $configIni = <<<'INI'
        elements.userprofile_text.name                              = "userprofile_text"
        elements.userprofile_text.type                              = "Text"
        elements.userprofile_text.options.label                     = "Text field"
        elements.userprofile_text.attributes.id                     = "userprofile_text"

        elements.userprofile_select.name                            = "userprofile_select"
        elements.userprofile_select.type                            = "Select"
        elements.userprofile_select.options.label                   = "Select field"
        elements.userprofile_select.options.empty_option            = ""
        elements.userprofile_select.options.value_options[alpha]    = "Alpha"
        elements.userprofile_select.options.value_options[beta]     = "Beta"
        elements.userprofile_select.attributes.id                   = "userprofile_select"

        elements.userprofile_multi.name                             = "userprofile_multi"
        elements.userprofile_multi.type                             = "MultiCheckbox"
        elements.userprofile_multi.options.label                    = "Multiple field"
        elements.userprofile_multi.options.value_options[one]       = "One"
        elements.userprofile_multi.options.value_options[two]       = "Two"
        elements.userprofile_multi.attributes.id                    = "userprofile_multi"

        elements.userprofile_check.name                             = "userprofile_check"
        elements.userprofile_check.type                             = "Checkbox"
        elements.userprofile_check.options.label                    = "Checkbox field"
        elements.userprofile_check.attributes.id                    = "userprofile_check"

        elements.userprofile_hidden.name                            = "userprofile_hidden"
        elements.userprofile_hidden.type                            = "Text"
        elements.userprofile_hidden.options.label                   = "Hidden field"
        elements.userprofile_hidden.options.show                    = "none"
        elements.userprofile_hidden.options.edit                    = "none"
        elements.userprofile_hidden.attributes.id                   = "userprofile_hidden"

        elements.userprofile_category.name                          = "userprofile_category"
        elements.userprofile_category.type                          = "Text"
        elements.userprofile_category.options.label                 = "Category set by admins"
        elements.userprofile_category.options.edit                  = "admin"

        elements.userprofile_personal.name                          = "userprofile_personal"
        elements.userprofile_personal.type                          = "Text"
        elements.userprofile_personal.options.label                 = "Personal setting"
        elements.userprofile_personal.options.edit                  = "user"

        elements.userprofile_admin_note.name                        = "userprofile_admin_note"
        elements.userprofile_admin_note.type                        = "Textarea"
        elements.userprofile_admin_note.options.label               = "Internal note"
        elements.userprofile_admin_note.options.show                = "admin"
        elements.userprofile_admin_note.options.edit                = "admin"

        elements.userprofile_private.name                           = "userprofile_private"
        elements.userprofile_private.type                           = "Text"
        elements.userprofile_private.options.label                  = "Private preference"
        elements.userprofile_private.options.show                   = "user"
        elements.userprofile_private.options.edit                   = "user"
        INI;

    protected function getServiceLocator(): ServiceLocatorInterface
    {
        return $this->getApplication()->getServiceManager();
    }

    protected function module(): \UserProfile\Module
    {
        $module = new \UserProfile\Module();
        $module->setServiceLocator($this->getServiceLocator());
        return $module;
    }

    protected function invokeModule(string $method, array $args = [])
    {
        $module = $this->module();
        $reflection = new \ReflectionMethod($module, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($module, $args);
    }

    /**
     * Set the config of the module and update the list of fields.
     */
    protected function setConfig(?string $config = null): bool
    {
        $this->getServiceLocator()->get('Omeka\Settings')
            ->set('userprofile_elements', $config ?? $this->configIni);
        return $this->invokeModule('updateListFields');
    }

    protected function resetConfig(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('userprofile_elements', '');
        $settings->set('userprofile_fields', []);
        $settings->delete('userprofile_exclude');
    }

    /**
     * Mark the current request as a request of the rest api for a user.
     */
    protected function setApiRequest(bool $isApiRequest = true, ?int $userId = null): void
    {
        if ($isApiRequest) {
            $this->getApplication()->getMvcEvent()->setRouteMatch(new \Laminas\Router\Http\RouteMatch([
                '__API__' => true,
                'controller' => 'Omeka\Controller\ApiController',
                'resource' => 'users',
                'id' => $userId,
            ]));
        }
        $status = $this->getServiceLocator()->get('Omeka\Status');
        $property = new \ReflectionProperty($status, 'isApiRequest');
        $property->setAccessible(true);
        $property->setValue($status, $isApiRequest);
    }

    protected function createUser(string $email): int
    {
        $user = $this->api()->create('users', [
            'o:email' => $email,
            'o:name' => 'User Profile Test',
            'o:role' => 'researcher',
            'o:is_active' => true,
        ])->getContent();
        $this->createdUsers[] = $user->id();
        return $user->id();
    }

    protected function cleanupUsers(): void
    {
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        foreach ($this->createdUsers as $userId) {
            $connection->delete('user_setting', ['user_id' => $userId]);
            $connection->delete('user', ['id' => $userId]);
        }
        $this->createdUsers = [];
    }

    protected function userSetting(int $userId, string $key)
    {
        $value = $this->getServiceLocator()->get('Omeka\Connection')
            ->executeQuery('SELECT `value` FROM `user_setting` WHERE `id` = ? AND `user_id` = ?', [$key, $userId])
            ->fetchOne();
        return $value === false ? null : json_decode($value, true);
    }
}
