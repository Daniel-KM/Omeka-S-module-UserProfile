<?php declare(strict_types=1);

namespace UserProfileTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Display and edition of the profile in the admin user pages.
 */
class AdminViewTest extends AbstractHttpControllerTestCase
{
    use UserProfileTestTrait;

    protected $userId;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        $this->setConfig();
        $this->userId = $this->createUser('userprofile-view-' . uniqid() . '@example.org');
        $userSettings = $this->getServiceLocator()->get('Omeka\Settings\User');
        $userSettings->set('userprofile_text', 'Displayed text', $this->userId);
        $userSettings->set('userprofile_hidden', 'Hidden text', $this->userId);
    }

    public function tearDown(): void
    {
        $this->cleanupUsers();
        $this->resetConfig();
        parent::tearDown();
    }

    public function testShowPageDisplaysTheProfile(): void
    {
        $this->dispatch('/admin/user/' . $this->userId);
        $this->assertResponseStatusCode(200);
        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('Text field', $body);
        $this->assertStringContainsString('Displayed text', $body);
        $this->assertStringNotContainsString('Hidden text', $body);
    }

    public function testEditFormContainsTheFields(): void
    {
        $this->dispatch('/admin/user/' . $this->userId . '/edit');
        $this->assertResponseStatusCode(200);
        // The attributes are escaped.
        $body = html_entity_decode($this->getResponse()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertMatchesRegularExpression('~name="user-settings\[userprofile_text\]"[^>]*value="Displayed text"~', $body);
        $this->assertStringContainsString('name="user-settings[userprofile_select]"', $body);
        $this->assertStringNotContainsString('name="user-settings[userprofile_hidden]"', $body);
        // An admin cannot edit the personal settings.
        $this->assertStringContainsString('name="user-settings[userprofile_category]"', $body);
        $this->assertStringNotContainsString('name="user-settings[userprofile_personal]"', $body);
    }

    /**
     * An optional select may be left empty, even without an empty option, like
     * the selects of the modules CustomVocab, Table and Thesaurus.
     */
    public function testOptionalSelectWithoutEmptyOptionIsNotRequired(): void
    {
        $this->setConfig(<<<'INI'
            elements.userprofile_multiple.name = "userprofile_multiple"
            elements.userprofile_multiple.type = "Laminas\Form\Element\Select"
            elements.userprofile_multiple.options.value_options.alpha = "Alpha"
            elements.userprofile_multiple.attributes.multiple = "1"
            INI);
        $this->dispatch('/admin/user/' . $this->userId . '/edit');
        $this->assertResponseStatusCode(200);
        $form = $this->getApplication()->getMvcEvent()->getResult()->getVariable('form');
        $input = $form->getInputFilter()->get('user-settings')->get('userprofile_multiple');
        $this->assertFalse($input->isRequired());
    }

    /**
     * In the admin interface, a user who is not an admin has the rights of a
     * user.
     */
    public function testEditFormForUserWithoutAdminRole(): void
    {
        $this->loginAs($this->userId);
        $this->dispatch('/admin/user/' . $this->userId . '/edit');
        $this->assertResponseStatusCode(200);
        $body = html_entity_decode($this->getResponse()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('name="user-settings[userprofile_personal]"', $body);
        $this->assertStringNotContainsString('name="user-settings[userprofile_category]"', $body);
    }
}
