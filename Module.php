<?php declare(strict_types=1);

namespace UserProfile;

if (!class_exists('Common\TraitModule', false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\Config\Reader\Ini as IniReader;
use Laminas\Config\Reader\Json as JsonReader;
use Laminas\Config\Reader\Xml as XmlReader;
use Laminas\Config\Reader\Yaml as YamlReader;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\AbstractModule;
use UserProfile\Form\ConfigForm;
use UserProfile\Form\UserSettingsFieldset;

/**
 * User Profile.
 *
 * @copyright Daniel Berthereau, 2019-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    const CONFIG_FORMATS = ['fields', 'yaml', 'ini', 'json', 'xml'];

    /**
     * Types of the simple syntax of the fields and their equivalent Laminas
     * types, used to convert and to compare the configs.
     */
    const FIELD_TYPES = [
        'checkbox' => ['Checkbox', \Common\Form\Element\OptionalCheckbox::class],
        'date' => ['Date', \Common\Form\Element\OptionalDate::class],
        'datetime' => ['DateTimeLocal', \Common\Form\Element\OptionalDateTimeLocal::class],
        'email' => ['Email', \Common\Form\Element\OptionalEmail::class],
        'hidden' => ['Hidden'],
        'multicheckbox' => ['MultiCheckbox', \Common\Form\Element\OptionalMultiCheckbox::class],
        'number' => ['Number', \Common\Form\Element\OptionalNumber::class],
        'password' => ['Password'],
        'radio' => ['Radio', \Common\Form\Element\OptionalRadio::class],
        'select' => ['Select', \Common\Form\Element\OptionalSelect::class],
        'tel' => ['Tel'],
        'text' => ['Text'],
        'textarea' => ['Textarea'],
        'time' => ['Time', \Common\Form\Element\OptionalTime::class],
        'url' => ['Url', \Common\Form\Element\OptionalUrl::class],
    ];


    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.82')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.82'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        $this->upgradeFromGuestProfile();
    }

    /**
     * Convert the fields and the values of the old module Guest Profile.
     *
     * The module Guest Profile stored two fixed fields, a status and another
     * status, in the user settings. They are added to the config, and the
     * values are copied to new keys. The old values are kept, since they are
     * not removed when the old module is uninstalled. The process can be run
     * again.
     */
    public function upgradeFromGuestProfile(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');

        $oldKeys = [
            'guestprofile_field_1' => 'userprofile_status',
            'guestprofile_field_2' => 'userprofile_status_other',
        ];

        $total = (int) $connection
            ->executeQuery('SELECT COUNT(DISTINCT `user_id`) FROM `user_setting` WHERE `id` IN (?)', [array_keys($oldKeys)], [\Doctrine\DBAL\Connection::PARAM_STR_ARRAY])
            ->fetchOne();
        if (!$total) {
            return;
        }

        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $settings = $services->get('Omeka\Settings');

        $iniElements = <<<'INI'
            elements.userprofile_status.name                            = "userprofile_status"
            elements.userprofile_status.type                            = "Select"
            elements.userprofile_status.options.element_group           = "profile"
            elements.userprofile_status.options.label                   = "Statut"
            elements.userprofile_status.options.empty_option            = "Choisissez votre statut…"
            elements.userprofile_status.options.value_options[particulier] = "Particulier"
            elements.userprofile_status.options.value_options[auteur]   = "Auteur"
            elements.userprofile_status.options.value_options[chercheur] = "Chercheur"
            elements.userprofile_status.options.value_options[étudiant] = "Étudiant"
            elements.userprofile_status.options.value_options[éditeur]  = "Éditeur"
            elements.userprofile_status.options.value_options[revue]    = "Revue"
            elements.userprofile_status.options.value_options[magazine] = "Magazine"
            elements.userprofile_status.options.value_options[bibliothèque] = "Bibliothèque"
            elements.userprofile_status.options.value_options[institut] = "Institut"
            elements.userprofile_status.options.value_options[autre]    = "Autre"
            elements.userprofile_status.attributes.id                   = "userprofile_status"

            elements.userprofile_status_other.name                      = "userprofile_status_other"
            elements.userprofile_status_other.type                      = "Textarea"
            elements.userprofile_status_other.options.element_group     = "profile"
            elements.userprofile_status_other.options.label             = "Autre statut…"
            elements.userprofile_status_other.attributes.id             = "userprofile_status_other"
            elements.userprofile_status_other.attributes.placeholder    = "Indiquez votre statut…"
            INI;

        // Add the fields to the config when they are not set, only when the
        // config is empty or in format ini, the format of the new fields.
        $elements = trim((string) $settings->get('userprofile_elements'));
        $hasFields = strpos($elements, 'userprofile_status') !== false;
        $isIni = $elements === '' || $this->configFormat($elements) === 'ini';
        if (!$hasFields && $isIni) {
            $settings->set('userprofile_elements', trim($elements . "\n\n" . $iniElements));
            if (!$this->updateListFields()) {
                $settings->set('userprofile_elements', $elements);
                $this->updateListFields();
                $isIni = false;
            }
        }
        if (!$hasFields && !$isIni) {
            $message = new PsrMessage(
                'The fields of the module Guest Profile cannot be added automatically to the config, that is not in format ini. Add the fields "userprofile_status" and "userprofile_status_other" manually.' // @translate
            );
            $messenger->addWarning($message);
        }

        // Copy the values, without replacing a value already set.
        foreach ($oldKeys as $oldKey => $newKey) {
            $sql = <<<'SQL'
                INSERT INTO `user_setting` (`id`, `user_id`, `value`)
                SELECT :new_key, `old`.`user_id`, `old`.`value`
                FROM `user_setting` AS `old`
                WHERE `old`.`id` = :old_key
                    AND NOT EXISTS (
                        SELECT 1 FROM `user_setting` AS `new`
                        WHERE `new`.`id` = :new_key AND `new`.`user_id` = `old`.`user_id`
                    )
                SQL;
            $connection->executeStatement($sql, ['old_key' => $oldKey, 'new_key' => $newKey]);
        }

        $message = new PsrMessage(
            'The profiles of {total} users were converted from the module Guest Profile. Check the config of the module, then uninstall the module Guest Profile. The old values are kept in the user settings "guestprofile_field_1" and "guestprofile_field_2".', // @translate
            ['total' => $total]
        );
        $messenger->addSuccess($message);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Append the settings to the current user.
        // Rights are the same than main one, so it is available only for the
        // current user or admin with credentials.
        $sharedEventManager->attach(
            \Omeka\Api\Representation\UserRepresentation::class,
            'rep.resource.json',
            [$this, 'filterResourceJsonUser']
        );

        // Manage user settings via rest api (and the new user via ui).
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

    public function getConfigForm(PhpRenderer $renderer)
    {
        // The list of fields in the simple syntax can be edited as a form.
        if ($renderer->getHelperPluginManager()->has('fieldsTextareaAssets')) {
            $renderer->fieldsTextareaAssets();
        }
        return $this->getConfigFormAuto($renderer);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        if (!$this->convertPostedConfig($controller)) {
            return false;
        }

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
     * Convert the posted config into the format requested in the config form.
     *
     * When the conversion is not possible, nothing is saved and the form is
     * displayed again with the submitted values.
     */
    protected function convertPostedConfig(AbstractController $controller): bool
    {
        $post = $controller->getRequest()->getPost();
        $format = (string) $post->get('userprofile_convert_format', '');
        $config = trim((string) $post->get('userprofile_elements', ''));
        if (!in_array($format, self::CONFIG_FORMATS, true)
            || $config === ''
            || $this->configFormat($config) === $format
        ) {
            return true;
        }

        try {
            $post->set('userprofile_elements', $this->convertConfigFormat($config, $format));
            return true;
        } catch (\Throwable $e) {
            $controller->messenger()->addError(new PsrMessage(
                'The config cannot be converted to the format {format} and it was not saved: {message}', // @translate
                ['format' => $format, 'message' => $e->getMessage()]
            ));
        }

        $form = $this->getServiceLocator()->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $form->setData($post);
        $this->invalidConfigForm = $form;

        return false;
    }

    /**
     * Get the formats to which the stored config cannot be converted.
     *
     * @return string[]
     */
    public function impossibleConfigFormats(): array
    {
        $config = trim((string) $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_elements'));
        if ($config === '') {
            return [];
        }

        $current = $this->configFormat($config);
        $result = [];
        foreach (self::CONFIG_FORMATS as $format) {
            if ($format === $current) {
                continue;
            }
            try {
                $this->convertConfigFormat($config, $format);
            } catch (\Throwable $e) {
                $result[] = $format;
            }
        }
        return $result;
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
        if (!$profileElements) {
            return false;
        }

        if (is_string($profileElements)) {
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $logger = $services->get('Omeka\Logger');
            $plugins = $services->get('ControllerPluginManager');
            $messenger = $plugins->get('messenger');
            $message = new PsrMessage(
                'The settings cannot be parsed: {msg}.', // @translate
                ['msg' => $profileElements]
            );
            $messenger->addError($message);
            $logger->err($message->getMessage(), $message->getContext());
            return false;
        }

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
            } catch (\Throwable $e) {
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
            // Currently, only select and multicheckbox are checked.
            $allowedValues = $this->elementAllowedValues($element);
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

            foreach (['show', 'edit'] as $part) {
                $allowed = $this->elementRight($element, $part);
                if ($allowed === null) {
                    $elementErrors[] = $name;
                    continue 2;
                }
                foreach (['admin', 'public'] as $context) {
                    if (!in_array($context, $allowed)) {
                        $exclude[$context][$part][] = $name;
                    }
                }
            }
        }

        if ($elementErrors) {
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $logger = $services->get('Omeka\Logger');
            $plugins = $services->get('ControllerPluginManager');
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

        if (is_string($elements)) {
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $services = $this->getServiceLocator();
            $logger = $services->get('Omeka\Logger');
            $plugins = $services->get('ControllerPluginManager');
            $messenger = $plugins->get('messenger');
            $message = new PsrMessage(
                'The settings cannot be parsed: {msg}.', // @translate
                ['msg' => $elements]
            );
            $messenger->addError($message);
            $logger->err($message->getMessage(), $message->getContext());
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
            $data[$name] = $userSettings ? $userSettings->get($name, null, $userId) : null;
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

        /** @var \Omeka\Settings\UserSettings $userSettings */
        $userSettings = $services->get('Omeka\Settings\User');

        // The rights of display apply to the api too.
        $exclude = $this->excludedFields('show');

        // Don't set the target id early to allow to use another user.
        foreach (array_diff(array_keys($fields), $exclude) as $field) {
            $value = $userSettings->get($field, null, $user->id());
            $jsonLd['o:setting'][$field] = $value;
        }

        $event->setParam('jsonLd', $jsonLd);
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
        $userId = $user ? $user->getId() : null;
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
                    || (!$isMultipleValues && (string) $value === '')
                )
            ) {
                $errorStore->addError('o:setting', new PsrMessage(
                    'A value is required for “{key}”.', // @translate
                    ['key' => $key]
                ));
                continue;
            }

            // Currently, only select and multicheckbox are checked.
            $allowedValues = $this->elementAllowedValues($element);
            if ($allowedValues !== null) {
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
                    if ((string) $value !== '' && !in_array($value, $allowedValues)) {
                        $errorStore->addError('o:setting', new PsrMessage(
                            'The value “{value}” is not allowed for “{key}”.', // @translate
                            ['value' => $value, 'key' => $key]
                        ));
                    }
                }
            } elseif ((method_exists($element, 'isMultiple') && $element->isMultiple()
                    || $element instanceof \Laminas\Form\Element\MultiCheckbox)
                && !$isMultipleValues
            ) {
                $errorStore->addError('o:setting', new PsrMessage(
                    'An array of values is required for “{key}”.', // @translate
                    ['key' => $key]
                ));
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
         * @var \Omeka\Entity\User $user
         * @var \Omeka\Settings\UserSettings $userSettings
         */
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');

        $user = $event->getParam('response')->getContent();
        $userId = $user->getId();

        $fieldset = $this->userSettingsFieldset($user->getId());
        $exclude = $this->excludedFields('edit');

        foreach ($requestUserSettings as $key => $value) {
            // Silently skip if not exist for security and clean process.
            if (!$fieldset->has($key)) {
                continue;
            }

            // Skip silently the fields that the current user cannot edit.
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
                $allowedValues = $this->elementAllowedValues($element);
                $value = array_values(array_intersect($allowedValues, $value));
            } elseif ($element instanceof \Laminas\Form\Element\Checkbox) {
                $value = (bool) $value;
            } elseif ($element instanceof \Laminas\Form\Element\Number) {
                $value = (int) $value;
            }

            // Set userId as target here to avoid issues.
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
        $this->viewUserData($view, $user, 'common/user-settings-profile');
    }

    public function viewUserShowAfter(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $this->viewUserData($view, $user, 'common/user-settings-list-profile');
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
        echo $view->partial(
            $partial,
            [
                'user' => $user,
                'userSettings' => $userSettings,
                'fields' => array_column($fields, 'label', 'name'),
            ]
        );
    }

    /**
     * Get the contexts allowed to display or to edit an element.
     *
     * The option "show" or "edit" is "all" (default), "admin", "user" or
     * "none". The old boolean options "exclude_admin_show", etc. are still
     * read when the new option is not set.
     *
     * @return array|null The list of allowed contexts ("admin" and "public"),
     * or null when the option is invalid.
     */
    protected function elementRight(Element $element, string $part): ?array
    {
        $right = $element->getOption($part);
        if ($right === null || $right === '') {
            return array_values(array_filter([
                $element->getOption('exclude_admin_' . $part) ? null : 'admin',
                $element->getOption('exclude_public_' . $part) ? null : 'public',
            ]));
        }
        $rights = [
            'all' => ['admin', 'public'],
            'admin' => ['admin'],
            'user' => ['public'],
            'none' => [],
        ];
        return is_string($right) ? $rights[strtolower($right)] ?? null : null;
    }

    /**
     * Convert the old options of rights of the config into the new ones.
     *
     * The four boolean options "exclude_admin_show", "exclude_admin_edit",
     * "exclude_public_show" and "exclude_public_edit" are replaced by the
     * options "show" and "edit". Only the lines or nodes of these options are
     * modified for the formats ini and xml, so the comments are kept. The json
     * is formatted again. The yaml is never converted, since it was added
     * after the new options.
     *
     * @return string|null The converted config, or null when unchanged.
     */
    public function convertConfigRights(string $config): ?string
    {
        if (strpos($config, 'exclude_') === false) {
            return null;
        }

        $rightOf = function (array $excludes): string {
            $isAdmin = empty($excludes['admin']);
            $isUser = empty($excludes['public']);
            return $isAdmin && $isUser ? 'all' : ($isAdmin ? 'admin' : ($isUser ? 'user' : 'none'));
        };
        $isTrue = fn ($value): bool => in_array(strtolower(trim((string) $value, " \t\"'")), ['1', 'true', 'yes', 'on'], true);

        $format = $this->configFormat($config);

        if ($format === 'ini') {
            $pattern = '~^(\s*elements\.([^.=\s]+)\.options\.)exclude_(admin|public)_(show|edit)\s*=\s*(.*?)\s*$~m';
            if (!preg_match_all($pattern, $config, $matches, PREG_SET_ORDER)) {
                return null;
            }
            $excludes = [];
            foreach ($matches as $match) {
                $excludes[$match[2]][$match[4]][$match[3]] = $isTrue($match[5]);
                $excludes[$match[2]][$match[4]]['prefix'] = $match[1];
            }
            $done = [];
            $result = preg_replace_callback($pattern, function ($match) use (&$done, $excludes, $rightOf) {
                $key = $match[2] . '/' . $match[4];
                if (isset($done[$key])) {
                    return "\0";
                }
                $done[$key] = true;
                $right = $rightOf($excludes[$match[2]][$match[4]]);
                return $right === 'all' ? "\0" : $match[1] . $match[4] . ' = "' . $right . '"';
            }, $config);
            // Remove the replaced lines.
            return preg_replace("~^\0(?:\r?\n)?~m", '', $result);
        }

        if ($format === 'json') {
            $data = json_decode($config, true);
            if (!is_array($data) || empty($data['elements']) || !is_array($data['elements'])) {
                return null;
            }
            foreach ($data['elements'] as &$element) {
                if (empty($element['options']) || !is_array($element['options'])) {
                    continue;
                }
                foreach (['show', 'edit'] as $part) {
                    $excludes = [];
                    foreach (['admin', 'public'] as $context) {
                        $key = "exclude_{$context}_{$part}";
                        if (array_key_exists($key, $element['options'])) {
                            $excludes[$context] = $isTrue(is_bool($element['options'][$key]) ? (int) $element['options'][$key] : $element['options'][$key]);
                            unset($element['options'][$key]);
                        }
                    }
                    if ($excludes && !isset($element['options'][$part]) && ($right = $rightOf($excludes)) !== 'all') {
                        $element['options'][$part] = $right;
                    }
                }
            }
            unset($element);
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($format === 'xml') {
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = true;
            if (!@$dom->loadXML($config)) {
                return null;
            }
            $xpath = new \DOMXPath($dom);
            $isChanged = false;
            foreach ($xpath->query('/*/*/options') as $options) {
                foreach (['show', 'edit'] as $part) {
                    $excludes = [];
                    $first = null;
                    foreach (['admin', 'public'] as $context) {
                        foreach ($xpath->query("exclude_{$context}_{$part}", $options) as $node) {
                            $excludes[$context] = $isTrue($node->textContent);
                            $first ??= $node;
                            if ($node !== $first) {
                                $node->parentNode->removeChild($node);
                            }
                        }
                    }
                    if (!$first) {
                        continue;
                    }
                    $isChanged = true;
                    $right = $rightOf($excludes);
                    if ($right === 'all' || $xpath->query($part, $options)->length) {
                        $first->parentNode->removeChild($first);
                    } else {
                        $first->parentNode->replaceChild($dom->createElement($part, $right), $first);
                    }
                }
            }
            if (!$isChanged) {
                return null;
            }
            // Remove the empty lines left by the removed nodes.
            $result = strpos(ltrim($config), '<?xml') === 0
                ? $dom->saveXML()
                : $dom->saveXML($dom->documentElement);
            return trim(preg_replace('~\n[ \t]*(?=\n)~', '', $result));
        }

        return null;
    }

    /**
     * Convert a config into another format.
     *
     * The result is parsed and compared to the source, so a config is never
     * converted with a loss, for example a key that a format does not allow.
     * The comments are not kept.
     *
     * @throws \RuntimeException
     */
    public function convertConfigFormat(string $config, string $format): string
    {
        $source = $this->parseConfig($config);
        if (is_string($source)) {
            throw new \RuntimeException($source);
        }

        $data = ['elements' => $this->normalizeValueOptions($source['elements'])];

        switch ($format) {
            case 'fields':
                $specs = [];
                foreach ($data['elements'] as $name => $spec) {
                    $type = $this->fieldType($spec['type'] ?? 'Text');
                    if ($type === null) {
                        throw new \RuntimeException((string) new PsrMessage(
                            'The type "{type}" of the field "{name}" is not available in the simple syntax.', // @translate
                            ['type' => $spec['type'], 'name' => $name]
                        ));
                    }
                    $spec['type'] = $this->fieldTypeClass($type);
                    $specs[$name] = $spec;
                }
                $result = $this->fieldsTextarea()->arrayToString($specs);
                break;
            case 'yaml':
                if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                    throw new \RuntimeException('The format yaml requires the library symfony/yaml.'); // @translate
                }
                $result = \Symfony\Component\Yaml\Yaml::dump($data, 10, 2);
                break;
            case 'ini':
                $writer = new \Laminas\Config\Writer\Ini();
                $writer->setRenderWithoutSectionsFlags(true);
                $result = $writer->toString($data);
                break;
            case 'json':
                $result = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                break;
            case 'xml':
                // A value is not always a valid name of a xml node.
                $writer = new \Laminas\Config\Writer\Xml();
                $result = $writer->toString($this->listValueOptions($data['elements']));
                $result = strtr(preg_replace('~^<\?xml[^>]*\?>\s*~', '', $result), ['laminas-config>' => 'elements>']);
                break;
            default:
                throw new \RuntimeException('Unknown format.'); // @translate
        }

        $result = trim($result);
        $parsed = $this->parseConfig($result);
        if (is_string($parsed)) {
            throw new \RuntimeException($parsed);
        }
        $expected = $this->flatConfig($this->comparableConfig($source['elements']));
        $actual = $this->flatConfig($this->comparableConfig($parsed['elements']));
        if ($expected !== $actual) {
            $invalid = array_keys(array_diff_assoc($expected, $actual) ?: array_diff_assoc($actual, $expected));
            // The order is the only difference when there is no invalid key.
            throw new \RuntimeException((string) new PsrMessage(
                'Some keys or values cannot be stored in this format: {list}', // @translate
                ['list' => implode('; ', array_slice($invalid, 0, 5)) ?: 'order']
            ));
        }

        return $result;
    }

    /**
     * Flatten a config to a list of values by path.
     */
    protected function flatConfig(array $data, string $path = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $keyPath = $path === '' ? (string) $key : $path . ' / ' . $key;
            if (is_array($value)) {
                $result += $this->flatConfig($value, $keyPath);
            } else {
                $result[$keyPath] = $value;
            }
        }
        return $result;
    }

    /**
     * Convert the lists of value and label of xml into simple maps.
     */
    protected function normalizeValueOptions(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'value_options') {
                $list = isset($value['value'], $value['label']) ? [$value] : $value;
                $isList = (bool) $list;
                foreach ($list as $option) {
                    $isList = $isList && is_array($option) && isset($option['value'], $option['label']) && count($option) === 2;
                }
                $data[$key] = $isList ? array_column($list, 'label', 'value') : $value;
            } else {
                $data[$key] = $this->normalizeValueOptions($value);
            }
        }
        return $data;
    }

    /**
     * Convert the maps of value options into lists of value and label for xml.
     */
    protected function listValueOptions(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'value_options' && count($value) === count(array_filter($value, 'is_scalar'))) {
                $data[$key] = [];
                foreach ($value as $optionValue => $label) {
                    $data[$key][] = ['value' => (string) $optionValue, 'label' => $label];
                }
            } else {
                $data[$key] = $this->listValueOptions($value);
            }
        }
        return $data;
    }

    /**
     * Prepare a config for comparison: the formats don't keep the scalar types.
     */
    protected function comparableConfig(array $data): array
    {
        $data = $this->normalizeValueOptions($data);
        foreach ($data as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            // The equivalent types and the keys that the simple syntax adds
            // implicitly are the same.
            $type = $this->fieldType((string) ($spec['type'] ?? 'Text'));
            if ($type !== null) {
                $data[$name]['type'] = $type;
            }
            if (isset($spec['options']['label']) && $spec['options']['label'] === '') {
                unset($data[$name]['options']['label']);
            }
            if ($type === 'multicheckbox') {
                unset($data[$name]['attributes']['multiple']);
            }
            if (isset($spec['attributes']['required']) && !$spec['attributes']['required']) {
                unset($data[$name]['attributes']['required']);
            }
            foreach (['options', 'attributes'] as $key) {
                if (isset($data[$name][$key]) && $data[$name][$key] === []) {
                    unset($data[$name][$key]);
                }
            }
        }
        array_walk_recursive($data, function (&$value): void {
            $value = (string) $value;
        });
        // The order of the fields and of the values matters, not the order of
        // the keys of a field.
        foreach ($data as $name => $spec) {
            if (is_array($spec)) {
                $data[$name] = $this->sortKeys($spec);
            }
        }
        return $this->emptyArraysToStrings($data);
    }

    protected function sortKeys(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value) && $key !== 'value_options') {
                $data[$key] = $this->sortKeys($value);
            }
        }
        return $data;
    }

    protected function emptyArraysToStrings(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $value ? $this->emptyArraysToStrings($value) : '';
            }
        }
        return $data;
    }

    /**
     * Get the type of the simple syntax of a Laminas type, or null if none.
     */
    protected function fieldType(string $type): ?string
    {
        $type = strtolower(ltrim($type, '\\'));
        foreach (self::FIELD_TYPES as $fieldType => $types) {
            $equivalents = array_map('strtolower', array_merge($types, ['Laminas\\Form\\Element\\' . $types[0]]));
            if ($type === $fieldType || in_array($type, $equivalents, true)) {
                return $fieldType;
            }
        }
        return null;
    }

    /**
     * Get the class used by the simple syntax for a type.
     */
    protected function fieldTypeClass(string $type): string
    {
        $types = self::FIELD_TYPES[$type];
        return $types[1] ?? 'Laminas\\Form\\Element\\' . $types[0];
    }

    /**
     * Get the parser of the simple syntax of the fields, from module Common.
     */
    protected function fieldsTextarea(): \Common\Form\Element\FieldsTextarea
    {
        return new \Common\Form\Element\FieldsTextarea();
    }

    /**
     * Get the format of the config: "fields", "json", "xml", "yaml" or "ini".
     *
     * The yaml and the ini are distinguished by the first meaningful line: a
     * key followed by "=" or a section is ini, else yaml. A yaml without the
     * root key "elements" is the simple syntax of the fields, that can be
     * edited as a form.
     */
    protected function configFormat(string $config): string
    {
        $format = $this->configFormatByText($config);
        if ($format !== 'yaml' || !class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return $format;
        }
        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse(trim($config));
        } catch (\Throwable $e) {
            return $format;
        }
        return is_array($parsed) && !array_key_exists('elements', $parsed) ? 'fields' : 'yaml';
    }

    protected function configFormatByText(string $config): string
    {
        $config = trim($config);
        $first = mb_substr($config, 0, 1);
        if ($first === '{') {
            return 'json';
        } elseif ($first === '<') {
            return 'xml';
        }
        foreach (explode("\n", $config) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if ($line === '---' || $line[0] === '-') {
                return 'yaml';
            }
            if ($line[0] === '[') {
                return 'ini';
            }
            $equal = strpos($line, '=');
            $colon = strpos($line, ':');
            return $equal !== false && ($colon === false || $equal < $colon) ? 'ini' : 'yaml';
        }
        return 'ini';
    }

    /**
     * @return array|string Return a string in case of error, else the elements.
     */
    protected function readConfigElements()
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        return $this->parseConfig((string) $settings->get('userprofile_elements'));
    }

    /**
     * @return array|string Return a string in case of error, else the elements.
     */
    protected function parseConfig(string $elements)
    {
        $elements = trim($elements);
        if (!$elements) {
            return ['elements' => []];
        }

        $format = $this->configFormat($elements);

        if ($format === 'fields') {
            $fieldsTextarea = $this->fieldsTextarea();
            $specs = $fieldsTextarea->stringToArray($elements);
            if (!is_array($specs) || !$fieldsTextarea->validateFields($specs)) {
                return 'Invalid fields: check the yaml syntax, the field names (ascii, no space), the types and, for select/radio/multicheckbox, the list of values.'; // @translate
            }
            return ['elements' => $specs];
        }

        if ($format === 'yaml') {
            // The parser is provided by the module Common.
            if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                return 'The format yaml requires the library symfony/yaml.'; // @translate
            }
            try {
                $reader = new YamlReader([\Symfony\Component\Yaml\Yaml::class, 'parse']);
                $config = $reader->fromString($elements);
                if (is_array($config) && !empty($config['elements']) && is_array($config['elements'])) {
                    return $config;
                }
            } catch (\Throwable $e) {
                return $e->getMessage();
            }
        }

        if ($format === 'ini') {
            try {
                $reader = new IniReader;
                $config = $reader->fromString($elements);
                if (is_array($config) && !empty($config['elements']) && is_array($config['elements'])) {
                    return $config;
                }
            } catch (\Laminas\Config\Exception\RuntimeException $e) {
                return $e->getMessage();
            }
        }

        if ($format === 'json') {
            try {
                $reader = new JsonReader;
                $config = $reader->fromString($elements);
                if (is_array($config) && !empty($config['elements']) && is_array($config['elements'])) {
                    return $config;
                }
            } catch (\Laminas\Config\Exception\RuntimeException $e) {
                return $e->getMessage();
            }
        }

        if ($format === 'xml') {
            try {
                $reader = new XmlReader;
                $config = $reader->fromString($elements);
                if ($config && count($config)) {
                    return ['elements' => $config];
                }
            } catch (\Laminas\Config\Exception\RuntimeException $e) {
                return $e->getMessage();
            }
        }

        return 'Undetermined format.'; // @translate
    }

    /**
     * Get allowed values for a form element.
     *
     * Currently, only select and multicheckbox are checked.
     */
    protected function elementAllowedValues(Element $element): ?array
    {
        if (!method_exists($element, 'getValueOptions')) {
            return null;
        }

        $valueOptions = $element->getValueOptions();

        // Note: value options can be an array of arrays with keys value
        // and label, in particular when the config uses key with
        // forbidden letters.
        // It may be a grouped select too, so an array of arrays with
        // keys label and options.
        $first = reset($valueOptions);
        if (is_array($first)) {
            $result = [];
            if (isset($first['value'])) {
                foreach ($valueOptions as $array) {
                    $result[$array['value']] = true;
                }
            } elseif (isset($first['options'])) {
                foreach ($valueOptions as $array) {
                    foreach ($array['options'] as $value => $option) {
                        $result[is_array($option) ? $option['value'] : $value] = true;
                    }
                }
            }
            $valueOptions = $result;
        }

        // Don't add empty_option, that is a label.
        return array_keys($valueOptions);
    }

    /**
     * Get the fields that cannot be displayed or edited in the current context.
     *
     * The exclusions of edition are rights: they depend on the role of the
     * current user, an admin or not, in the admin interface and via the rest
     * api, and the public interface is always for users. The exclusions of
     * display depend on the interface. There is no exclusion for internal
     * processes.
     */
    protected function excludedFields(string $part): array
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        if ($status->isSiteRequest()) {
            $context = 'public';
        } elseif ($status->isAdminRequest() && $part !== 'edit') {
            $context = 'admin';
        } elseif ($status->isAdminRequest() || $status->isApiRequest()) {
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            $context = $user && $services->get('Omeka\Acl')->isAdminRole($user->getRole())
                ? 'admin'
                : 'public';
        } else {
            return [];
        }
        $exclude = $services->get('Omeka\Settings')->get('userprofile_exclude', []);
        $exclude = $exclude[$context][$part] ?? [];
        return is_array($exclude) ? $exclude : [];
    }
}
