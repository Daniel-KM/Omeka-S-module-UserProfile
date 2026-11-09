<?php declare(strict_types=1);

namespace UserProfile\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use UserProfile\Form\ConfigForm;
use UserProfile\Module;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $module = new Module();
        $module->setServiceLocator($services);

        return (new ConfigForm(null, $options ?? []))
            ->setImpossibleFormats($module->impossibleConfigFormats());
    }
}
