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
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$helpers = $services->get('ViewHelperManager');
$url = $helpers->get('url');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.96'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare($oldVersion, '3.4.4.6', '<')) {
    // TODO This is not possible directly since some features are not available during upgrade.
    // $this->updateListFields();
    $message = new PsrMessage(
        'To upgrade the config, you should go to the {link}config form{link_end} and submit it manually.', // @translate
        [
            'link' => '<a href="' . $url('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'UserProfile']]) . '">',
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
    } catch (\Throwable $e) {
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
                'link' => '<a href="' . $url('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'UserProfile']]) . '">',
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addWarning($message);
    }
}

if (version_compare($oldVersion, '3.4.13', '<')) {
    // Convert the profiles of the old module Guest Profile, if any.
    $this->upgradeFromGuestProfile();

    // The four options of rights "exclude_*" are replaced by "show" and "edit".
    $config = (string) $settings->get('userprofile_elements');
    $converted = $this->convertConfigRights($config);
    if ($converted !== null) {
        $settings->set('userprofile_elements', $converted);
        $services->get('Omeka\Logger')->info(
            'The options of rights of the config of the module User Profile were converted. The previous config was: {config}', // @translate
            ['config' => $config]
        );
        $message = new PsrMessage(
            'The options of rights of the fields "exclude_admin_show", "exclude_admin_edit", "exclude_public_show" and "exclude_public_edit" were replaced by the options "show" and "edit" ("all", "admin", "user" or "none"). The previous config is logged.' // @translate
        );
        $messenger->addSuccess($message);
    }

    // The list of excluded fields kept only the last one, as a string, so no
    // field was excluded.
    if (trim((string) $settings->get('userprofile_elements')) !== '' && !$this->updateListFields()) {
        $message = new PsrMessage(
            'To upgrade the config, you should go to the {link}config form{link_end} and submit it manually.', // @translate
            [
                'link' => '<a href="' . $url('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'UserProfile']]) . '">',
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addWarning($message);
    }
}
