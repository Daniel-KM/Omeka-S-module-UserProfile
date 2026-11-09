<?php declare(strict_types=1);

namespace UserProfileTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Conversion of the old options of rights "exclude_*" into "show" and "edit".
 */
class ConvertRightsTest extends AbstractHttpControllerTestCase
{
    use UserProfileTestTrait;

    public function tearDown(): void
    {
        $this->resetConfig();
        parent::tearDown();
    }

    /**
     * Check that the converted config gives the same rights than the old one.
     */
    protected function assertSameRights(string $old, string $new): void
    {
        $this->assertTrue($this->setConfig($old));
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $expected = $settings->get('userprofile_exclude');
        $this->assertTrue($this->setConfig($new));
        $this->assertSame($expected, $settings->get('userprofile_exclude'));
        $this->assertStringNotContainsString('exclude_', $new);
    }

    public function testConvertIni(): void
    {
        $old = <<<'INI'
            ; A comment kept.
            elements.userprofile_a.name = "userprofile_a"
            elements.userprofile_a.type = "Text"
            elements.userprofile_a.options.label = "A: label"
            elements.userprofile_a.options.exclude_admin_edit = true
            elements.userprofile_a.options.exclude_public_edit = true
            elements.userprofile_a.options.exclude_public_show = true

            elements.userprofile_b.name = "userprofile_b"
            elements.userprofile_b.type = "Text"
            elements.userprofile_b.options.exclude_admin_show = false
            INI;
        $new = $this->module()->convertConfigRights($old);
        $this->assertStringContainsString('; A comment kept.', $new);
        $this->assertStringContainsString('elements.userprofile_a.options.edit = "none"', $new);
        $this->assertStringContainsString('elements.userprofile_a.options.show = "admin"', $new);
        $this->assertStringNotContainsString('userprofile_b.options.show', $new, 'A right for all is the default');
        $this->assertSameRights($old, $new);
    }

    public function testConvertJson(): void
    {
        $old = json_encode(['elements' => [
            'userprofile_a' => [
                'name' => 'userprofile_a',
                'type' => 'Text',
                'options' => ['label' => 'A', 'exclude_public_edit' => true, 'exclude_admin_show' => '1'],
            ],
        ]]);
        $new = $this->module()->convertConfigRights($old);
        $data = json_decode($new, true);
        $this->assertEquals(['label' => 'A', 'edit' => 'admin', 'show' => 'user'], $data['elements']['userprofile_a']['options']);
        $this->assertSameRights($old, $new);
    }

    public function testConvertXml(): void
    {
        $old = <<<'XML'
            <elements>
                <!-- A comment kept. -->
                <userprofile_a>
                    <name>userprofile_a</name>
                    <type>Text</type>
                    <options>
                        <label>A</label>
                        <exclude_admin_edit>1</exclude_admin_edit>
                        <exclude_public_show>1</exclude_public_show>
                    </options>
                </userprofile_a>
            </elements>
            XML;
        $new = $this->module()->convertConfigRights($old);
        $this->assertStringContainsString('<!-- A comment kept. -->', $new);
        $this->assertStringContainsString('<edit>user</edit>', $new);
        $this->assertStringContainsString('<show>admin</show>', $new);
        $this->assertSameRights($old, $new);
    }

    public function testConfigWithoutOldOptionsIsNotModified(): void
    {
        $this->assertNull($this->module()->convertConfigRights($this->configIni));
        $this->assertNull($this->module()->convertConfigRights("elements:\n  userprofile_a:\n    name: userprofile_a\n    type: Text"));
    }
}
