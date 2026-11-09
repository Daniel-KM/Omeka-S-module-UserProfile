<?php declare(strict_types=1);

namespace UserProfileTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Conversion of the profiles of the old module Guest Profile.
 */
class GuestProfileUpgradeTest extends AbstractHttpControllerTestCase
{
    use UserProfileTestTrait;

    protected $userId;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        $this->resetConfig();
        $this->userId = $this->createUser('userprofile-guest-' . uniqid() . '@example.org');
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $connection->insert('user_setting', ['id' => 'guestprofile_field_1', 'user_id' => $this->userId, 'value' => '"étudiant"']);
        $connection->insert('user_setting', ['id' => 'guestprofile_field_2', 'user_id' => $this->userId, 'value' => '"Doctorant"']);
    }

    public function tearDown(): void
    {
        $this->cleanupUsers();
        $this->resetConfig();
        parent::tearDown();
    }

    public function testConvertConfigAndValues(): void
    {
        $this->module()->upgradeFromGuestProfile();
        // A second run changes nothing.
        $this->module()->upgradeFromGuestProfile();

        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        // An empty config receives the simple syntax, editable as a form.
        $elements = $settings->get('userprofile_elements');
        $this->assertSame('fields', $this->invokeModule('configFormat', [$elements]));
        $this->assertSame(1, preg_match_all('~^userprofile_status:~m', $elements));
        $fields = $settings->get('userprofile_fields');
        $this->assertSame(['userprofile_status', 'userprofile_status_other'], array_keys($fields));
        $this->assertContains('étudiant', $fields['userprofile_status']['allowed_values']);

        $this->assertSame('étudiant', $this->userSetting($this->userId, 'userprofile_status'));
        $this->assertSame('Doctorant', $this->userSetting($this->userId, 'userprofile_status_other'));
        $this->assertSame('étudiant', $this->userSetting($this->userId, 'guestprofile_field_1'), 'The old value is kept');
    }

    public function testExistingValueIsNotReplaced(): void
    {
        $this->getServiceLocator()->get('Omeka\Settings\User')->set('userprofile_status', 'auteur', $this->userId);
        $this->module()->upgradeFromGuestProfile();
        $this->assertSame('auteur', $this->userSetting($this->userId, 'userprofile_status'));
    }

    public function testConfigInIniReceivesIni(): void
    {
        $ini = "elements.userprofile_phone.name = \"userprofile_phone\"\nelements.userprofile_phone.type = \"Tel\"";
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('userprofile_elements', $ini);
        $this->module()->upgradeFromGuestProfile();
        $elements = $settings->get('userprofile_elements');
        $this->assertStringStartsWith($ini, $elements);
        $this->assertStringContainsString('elements.userprofile_status.name', $elements);
        $this->assertSame('ini', $this->invokeModule('configFormat', [$elements]));
    }

    public function testConfigInYamlIsNotModified(): void
    {
        $yaml = "elements:\n  userprofile_phone:\n    name: userprofile_phone\n    type: Tel";
        $this->getServiceLocator()->get('Omeka\Settings')->set('userprofile_elements', $yaml);
        $this->module()->upgradeFromGuestProfile();
        $this->assertSame($yaml, $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_elements'));
    }

    public function testConfigInJsonIsNotModified(): void
    {
        $json = '{"elements": {"userprofile_phone": {"name": "userprofile_phone", "type": "Tel"}}}';
        $this->getServiceLocator()->get('Omeka\Settings')->set('userprofile_elements', $json);
        $this->module()->upgradeFromGuestProfile();
        $this->assertSame($json, $this->getServiceLocator()->get('Omeka\Settings')->get('userprofile_elements'));
        $this->assertSame('étudiant', $this->userSetting($this->userId, 'userprofile_status'), 'The values are copied anyway');
    }
}
