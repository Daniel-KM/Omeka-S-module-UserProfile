<?php
namespace GuestProfile;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Guest';

    public function preInstall()
    {
        $services = $this->getServiceLocator();
        if (!$this->isModuleActive($this->dependency)) {
            $translator = $services->get('MvcTranslator');
            $message = new Message($translator->translate('This module requires the module "%s".'),
                $this->dependency
            );
            throw new ModuleCannotInstallException($message);
        }

        $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
        if (!$module || version_compare($module->getIni('version'), '3.0.13', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new Message($translator->translate('This module requires the module "%1$s" >= %2$s.'),
                'Generic', '3.0.13'
            );
            throw new ModuleCannotInstallException($message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Add the guest profile to the user show admin pages.
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

        // Add the guest profile elements to the user form.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'handleUserSettings']
        );
    }

    public function handleUserSettings(Event $event)
    {
        $services = $this->getServiceLocator();
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if ($status->isSiteRequest()) {
            /** @var \Zend\Router\Http\RouteMatch $routeMatch */
            $routeMatch = $services->get('Application')->getMvcEvent()->getRouteMatch();
            if (!in_array($routeMatch->getParam('controller'), [\Guest\Controller\Site\GuestController::class, 'user'])) {
                return;
            }
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            if (!$user) {
                return;
            }
            $routeMatch->setParam('id', $user->getId());
            $this->handleAnySettings($event, 'user_settings');
        } else {
            parent::handleUserSettings($event);
        }
    }

    public function viewUserDetails(Event $event)
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $this->viewUserData($view, $user, 'common/admin/guest-profile');
    }

    public function viewUserShowAfter(Event $event)
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $this->viewUserData($view, $user, 'common/admin/guest-profile-list');
    }

    protected function viewUserData(PhpRenderer $view, UserRepresentation $user, $partial)
    {
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());
        echo $view->partial(
            $partial,
            [
                'user' => $user,
                'userSettings' => $userSettings,
            ]
        );
    }
}
