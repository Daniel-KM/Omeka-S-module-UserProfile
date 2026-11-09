<?php declare(strict_types=1);

namespace UserProfileTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Conversion of the config between the formats.
 */
class ConvertFormatTest extends AbstractHttpControllerTestCase
{
    use UserProfileTestTrait;

    protected function fieldsAndRights(string $config): array
    {
        $this->assertTrue($this->setConfig($config));
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        return [$settings->get('userprofile_fields'), $settings->get('userprofile_exclude')];
    }

    public function tearDown(): void
    {
        $this->resetConfig();
        parent::tearDown();
    }

    /**
     * @dataProvider formats
     */
    public function testConversionKeepsFieldsAndRights(string $format): void
    {
        $expected = $this->fieldsAndRights($this->configIni);
        $module = $this->module();

        $converted = $module->convertConfigFormat($this->configIni, $format);
        $this->assertSame($format, $this->invokeModule('configFormat', [$converted]));
        $this->assertEquals($expected, $this->fieldsAndRights($converted));

        // And back to the first format.
        $back = $module->convertConfigFormat($converted, 'ini');
        $this->assertEquals($expected, $this->fieldsAndRights($back));
    }

    public function formats(): array
    {
        return [['fields'], ['yaml'], ['json'], ['xml'], ['ini']];
    }

    public function testSimpleSyntax(): void
    {
        $fields = <<<'YAML'
            userprofile_phone:
              label: Phone
              type: tel
            userprofile_organisation:
              label: Organisation
              type: select
              required: true
              values:
                alpha: Alpha
                beta: Beta
              options:
                edit: admin
            userprofile_note: Note
            YAML;
        $this->assertSame('fields', $this->invokeModule('configFormat', [$fields]));
        [$list, $rights] = $this->fieldsAndRights($fields);
        $this->assertSame(['userprofile_phone', 'userprofile_organisation', 'userprofile_note'], array_keys($list));
        $this->assertSame(['alpha', 'beta'], $list['userprofile_organisation']['allowed_values']);
        $this->assertTrue($list['userprofile_organisation']['required']);
        $this->assertSame('Note', $list['userprofile_note']['label']);
        $this->assertSame(['userprofile_organisation'], $rights['public']['edit']);

        // The yaml with the root "elements" is the full Laminas specification.
        $this->assertSame('yaml', $this->invokeModule('configFormat', ["elements:\n  userprofile_a:\n    name: userprofile_a\n    type: Text"]));
    }

    public function testTypeWithoutSimpleSyntaxIsRefused(): void
    {
        $ini = <<<'INI'
            elements.userprofile_a.name = "userprofile_a"
            elements.userprofile_a.type = "Omeka\\Form\\Element\\UserSelect"
            INI;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UserSelect');
        $this->module()->convertConfigFormat($ini, 'fields');
    }

    public function testSelectOfOmekaIsConvertedToSimpleSyntax(): void
    {
        $ini = <<<'INI'
            elements.userprofile_a.name = "userprofile_a"
            elements.userprofile_a.type = "Omeka\\Form\\Element\\ItemSetSelect"
            elements.userprofile_a.options.label = "A"
            elements.userprofile_a.options.empty_option = ""
            INI;
        $fields = $this->module()->convertConfigFormat($ini, 'fields');
        $this->assertStringContainsString('type: itemset', $fields);
    }

    public function testTypeOfModuleIsConvertedToSimpleSyntax(): void
    {
        if (!class_exists(\CustomVocab\Form\Element\CustomVocabSelect::class)) {
            $this->markTestSkipped('Requires module CustomVocab.');
        }
        $ini = <<<'INI'
            elements.userprofile_a.name = "userprofile_a"
            elements.userprofile_a.type = "CustomVocab\\Form\\Element\\CustomVocabSelect"
            elements.userprofile_a.options.label = "A"
            elements.userprofile_a.options.custom_vocab_id = "3"
            elements.userprofile_a.attributes.multiple = "1"
            INI;
        $fields = $this->module()->convertConfigFormat($ini, 'fields');
        $this->assertStringContainsString('type: customvocab', $fields);
        $this->assertStringContainsString('multiple:', $fields);
    }

    public function testConfigFieldExplainsWhyItIsNotEditableAsAForm(): void
    {
        $this->getServiceLocator()->get('Omeka\Settings')->set('userprofile_elements', <<<'INI'
            elements.userprofile_a.name = "userprofile_a"
            elements.userprofile_a.type = "Omeka\\Form\\Element\\UserSelect"
            INI);
        $this->requiresLogin = true;
        $this->dispatch('/admin/module/configure?id=UserProfile');
        $this->assertResponseStatusCode(200);
        $body = html_entity_decode($this->getResponse()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertMatchesRegularExpression('~data-raw-message="The list uses the full Laminas specifications \\(root key "elements"\\).*?This conversion is not possible for the current list: The type "Omeka.*?UserSelect" of the field "userprofile_a" is not available~s', $body);
    }

    public function testConfigFieldIsEditableAsAForm(): void
    {
        $this->requiresLogin = true;
        $this->dispatch('/admin/module/configure?id=UserProfile');
        $this->assertResponseStatusCode(200);
        $body = $this->getResponse()->getBody();
        $this->assertMatchesRegularExpression('~<textarea[^>]*class="common-fields-textarea"[^>]*data-raw-keys="elements"~', $body);
        $this->assertStringContainsString('common-fields-textarea.js', $body);
    }

    public function testXmlSupportsValuesThatAreNotNodeNames(): void
    {
        $yaml = <<<'YAML'
            elements:
              userprofile_a:
                name: userprofile_a
                type: Select
                options:
                  label: A
                  value_options:
                    Gamma’s Delta: Gamma’s Delta
                    alpha: Alpha
            YAML;
        $xml = $this->module()->convertConfigFormat($yaml, 'xml');
        $this->assertStringContainsString('<value>Gamma’s Delta</value>', $xml);
        $this->assertStringStartsWith('<elements>', $xml);
        [$fields] = $this->fieldsAndRights($xml);
        $this->assertSame(['Gamma’s Delta', 'alpha'], $fields['userprofile_a']['allowed_values']);
    }

    public function testConversionWithLossIsRefused(): void
    {
        $yaml = <<<'YAML'
            elements:
              userprofile_a:
                name: userprofile_a
                type: Select
                options:
                  value_options:
                    v1.5: Version 1.5
            YAML;
        // A dot is the separator of the keys in ini.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('userprofile_a / options / value_options / v1.5');
        $this->module()->convertConfigFormat($yaml, 'ini');
    }

    protected $configWithDot = <<<'YAML'
        elements:
          userprofile_a:
            name: userprofile_a
            type: Select
            options:
              value_options:
                v1.5: Version 1.5
        YAML;

    public function testImpossibleFormatIsDisabledInTheForm(): void
    {
        $this->setConfig($this->configWithDot);
        $this->assertSame(['ini'], $this->module()->impossibleConfigFormats());

        $form = $this->getServiceLocator()->get('FormElementManager')->get(\UserProfile\Form\ConfigForm::class);
        $form->init();
        $options = $form->get('userprofile_convert_format')->getValueOptions();
        $this->assertTrue($options['ini']['disabled']);
        $this->assertFalse($options['json']['disabled']);
    }

    public function testConfigPageDisablesTheImpossibleFormat(): void
    {
        $this->setConfig($this->configWithDot);
        $this->requiresLogin = true;
        $this->dispatch('/admin/module/configure?id=UserProfile');
        $this->assertResponseStatusCode(200);
        $body = html_entity_decode($this->getResponse()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertMatchesRegularExpression('~<input[^>]*name="userprofile_convert_format"[^>]*value="ini"[^>]*disabled~', $body);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*name="userprofile_convert_format"[^>]*value="json"[^>]*disabled~', $body);
    }

    public function testImpossibleConversionIsNotSaved(): void
    {
        $this->setConfig($this->configIni);
        $this->assertFalse($this->saveConfigForm([
            'userprofile_elements' => $this->configWithDot,
            'userprofile_convert_format' => 'ini',
        ]));
        $this->assertSame($this->configIni, $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_elements'));
    }

    protected function saveConfigForm(array $post): bool
    {
        $services = $this->getServiceLocator();
        $this->loginAsAdmin();
        $form = $services->get('FormElementManager')->get(\UserProfile\Form\ConfigForm::class);
        $form->init();
        $post['csrf'] = $form->get('csrf')->getValue();
        $controller = $services->get('ControllerManager')->get('Omeka\Controller\Admin\Module');
        $controller->getRequest()->setMethod('POST')->setPost(new \Laminas\Stdlib\Parameters($post));
        return $this->module()->handleConfigForm($controller);
    }

    public function testConfigFormConvertsOnSave(): void
    {
        $this->assertTrue($this->saveConfigForm([
            'userprofile_elements' => $this->configIni,
            'userprofile_convert_format' => 'yaml',
        ]));
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $this->assertStringStartsWith('elements:', $settings->get('userprofile_elements'));
        $this->assertArrayHasKey('userprofile_text', $settings->get('userprofile_fields'));
        $this->assertNull($settings->get('userprofile_convert_format'), 'The format is not a setting');
    }

    public function testConfigFormKeepsTheFormatByDefault(): void
    {
        $this->assertTrue($this->saveConfigForm([
            'userprofile_elements' => $this->configIni,
            'userprofile_convert_format' => '',
        ]));
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $this->assertSame(trim($this->configIni), trim($settings->get('userprofile_elements')));
    }

    public function testInvalidSourceIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->module()->convertConfigFormat('{"elements": ', 'yaml');
    }
}
