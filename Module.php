<?php declare(strict_types=1);

/*
 * Copyright 2019-2024 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace UserProfile;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\Config\Reader\Ini as IniReader;
use Laminas\Config\Reader\Json as JsonReader;
use Laminas\Config\Reader\Xml as XmlReader;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
    ];

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.52')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.52'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Representation\UserRepresentation::class,
            'rep.resource.json',
            [$this, 'filterResourceJsonUser']
        );

        // Manage user settings via rest api (and the new user via ui).
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            // 'api.create.pre',
            'api.create.post',
            [$this, 'apiCreatePreUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.hydrate.pre',
            [$this, 'apiHydratePreUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.create.post',
            [$this, 'apiCreatePostUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.update.post',
            [$this, 'apiUpdatePostUser']
        );

        // Add the user profile to the user show admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.details',
            [$this, 'viewUserDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.show.after',
            [$this, 'viewUserShowAfter']
        );

        // Add the user profile elements to the user form.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'handleUserSettings']
        );
    }

    public function handleConfigForm(AbstractController $controller)
    {
        if (!$this->handleConfigFormAuto($controller)) {
            return false;
        }

        $this->updateListFields();
        return true;
    }

    protected function updateListFields(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $fieldsConfig = $this->readConfigElements();

        $fields = [];
        $exclude = [
            'admin' => ['show' => [], 'edit' => []],
            'public' => ['show' => [], 'edit' => []],
        ];
        foreach ($fieldsConfig['elements'] as $element) {
            if (!isset($element['name'])) {
                continue;
            }
            $fields[$element['name']] = empty($element['options']['label'])
                ? $element['name']
                : $element['options']['label'];
            if (!empty($element['options']['exclude_admin_show'])) {
                $exclude['admin']['show'] = $element['name'];
            }
            if (!empty($element['options']['exclude_admin_edit'])) {
                $exclude['admin']['edit'] = $element['name'];
            }
            if (!empty($element['options']['exclude_public_show'])) {
                $exclude['public']['show'] = $element['name'];
            }
            if (!empty($element['options']['exclude_public_edit'])) {
                $exclude['public']['edit'] = $element['name'];
            }
        }

        $settings->set('userprofile_fields', $fields);
        $settings->set('userprofile_exclude', $exclude);
    }

    public function handleUserSettings(Event $event): void
    {
        // Compatibility with module Guest.

        /**
         * @var \Omeka\Mvc\Status $status
         * @var \Laminas\Router\Http\RouteMatch $routeMatch
         */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        $routeMatch = $services->get('Application')->getMvcEvent()->getRouteMatch();
        if ($status->isSiteRequest()) {
            /** @var \Laminas\Router\Http\RouteMatch $routeMatch */
            $controller = $routeMatch->getParam('controller');
            if ($controller === \Guest\Controller\Site\AnonymousController::class) {
                if ($routeMatch->getParam('action') !== 'register') {
                    return;
                }
            } elseif ($controller === \Guest\Controller\Site\GuestController::class) {
                $user = $services->get('Omeka\AuthenticationService')->getIdentity();
                $routeMatch->setParam('id', $user->getId());
            } else {
                return;
            }
            $this->handleAnySettingsUser($event);
        }
        // Here, the upper method is overridden, so copy its content.
        elseif ($status->isAdminRequest()) {
            if (!in_array($routeMatch->getParam('controller'), ['Omeka\Controller\Admin\User', 'user'])) {
                return;
            }
            $this->handleAnySettingsUser($event);
        } else {
            $this->handleAnySettingsUser($event);
        }
    }

    protected function handleAnySettingsUser(Event $event): ?\Laminas\Form\Fieldset
    {
        $elements = $this->readConfigElements();
        if (!$elements) {
            return null;
        }

        /** @var \Guest\Form\UserForm $form */
        $form = $event->getTarget();
        $formFieldset = $this->handleAnySettings($event, 'user_settings');

        // Specific to this module.
        $services = $this->getServiceLocator();

        /**
         * These settings can be managed in admin or via guest.
         * The user may be created or not yet.
         * In admin, the user may not be the current user.
         *
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\Entity\User $user
         * @var \Omeka\Settings\UserSettings $userSettings
         */
        $status = $services->get('Omeka\Status');
        $isAdminRequest = $status->isAdminRequest();
        if ($isAdminRequest) {
            $userId = (int) $status->getRouteParam('id') ?: null;
        }
        if (empty($userId)) {
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            $userId = $user ? $user->getId() : null;
        }
        // Rights to edit user settings is already checked.
        if ($userId) {
            // In some cases (api), the user may not have been set.
            $userSettings = $services->get('Omeka\Settings\User');
            $userSettings->setTargetId($userId);
        } else {
            $userSettings = null;
        }

        $exclude = $this->excludedFields('edit');

        // In Omeka S < v4, the element groups are skipped.
        $elementGroups = [
            'profile' => 'Profile', // @translate
        ];

        foreach ($elements['elements'] as $name => $element) {
            if (in_array($name, $exclude)) {
                continue;
            }
            $data[$name] = $userSettings ? $userSettings->get($name) : null;
            if (empty($element['options']['element_group'])) {
                $element['options']['element_group'] = 'profile';
            } elseif (!isset($elementGroups[$element['options']['element_group']])) {
                // The key is checked in order to keep default group labels.
                $elementGroups[$element['options']['element_group']] = $element['options']['element_group'];
            }
            $formFieldset
                ->add($element);
            if ($userSettings) {
                $formFieldset
                    ->get($name)->setValue($data[$name]);
            }
        }

        $userSettingsFieldset = $form->get('user-settings');
        $userSettingsFieldset->setOption('element_groups', array_merge($userSettingsFieldset->getOption('element_groups') ?: [], $elementGroups));

        // Fix to manage empty values for selects and multicheckboxes.
        // @see \Omeka\Controller\SiteAdmin\IndexController::themeSettingsAction()
        $inputFilter = $form->getInputFilter()->get('user-settings');
        foreach ($formFieldset->getElements() as $element) {
            if ($element instanceof \Laminas\Form\Element\MultiCheckbox
                || $element instanceof \Laminas\Form\Element\Tel
                || ($element instanceof \Laminas\Form\Element\Select
                    && $element->getOption('empty_option') !== null)
            ) {
                $name = $element->getName();
                if (!in_array($name, $exclude)
                    && !$element->getAttribute('required')
                ) {
                    $inputFilter->add([
                        'name' => $name,
                        'allow_empty' => true,
                        'required' => false,
                    ]);
                }
            }
        }

        return $form;
    }

    public function filterResourceJsonUser(Event $event): void
    {
        $user = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');

        $services = $this->getServiceLocator();

        $settings = $services->get('Omeka\Settings');
        $elements = $settings->get('userprofile_elements', '');
        if (!$elements) {
            return;
        }

        $fields = $settings->get('userprofile_fields', []);
        if (!$fields) {
            return;
        }

        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());

        foreach (array_keys($fields) as $field) {
            $value = $userSettings->get($field);
            $jsonLd['o:setting'][$field] = $value;
        }

        $event->setParam('jsonLd', $jsonLd);
    }

    /**
     * Unlike update, create cannot manage appended fields in views currently.
     *
     * @param Event $event
     */
    public function apiCreatePreUser(Event $event): void
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if ($status->isApiRequest()) {
            return;
        }

        /** @var \Laminas\Http\PhpEnvironment\Request $request */
        $request = $services->get('Request');
        $post = $request->getPost();
        $userSettings = $post->offsetGet('user-settings') ?: [];
        $post->offsetSet('o:setting', $userSettings);
        $request->setPost($post);

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $request->setContent($post->toArray());

        // TODO Check request from admin and guest form.
        // $this->checkApiRequest($request);
    }

    public function apiHydratePreUser(Event $event): void
    {
        $services = $this->getServiceLocator();

        // Only for the rest api manager.
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            return;
        }

        $this->checkApiRequest($event);
    }

    /**
     * Check an api request before hydration.
     */
    protected function checkApiRequest(Event $event): void
    {
        // TODO Manage "required" during create and update.

        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings) {
            return;
        }

        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $errorStore = $event->getParam('errorStore');
        $user = $event->getParam('entity');
        $fieldset = $this->userSettingsFieldset($user ? $user->getId() : null);

        if (!is_array($requestUserSettings)) {
            $errorStore->addError('o:setting', new PsrMessage(
                'The key “o:setting” should be an array of user settings.' // @translate
            ));
            return;
        }

        foreach ($requestUserSettings as $key => $value) {
            if (!$fieldset->has($key)) {
                continue;
            }

            // TODO Use the validator of the element.
            /** @var \Laminas\Form\Element $element */
            $element = $fieldset->get($key);
            $isMultipleValues = is_array($value);

            if ($element->getAttribute('required')
                && (($isMultipleValues && !count($value)) || (!$isMultipleValues && !strlen($value)))
            ) {
                $errorStore->addError('o:setting', new PsrMessage(
                    'A value is required for “{key}”.', // @translate
                    ['key' => $key]
                ));
                continue;
            }

            // Currently, only select and multicheckbox are checked.
            if (method_exists($element, 'getValueOptions')) {
                $valueOptions = $element->getValueOptions();
                // Note: value options can be an array of arrays with keys value
                // and label, iin particular when the config uses key with
                // forbidden letters.
                if (is_array(reset($valueOptions))) {
                    $result = [];
                    foreach ($valueOptions as $array) {
                        $result[$array['label']] = $array['value'];
                    }
                    $valueOptions = $result;
                }

                if ($isMultipleValues) {
                    $values = array_intersect_key($valueOptions, array_flip($value));
                    if (method_exists($element, 'isMultiple') && !$element->isMultiple()) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'Only one value is allowed for “{key}”.', // @translate
                            ['key' => $key]
                        ));
                    } elseif (count($value) !== count($values)) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'One or more values (“{values}”) are not allowed for “{key}”.', // @translate
                            ['values' => implode('”, “', array_diff($value, array_keys($valueOptions))), 'key' => $key]
                        ));
                    } elseif (!count($values) && $element->getAttribute('required')) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'A value is required for “{key}”.', // @translate
                            ['key' => $key]
                        ));
                    }
                } else {
                    if (strlen($value) && !isset($valueOptions[$value])) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'The value “{value}” is not allowed for “{key}”.', // @translate
                            ['value' => $value, 'key' => $key]
                        ));
                    }
                }
            } else {
                if (method_exists($element, 'isMultiple') && $element->isMultiple()
                    || $element instanceof \Laminas\Form\Element\MultiCheckbox
                ) {
                    $errorStore->addError('o:setting', new PsrMessage(
                        'An array of values is required for “{key}”.', // @translate
                        ['key' => $key]
                    ));
                } elseif (!isset($valueOptions[$value])) {
                    $errorStore->addError('o:setting', new PsrMessage(
                        'The value “{value}” is not allowed for “{key}”.', // @translate
                        ['value' => $value, 'key' => $key]
                    ));
                }
            }
            // TODO Add more check or use element validator.
        }
    }

    public function apiCreatePostUser(Event $event): void
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            /** @var \Laminas\Http\PhpEnvironment\Request $request */
            $request = $services->get('Request');
            $post = $request->getPost();
            $userSettings = $post->offsetGet('user-settings') ?: [];
            $post->offsetSet('o:setting', $userSettings);
            $request->setPost($post);

            /** @var \Omeka\Api\Request $request */
            $request = $event->getParam('request');
            $request->setContent($post->toArray());
        }
        $this->apiCreateOrUpdatePostUser($event);
    }

    /**
     * Unlike create, update is managed via the settings because it displays the
     * form a new time. So a specific check is done for update.
     *
     * @param Event $event
     */
    public function apiUpdatePostUser(Event $event): void
    {
        // Only for the rest api manager: in public or admin, the view is
        // reloaded and managed during the creation of the form.

        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            return;
        }
        $this->apiCreateOrUpdatePostUser($event);
    }

    protected function apiCreateOrUpdatePostUser(Event $event): void
    {
        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings || !is_array($requestUserSettings)) {
            return;
        }

        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $user = $event->getParam('response')->getContent();
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->getId());
        $fieldset = $this->userSettingsFieldset($user->getId());

        $exclude = $this->excludedFields('edit');

        foreach ($requestUserSettings as $key => $value) {
            // Silently skip if not exist for security and clean process.
            if (!$fieldset->has($key)) {
                continue;
            }

            // Skip elements for security: a user cannot edit an excluded field.
            if (in_array($key, $exclude)) {
                continue;
            }

            // TODO Use the validator of the element.
            /** @var \Laminas\Form\Element $element */
            $element = $fieldset->get($key);
            $isMultipleValues = is_array($value);

            // Some cleaning, required because some fields are not checked
            // during creation via form.
            if (method_exists($element, 'getValueOptions') && $isMultipleValues) {
                $value = array_keys(array_intersect_key($element->getValueOptions(), array_flip($value)));
            } elseif ($element instanceof \Laminas\Form\Element\Checkbox) {
                $value = (bool) $value;
            } elseif ($element instanceof \Laminas\Form\Element\Number) {
                $value = (int) $value;
            }

            $userSettings->set($key, $value);
        }
    }

    /**
     * Get the fieldset "user-setting" of the user form.
     *
     * @param int $userId
     * @return \Laminas\Form\Fieldset
     */
    protected function userSettingsFieldset($userId = null)
    {
        $services = $this->getServiceLocator();
        // $isSiteRequest = $services->get('Omeka\Status')->isSiteRequest();
        $form = $services->get('FormElementManager')
            ->get(\Omeka\Form\UserForm::class, [
                // 'is_public' => $isSiteRequest,
                'user_id' => $userId,
                'include_role' => true,
                'include_admin_roles' => true,
                'include_is_active' => true,
                'current_password' => false,
                'include_password' => false,
                'include_key' => false,
                'include_site_role_remove' => false,
                'include_site_role_add' => false,
            ]);
        $form->init();
        return $form
            ->get('user-settings');
    }

    public function viewUserDetails(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $this->viewUserData($view, $user, 'common/admin/user-profile');
    }

    public function viewUserShowAfter(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $this->viewUserData($view, $user, 'common/admin/user-profile-list');
    }

    protected function viewUserData(PhpRenderer $view, UserRepresentation $user, $partial): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $elements = $settings->get('userprofile_elements', '');
        if (!$elements) {
            return;
        }

        $fields = $settings->get('userprofile_fields', []) ?: [];
        $exclude = $this->excludedFields('show');
        $fields = array_diff_key($fields, array_flip($exclude));
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());
        echo $view->partial(
            $partial,
            [
                'user' => $user,
                'userSettings' => $userSettings,
                'fields' => $fields,
            ]
        );
    }

    protected function readConfigElements(): array
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $elements = $settings->get('userprofile_elements');
        if (!$elements) {
            return ['elements' => []];
        }

        try {
            $reader = new IniReader;
            $config = $reader->fromString($elements);
            if ($config && count($config['elements'])) {
                return $config;
            }
        } catch (\Laminas\Config\Exception\RuntimeException $e) {
        }

        try {
            $reader = new XmlReader;
            $config = $reader->fromString($elements);
            if ($config && count($config)) {
                return ['elements' => $config];
            }
        } catch (\Laminas\Config\Exception\RuntimeException $e) {
        }

        try {
            $reader = new JsonReader;
            $config = $reader->fromString($elements);
            if ($config && count($config['elements'])) {
                return $config;
            }
        } catch (\Laminas\Config\Exception\RuntimeException $e) {
        }

        return ['elements' => []];
    }

    protected function excludedFields(string $part): array
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        $isSiteRequest = $status->isSiteRequest();
        $isAdminRequest = $status->isAdminRequest();
        if ($isSiteRequest || $isAdminRequest) {
            $settings = $services->get('Omeka\Settings');
            $exclude = $settings->get('userprofile_exclude', ['admin' => [$part => []], 'public' => [$part => []]]);
            $exclude = $exclude[$isSiteRequest ? 'public' : 'admin'][$part] ?? [];
            if (!is_array($exclude)) {
                $exclude = [];
            }
        } else {
            $exclude = [];
        }
        return $exclude;
    }
}
