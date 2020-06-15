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

        $userSettings = $services->get('Omeka\Settings\User');
        if ($elements) {
            $iniReader = new IniReader;
            $ini = $iniReader->fromString($elements);
            if (!empty($ini['elements'])) {
                foreach ($ini['elements'] as $name => $element) {
                    $data[$name] = $userSettings->get($name);
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
