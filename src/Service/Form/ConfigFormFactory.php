<?php declare(strict_types=1);

namespace UserProfile\Service\Form;

use Interop\Container\ContainerInterface;
use Common\Stdlib\PsrMessage;
use Laminas\ServiceManager\Factory\FactoryInterface;
use UserProfile\Form\ConfigForm;
use UserProfile\Module;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $module = new Module();
        $module->setServiceLocator($services);
        $reasons = $module->impossibleConfigFormatReasons();

        // Explain how to edit as a form a list of the full Laminas specifications.
        $translator = $services->get('MvcTranslator');
        $rawMessage = (string) (new PsrMessage(
            'The list uses the full Laminas specifications (root key "elements"). To edit it as a form, select "simple syntax" in the option "Convert the list of fields when saving" below, then save.' // @translate
        ))->setTranslator($translator);
        if (isset($reasons['fields'])) {
            $rawMessage .= "\n\n" . (new PsrMessage(
                'This conversion is not possible for the current list: {reason}', // @translate
                ['reason' => $reasons['fields']]
            ))->setTranslator($translator);
        }

        return (new ConfigForm(null, $options ?? []))
            ->setImpossibleFormats(array_keys($reasons))
            ->setRawMessage($rawMessage);
    }
}
