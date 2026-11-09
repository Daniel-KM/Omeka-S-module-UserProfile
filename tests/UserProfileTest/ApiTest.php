<?php declare(strict_types=1);

namespace UserProfileTest;

use CommonTest\AbstractHttpControllerTestCase;
use Omeka\Api\Exception\ValidationException;

/**
 * Management of the user settings of the profile via the rest api.
 *
 * The listeners are restricted to the rest api, so the internal api is used
 * with the flag of the rest api.
 */
class ApiTest extends AbstractHttpControllerTestCase
{
    use UserProfileTestTrait;

    protected $userId;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        $this->setConfig();
        $this->userId = $this->createUser('userprofile-api-' . uniqid() . '@example.org');
        $this->setApiRequest(true, $this->userId);
    }

    public function tearDown(): void
    {
        $this->setApiRequest(false);
        $this->cleanupUsers();
        $this->resetConfig();
        parent::tearDown();
    }

    protected function updateSettings(array $settings)
    {
        return $this->api()->update('users', $this->userId, ['o:setting' => $settings], [], ['isPartial' => true]);
    }

    public function testUpdateTextAndSelect(): void
    {
        $this->updateSettings(['userprofile_text' => 'Some text', 'userprofile_select' => 'beta']);
        $this->assertSame('Some text', $this->userSetting($this->userId, 'userprofile_text'));
        $this->assertSame('beta', $this->userSetting($this->userId, 'userprofile_select'));
    }

    public function testInvalidSelectValueIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->updateSettings(['userprofile_select' => 'gamma']);
    }

    public function testMultipleValues(): void
    {
        $this->updateSettings(['userprofile_multi' => ['one', 'two']]);
        $this->assertSame(['one', 'two'], $this->userSetting($this->userId, 'userprofile_multi'));

        $this->expectException(ValidationException::class);
        $this->updateSettings(['userprofile_multi' => ['one', 'bad']]);
    }

    public function testCheckbox(): void
    {
        $this->updateSettings(['userprofile_check' => '1']);
        $this->assertTrue($this->userSetting($this->userId, 'userprofile_check'));
    }

    public function testUnknownFieldsAreSkipped(): void
    {
        $this->updateSettings(['userprofile_unknown' => 'value', 'userprofile_text' => 'ok']);
        $this->assertNull($this->userSetting($this->userId, 'userprofile_unknown'));
        $this->assertSame('ok', $this->userSetting($this->userId, 'userprofile_text'));
    }

    /**
     * An admin cannot edit a field excluded from the edition by admins.
     */
    public function testAdminCannotEditExcludedFields(): void
    {
        $this->updateSettings(['userprofile_hidden' => 'no', 'userprofile_personal' => 'no', 'userprofile_category' => 'yes']);
        $this->assertNull($this->userSetting($this->userId, 'userprofile_hidden'));
        $this->assertNull($this->userSetting($this->userId, 'userprofile_personal'));
        $this->assertSame('yes', $this->userSetting($this->userId, 'userprofile_category'));
    }

    /**
     * A user cannot edit a field excluded from the edition by users.
     */
    public function testUserCannotEditExcludedFields(): void
    {
        $this->loginAs($this->userId);
        $this->updateSettings(['userprofile_hidden' => 'no', 'userprofile_category' => 'no', 'userprofile_personal' => 'yes']);
        $this->assertNull($this->userSetting($this->userId, 'userprofile_hidden'));
        $this->assertNull($this->userSetting($this->userId, 'userprofile_category'));
        $this->assertSame('yes', $this->userSetting($this->userId, 'userprofile_personal'));
    }

    /**
     * The rights of display apply to the api, according to the role.
     */
    public function testJsonFollowsTheRightsOfDisplay(): void
    {
        $userSettings = $this->getServiceLocator()->get('Omeka\Settings\User');
        $userSettings->set('userprofile_hidden', 'hidden', $this->userId);
        $userSettings->set('userprofile_admin_note', 'note', $this->userId);
        $userSettings->set('userprofile_private', 'private', $this->userId);

        $json = $this->api()->read('users', $this->userId)->getContent()->jsonSerialize();
        $this->assertArrayNotHasKey('userprofile_hidden', $json['o:setting']);
        $this->assertSame('note', $json['o:setting']['userprofile_admin_note'] ?? null);
        $this->assertArrayNotHasKey('userprofile_private', $json['o:setting']);

        $this->loginAs($this->userId);
        $json = $this->api()->read('users', $this->userId)->getContent()->jsonSerialize();
        $this->assertArrayNotHasKey('userprofile_hidden', $json['o:setting']);
        $this->assertArrayNotHasKey('userprofile_admin_note', $json['o:setting']);
        $this->assertSame('private', $json['o:setting']['userprofile_private'] ?? null);
    }

    public function testJsonContainsTheSettings(): void
    {
        $this->updateSettings(['userprofile_text' => 'In json']);
        $json = $this->api()->read('users', $this->userId)->getContent()->jsonSerialize();
        $this->assertSame('In json', $json['o:setting']['userprofile_text'] ?? null);
    }
}
