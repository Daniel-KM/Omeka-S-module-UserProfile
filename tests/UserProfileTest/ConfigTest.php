<?php declare(strict_types=1);

namespace UserProfileTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Parsing of the config and list of the fields.
 */
class ConfigTest extends AbstractHttpControllerTestCase
{
    use UserProfileTestTrait;

    public function tearDown(): void
    {
        $this->resetConfig();
        parent::tearDown();
    }

    public function testUpdateListFields(): void
    {
        $this->assertTrue($this->setConfig());
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $fields = $settings->get('userprofile_fields');
        $this->assertSame(['userprofile_text', 'userprofile_select', 'userprofile_multi', 'userprofile_check', 'userprofile_hidden', 'userprofile_category', 'userprofile_personal', 'userprofile_admin_note', 'userprofile_private'], array_keys($fields));
        $this->assertSame('Text field', $fields['userprofile_text']['label']);
        $this->assertNull($fields['userprofile_text']['allowed_values']);
        $this->assertSame(['alpha', 'beta'], $fields['userprofile_select']['allowed_values']);
        $this->assertFalse($fields['userprofile_select']['is_multiple']);
        $this->assertTrue($fields['userprofile_multi']['is_multiple']);
        $this->assertTrue($fields['userprofile_check']['is_bool']);

        $this->assertSame([
            'admin' => ['show' => ['userprofile_hidden', 'userprofile_private'], 'edit' => ['userprofile_hidden', 'userprofile_personal', 'userprofile_private']],
            'public' => ['show' => ['userprofile_hidden', 'userprofile_admin_note'], 'edit' => ['userprofile_hidden', 'userprofile_category', 'userprofile_admin_note']],
        ], $settings->get('userprofile_exclude'));
    }

    public function testJsonConfig(): void
    {
        $json = json_encode(['elements' => [
            'userprofile_phone' => [
                'name' => 'userprofile_phone',
                'type' => 'Tel',
                'options' => ['label' => 'Phone'],
            ],
        ]]);
        $this->assertTrue($this->setConfig($json));
        $fields = $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_fields');
        $this->assertSame(['userprofile_phone'], array_keys($fields));
    }

    public function testYamlConfig(): void
    {
        $yaml = <<<'YAML'
            # A comment.
            elements:
              userprofile_phone:
                name: userprofile_phone
                type: Tel
                options:
                  label: 'Phone: mobile'
              userprofile_organisation:
                name: userprofile_organisation
                type: Select
                options:
                  label: Organisation
                  empty_option: ''
                  value_options:
                    alpha: Alpha
                    Gamma’s Delta: Gamma’s Delta
                  edit: admin
            YAML;
        $this->assertTrue($this->setConfig($yaml));
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $fields = $settings->get('userprofile_fields');
        $this->assertSame(['userprofile_phone', 'userprofile_organisation'], array_keys($fields));
        $this->assertSame('Phone: mobile', $fields['userprofile_phone']['label']);
        $this->assertSame(['alpha', 'Gamma’s Delta'], $fields['userprofile_organisation']['allowed_values']);
        $this->assertSame(['userprofile_organisation'], $settings->get('userprofile_exclude')['public']['edit']);
    }

    public function testOldOptionsOfRightsAreStillRead(): void
    {
        $ini = <<<'INI'
            elements.userprofile_a.name = "userprofile_a"
            elements.userprofile_a.type = "Text"
            elements.userprofile_a.options.exclude_public_edit = true
            elements.userprofile_a.options.exclude_admin_show = true
            INI;
        $this->assertTrue($this->setConfig($ini));
        $this->assertSame([
            'admin' => ['show' => ['userprofile_a'], 'edit' => []],
            'public' => ['show' => [], 'edit' => ['userprofile_a']],
        ], $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_exclude'));
    }

    public function testInvalidRightIsRefused(): void
    {
        $this->assertFalse($this->setConfig("elements.userprofile_a.name = \"userprofile_a\"\nelements.userprofile_a.type = \"Text\"\nelements.userprofile_a.options.edit = \"everybody\""));
    }

    public function testAllowedValuesOfGroupedSelect(): void
    {
        $yaml = <<<'YAML'
            elements:
              userprofile_grouped:
                name: userprofile_grouped
                type: Select
                options:
                  value_options:
                    europe:
                      label: Europe
                      options:
                        fr: France
                        be: Belgique
                    asia:
                      label: Asia
                      options:
                        jp: Japan
            YAML;
        $this->assertTrue($this->setConfig($yaml));
        $fields = $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_fields');
        $this->assertSame(['fr', 'be', 'jp'], $fields['userprofile_grouped']['allowed_values']);
    }

    public function testInvalidYamlIsRefused(): void
    {
        $this->assertFalse($this->setConfig("elements:\n  userprofile_bad:\n\tname: bad"), 'A tabulation is invalid in yaml');
    }

    public function testConfigFormat(): void
    {
        $this->assertSame('json', $this->invokeModule('configFormat', ['{"elements": {}}']));
        $this->assertSame('xml', $this->invokeModule('configFormat', ['<elements/>']));
        $this->assertSame('yaml', $this->invokeModule('configFormat', ["# Comment\nelements:\n  a:\n    name: a"]));
        $this->assertSame('ini', $this->invokeModule('configFormat', ['; Comment' . "\n" . 'elements.a.options.label = "Note: a"']));
        $this->assertSame('ini', $this->invokeModule('configFormat', ["[elements]\na.name = a"]));
    }

    public function testForbiddenCharactersInNamesAreReported(): void
    {
        $this->assertSame(
            'These names of field contain forbidden characters: only the letters without accent, the digits, "_" and "-" are allowed: user profile, profil.é',
            $this->invokeModule('parseConfig', ["user profile: Text\nprofil.é: Text\nuserprofile_ok: Text"])
        );
    }

    public function testInvalidConfigIsRefused(): void
    {
        $this->assertFalse($this->setConfig('elements.userprofile_bad.type = "Text"'), 'An element without name is invalid');
        $this->assertFalse($this->setConfig('{"elements": '), 'A malformed json is invalid');
    }
}
