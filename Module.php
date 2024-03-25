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
use Laminas\Form\Fieldset;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\AbstractModule;
use UserProfile\Form\UserSettingsFieldset;

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

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.54')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.54'
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

        // Store data about the config early and once.
        if (!$this->updateListFields()) {
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $messenger = $this->getServiceLocator()->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'You should fix the config of the module.' // @translate
            );
            $messenger->addError($message);
            return false;
        }

        return true;
    }

    /**
     * Update list of fields from the config and store it in settings.
     */
    protected function updateListFields(): bool
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // The simplest way to get data is to create a temp fieldset.
        // The fieldset may not be available during install/upgrade.
        // Anyway, this is an empty fieldset filled with config.
        /** @var \UserProfile\Form\UserSettingsFieldset $fieldset */
        $formManager = $services->get('FormElementManager');
        if ($formManager->has(UserSettingsFieldset::class)) {
            $fieldset = $formManager->get(UserSettingsFieldset::class);
        } else {
            $fieldset = new Fieldset('user-profile');
        }
        $fields = [];
        $exclude = [
            'admin' => ['show' => [], 'edit' => []],
            'public' => ['show' => [], 'edit' => []],
        ];
        $profileElements = $this->readConfigElements();
        $elementErrors = [];
        foreach ($profileElements['elements'] as $key => $elementConfig) {
            $name = $elementConfig['name'] ?? null;
            if (!$name) {
                $elementErrors[] = $key;
                continue;
            }
            if (isset($fields[$name])) {
                $elementErrors[] = $name;
                continue;
            }
            try {
                $fieldset->add($elementConfig);
            } catch (\Exception $e) {
                $elementErrors[] = $name;
                continue;
            }

            /** @var \Laminas\Form\Element $element */
            $element = $fieldset->get($name);
            $label = $element->getLabel();
            $required = (bool) $element->getAttribute('required');
            $isMultiple = (method_exists($element, 'isMultiple') && $element->isMultiple())
                || $element instanceof \Laminas\Form\Element\MultiCheckbox;
            $isInt = $element instanceof \Laminas\Form\Element\Number;
            $isBool = $element instanceof \Laminas\Form\Element\Checkbox
                && !$element instanceof \Laminas\Form\Element\MultiCheckbox
                && $element->getCheckedValue() === '1'
                && $element->getUncheckedValue() === '0';
            $allowedValues = null;
            if (method_exists($element, 'getValueOptions')) {
                $valueOptions = $element->getValueOptions();
                // Note: value options can be an array of arrays with keys value
                // and label, in particular when the config uses key with
                // forbidden letters.
                if (is_array(reset($valueOptions))) {
                    $result = [];
                    foreach ($valueOptions as $array) {
                        $result[$array['value']] = true;
                    }
                    $valueOptions = $result;
                }
                // Don't add empty_option, that is a label.
                $allowedValues = array_keys($valueOptions);
            }
            $field = [
                'name' => $name,
                'label' => $label,
                'required' => $required,
                'is_multiple' => $isMultiple,
                'is_int' => $isInt,
                'is_bool' => $isBool,
                'allowed_values' => $allowedValues,
            ];
            $fields[$name] = $field;

            if ($element->getOption('exclude_admin_show')) {
                $exclude['admin']['show'] = $name;
            }
            if ($element->getOption('exclude_admin_edit')) {
                $exclude['admin']['edit'] = $name;
            }
            if ($element->getOption('exclude_public_show')) {
                $exclude['public']['show'] = $name;
            }
            if ($element->getOption('exclude_public_edit')) {
                $exclude['public']['edit'] = $name;
            }
        }

        if ($elementErrors) {
            $logger = $services->get('Omeka\Logger');
            $plugins = $services->get('ControllerPluginManager');
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $messenger = $plugins->get('messenger');
            $message = new PsrMessage(
                'These elements of user profile are invalid: {list}.', // @translate
                ['list' => implode(', ', $elementErrors)]
            );
            $messenger->addError($message);
            $logger->err($message->getMessage(), $message->getContext());
            return false;
        }

        $settings->set('userprofile_fields', $fields);
        $settings->set('userprofile_exclude', $exclude);

        return true;
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
     * This check is not for api, that is checked via hydrate.
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

        $this->checkRequestValues($request);
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

        $this->checkRequestValues($event);
    }

    /**
     * Check a request before hydration.
     *
     * The check uses the form when possible (with a user).
     * Manage partial update, so required values are not checked if missing.
     */
    protected function checkRequestValues(Event $event): void
    {
        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings) {
            return;
        }

        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $errorStore = $event->getParam('errorStore');

        if (!is_array($requestUserSettings)) {
            $errorStore->addError('o:setting', new PsrMessage(
                'The key “o:setting” should be an array of user settings.' // @translate
            ));
            return;
        }

        // TODO Use the validator of the element, but the user id should be set, that is not possible during creation of a user.
        // The user may be currently being created.
        // Don't check current authenticated user, but the user being created/updated.
        $user = $event->getParam('entity');
        $userId = $user->getId();
        $fieldset = $userId ? $this->userSettingsFieldset($userId) : null;

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $fields = $settings->get('userprofile_fields', []);

        foreach ($requestUserSettings as $key => $value) {
            if ($fieldset && !$fieldset->has($key)) {
                continue;
            }
            if (!$fieldset && !isset($fields[$key])) {
                continue;
            }

            $isMultipleValues = is_array($value);

            // Same checks than elements, but without them.
            if (!$fieldset) {
                $field = $fields[$key];

                if ($field['required']
                    && (
                        ($isMultipleValues && !count($value))
                        || (!$isMultipleValues && !strlen((string) $value))
                    )
                ) {
                    $errorStore->addError('o:setting', new PsrMessage(
                        'A value is required for “{key}”.', // @translate
                        ['key' => $key]
                    ));
                    continue;
                }

                $allowedValues = $field['allowed_values'];
                if (is_array($allowedValues)) {
                    if ($isMultipleValues) {
                        $values = $allowedValues ? array_intersect($allowedValues, $value) : $value;
                        if (!$field['is_multiple']) {
                            $errorStore->addError('o:setting', new PsrMessage(
                                'Only one value is allowed for “{key}”.', // @translate
                                ['key' => $key]
                            ));
                        } elseif (count($value) !== count($values)) {
                            $errorStore->addError('o:setting', new PsrMessage(
                                'One or more values (“{values}”) are not allowed for “{key}”.', // @translate
                                ['values' => implode('”, “', array_diff($value, $allowedValues)), 'key' => $key]
                            ));
                        } elseif (!count($values) && $field['required']) {
                            $errorStore->addError('o:setting', new PsrMessage(
                                'A value is required for “{key}”.', // @translate
                                ['key' => $key]
                            ));
                        }
                    } else {
                        if (strlen((string) $value) && !in_array($value, $allowedValues)) {
                            $errorStore->addError('o:setting', new PsrMessage(
                                'The value “{value}” is not allowed for “{key}”.', // @translate
                                ['value' => $value, 'key' => $key]
                            ));
                        }
                    }
                } else {
                    if ($field['is_multiple'] && !$isMultipleValues) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'An array of values is required for “{key}”.', // @translate
                            ['key' => $key]
                        ));
                    }
                }
                continue;
            }

            /** @var \Laminas\Form\Element $element */
            $element = $fieldset->get($key);

            if ($element->getAttribute('required')
                && (
                    ($isMultipleValues && !count($value))
                    || (!$isMultipleValues && !strlen($value))
                )
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
                // and label, in particular when the config uses key with
                // forbidden letters.
                if (is_array(reset($valueOptions))) {
                    $result = [];
                    foreach ($valueOptions as $array) {
                        $result[$array['value']] = true;
                    }
                    $valueOptions = $result;
                }
                $allowedValues = array_keys($valueOptions);

                if ($isMultipleValues) {
                    $values = $allowedValues ? array_intersect($allowedValues, $value) : $value;
                    if (method_exists($element, 'isMultiple') && !$element->isMultiple()) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'Only one value is allowed for “{key}”.', // @translate
                            ['key' => $key]
                        ));
                    } elseif (count($value) !== count($values)) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'One or more values (“{values}”) are not allowed for “{key}”.', // @translate
                            ['values' => implode('”, “', array_diff($value, $allowedValues)), 'key' => $key]
                        ));
                    } elseif (!count($values) && $element->getAttribute('required')) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'A value is required for “{key}”.', // @translate
                            ['key' => $key]
                        ));
                    }
                } else {
                    if (strlen($value) && !in_array($value, $allowedValues)) {
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
                } elseif (!in_array($value, $allowedValues)) {
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
        // During post, the user id is always set.

        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings || !is_array($requestUserSettings)) {
            return;
        }

        /**
         * @var \Omeka\Stdlib\ErrorStore $errorStore
         * @var \Omeka\Entity\User $user
         * @var \Omeka\Settings\UserSettings $userSettings
         */
        $user = $event->getParam('response')->getContent();
        $userId = $user->getId();
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($userId);
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

            // Keep $userId here to avoid issues.
            $userSettings->set($key, $value, $userId);
        }
    }

    /**
     * Get the fieldset "user-setting" of the user form.
     */
    protected function userSettingsFieldset(?int $userId = null): \Laminas\Form\Fieldset
    {
        $services = $this->getServiceLocator();
        // $isSiteRequest = $services->get('Omeka\Status')->isSiteRequest();
        /** @var \UserProfile\Form\UserSettingsFieldset $form */
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
                'fields' => array_column($fields, 'label', 'name'),
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
