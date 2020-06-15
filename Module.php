<?php
namespace UserProfile;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;
use Zend\Config\Reader\Ini as IniReader;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function preInstall()
    {
        $services = $this->getServiceLocator();
        $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
        if ($module && version_compare($module->getIni('version'), '3.0.20', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new Message($translator->translate('This module requires the module "%1$s" >= %2$s.'),
                'Generic', '3.0.20'
            );
            throw new ModuleCannotInstallException($message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Manage user settings via rest api.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.hydrate.pre',
            [$this, 'apiHydratePreUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.create.post',
            [$this, 'apiCreateOrUpdatePostUser']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\UserAdapter::class,
            'api.update.post',
            [$this, 'apiCreateOrUpdatePostUser']
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
        if (!parent::handleConfigForm($controller)) {
            return false;
        }

        $this->updateListFields();
        return true;
    }

    protected function updateListFields()
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $elements = $settings->get('userprofile_elements');
        if (!$elements) {
            $settings->set('userprofile_fields', []);
            return;
        }

        $iniReader = new IniReader;
        $ini = $iniReader->fromString($elements);
        if (empty($ini['elements'])) {
            $settings->set('userprofile_fields', []);
            return;
        }

        $fields = [];
        foreach ($ini['elements'] as $element) {
            if (!isset($element['name'])) {
                continue;
            }
            $fields[$element['name']] = empty($element['options']['label'])
                ? $element['name']
                : $element['options']['label'];
        }
        $settings->set('userprofile_fields', $fields);
    }

    public function handleUserSettings(Event $event)
    {
        // Compatibility with module Guest.
        $services = $this->getServiceLocator();
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if ($status->isSiteRequest()) {
            /** @var \Zend\Router\Http\RouteMatch $routeMatch */
            $routeMatch = $services->get('Application')->getMvcEvent()->getRouteMatch();
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
            $this->handleAnySettings($event, 'user_settings');
        } elseif ($status->isApiRequest()) {
            $this->handleAnySettings($event, 'user_settings');
        } else {
            parent::handleUserSettings($event);
        }
    }

    protected function handleAnySettings(Event $event, $settingsType)
    {
        if ($settingsType !== 'user_settings') {
            return parent::handleAnySettings($event, $settingsType);
        }

        $form = parent::handleAnySettings($event, $settingsType);

        // Specific to this module.
        $services = $this->getServiceLocator();
        $formFieldset = $form->get('user-settings');

        $settings = $services->get('Omeka\Settings');
        $elements = $settings->get('userprofile_elements', '');

        $status = $services->get('Omeka\Status');
        $userExist = !$status->isApiRequest() || $status->getRouteMatch()->getParam('id');
        $userSettings = $userExist
            ? $services->get('Omeka\Settings\User')
            : null;

        if ($elements) {
            $iniReader = new IniReader;
            $ini = $iniReader->fromString($elements);
            if (!empty($ini['elements'])) {
                foreach ($ini['elements'] as $name => $element) {
                    $data[$name] = $userExist ? $userSettings->get($name) : null;
                    $formFieldset
                        ->add($element)
                        ->get($name)->setValue($data[$name]);
                }
            }

            // Fix to manage empty values for selects and multicheckboxes.
            // @see \Omeka\Controller\SiteAdmin\IndexController::themeSettingsAction()
            $inputFilter = $form->getInputFilter()->get('user-settings');
            foreach ($formFieldset->getElements() as $element) {
                if ($element instanceof \Zend\Form\Element\MultiCheckbox
                    || $element instanceof \Zend\Form\Element\Tel
                    || ($element instanceof \Zend\Form\Element\Select
                        && $element->getOption('empty_option') !== null)
                ) {
                    if (!$element->getAttribute('required')) {
                        $inputFilter->add([
                            'name' => $element->getName(),
                            'allow_empty' => true,
                            'required' => false,
                        ]);
                    }
                }
            }
        }

        return $form;
    }

    public function apiHydratePreUser(Event $event)
    {
        $services = $this->getServiceLocator();

        // Only for the rest api manager.
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            return;
        }

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
            $errorStore->addError('o:setting', new Message(
                'The key “o:setting” should be an array of user settings.' // @translate
            ));
            return;
        }

        foreach ($requestUserSettings as $key => $value) {
            if (!$fieldset->has($key)) {
                continue;
            }

            // TODO Use the validator of the element.
            /** @var \Zend\Form\Element $element */
            $element = $fieldset->get($key);
            $isMultipleValues = is_array($value);

            if ($element->getAttribute('required')
                && (($isMultipleValues && !count($value)) || (!$isMultipleValues && !strlen($value)))
            ) {
                $errorStore->addError('o:setting', new Message(
                    'A value is required for “%s”.', // @translate
                    $key
                ));
                continue;
            }

            // Currently, only select and multicheckbox are checked.
            if (method_exists($element, 'getValueOptions')) {
                $valueOptions = $element->getValueOptions();
                if ($isMultipleValues) {
                    $values = array_intersect_key($valueOptions, array_flip($value));
                    if (method_exists($element, 'isMultiple') && !$element->isMultiple()) {
                        $errorStore->addError('o:setting', new Message(
                            'Only one value is allowed for “%s”.', // @translate
                            $key
                        ));
                    } elseif (count($value) !== count($values)) {
                        $errorStore->addError('o:setting', new Message(
                            'One or more values (“%s”) are not allowed for “%s”.', // @translate
                            implode('”, “', array_diff($value, array_keys($valueOptions))), $key
                        ));
                    } elseif (!count($values) && $element->getAttribute('required')) {
                        $errorStore->addError('o:setting', new Message(
                            'A value is required for “%s”.', // @translate
                            $key
                        ));
                    }
                } else {
                    if (strlen($value) && !isset($valueOptions[$value])) {
                        $errorStore->addError('o:setting', new Message(
                            'The value “%s” is not allowed for “%s”.', // @translate
                            $value, $key
                        ));
                    }
                }
            } else {
                if (method_exists($element, 'isMultiple') && $element->isMultiple()
                    || $element instanceof \Zend\Form\Element\MultiCheckbox
                ) {
                    $errorStore->addError('o:setting', new Message(
                        'An array of values is required for “%s”.', // @translate
                        $key
                    ));
                } elseif (!isset($valueOptions[$value])) {
                    $errorStore->addError('o:setting', new Message(
                        'The value “%s” is not allowed for “%s”.', // @translate
                        $value, $key
                    ));
                }
            }
            // TODO Add more check or use element validator.
        }
    }

    public function apiCreateOrUpdatePostUser(Event $event)
    {
        $services = $this->getServiceLocator();

        // Only for the rest api manager.
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if (!$status->isApiRequest()) {
            return;
        }

        // Only if the request has settings.
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $requestUserSettings = $request->getValue('o:setting');
        if (!$requestUserSettings || !is_array($requestUserSettings)) {
            return;
        }

        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $user = $event->getParam('response')->getContent();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->getId());
        $fieldset = $this->userSettingsFieldset($user->getId());

        foreach ($requestUserSettings as $key => $value) {
            // Silently skip if not exist for security and clean process.
            if (!$fieldset->has($key)) {
                continue;
            }

            // TODO Use the validator of the element.
            /** @var \Zend\Form\Element $element */
            $element = $fieldset->get($key);
            $isMultipleValues = is_array($value);

            // Some useless cleaning.
            if (method_exists($element, 'getValueOptions') && $isMultipleValues) {
                $value = array_keys(array_intersect_key($element->getValueOptions(), array_flip($value)));
            } elseif ($element instanceof \Zend\Form\Element\Checkbox) {
                $value = (bool) $value;
            } elseif ($element instanceof \Zend\Form\Element\Number) {
                $value = (int) $value;
            }

            $userSettings->set($key, $value);
        }
    }

    /**
     * Get the fieldset "user-setting" of the user form.
     *
     * @param int $userId
     * @return \Zend\Form\Fieldset
     */
    protected function userSettingsFieldset($userId = null)
    {
        $form = $this->getServiceLocator()->get('FormElementManager')
            ->get(\Omeka\Form\UserForm::class, [
                'user_id' => $userId,
                'include_role' => true,
                'include_admin_roles' => true,
                'include_is_active' => true,
                'current_password' => false,
                'include_password' => false,
                'include_key' => false,
            ]);
        $form->init();
        return $form
            ->get('user-settings');
    }

    public function viewUserDetails(Event $event)
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $this->viewUserData($view, $user, 'common/admin/user-profile');
    }

    public function viewUserShowAfter(Event $event)
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $this->viewUserData($view, $user, 'common/admin/user-profile-list');
    }

    protected function viewUserData(PhpRenderer $view, UserRepresentation $user, $partial)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $elements = $settings->get('userprofile_elements', '');
        if (!$elements) {
            return;
        }

        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());
        echo $view->partial(
            $partial,
            [
                'user' => $user,
                'userSettings' => $userSettings,
                'fields' => $settings->get('userprofile_fields', []),
            ]
        );
    }
}
