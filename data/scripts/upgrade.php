<?php declare(strict_types=1);

namespace UserProfile;

use Common\Stdlib\PsrMessage;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\Controller\Plugin\Url $urlPlugin
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$urlPlugin = $plugins->get('url');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.54')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.54'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (version_compare($oldVersion, '3.4.4.6', '<')) {
    // TODO This is not possible directly since some features are not available during upgrade.
    // $this->updateListFields();
    $message = new PsrMessage(
        'To upgrade the config, you should go to the {link}config form{link_end} and submit it manually.', // @translate
        [
            'link' => '<a href="' . $urlPlugin->fromRoute('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'UserProfile']]) . '">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'It is now possible to remove values from guest form and to hide them in admin form.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.8', '<')) {
    /*
    // TODO This is not possible directly since some features are not available during upgrade.
    // Update hidden list of fields.
    try {
        $result = $this->updateListFields();
    } catch (\Exception $e) {
        $result = false;
    }
    if (!$result) {
        $message = new PsrMessage(
            'You should fix the config of the module or go to config form and save it.' // @ translate
        );
        $messenger->addWarning($message);
    }
    */
    if (version_compare($oldVersion, '3.4.4.6', '>=')) {
        $message = new PsrMessage(
            'To upgrade the config, you should go to the {link}config form{link_end} and submit it manually.', // @translate
            [
                'link' => '<a href="' . $urlPlugin->fromRoute('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'UserProfile']]) . '">',
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addWarning($message);
    }
}
