<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Identity\WordPressAccountDirectory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbAccountPreparationRepository;
use WP_Error;

final class AccountPreparationWordPressTest extends PersistenceIntegrationTestCase
{
    private array $users = [];
    private array $mail = [];
    private bool $acceptMail = true;
    private \Closure $capture;

    protected function setUp(): void
    {
        parent::setUp();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $admin = get_user_by('login', 'biblio_integration_admin');
        wp_set_current_user($admin->ID);
        $this->capture = function ($result, array $message): bool {
            $to = (array) $message['to'];
            $personal = array_filter($to, static fn ($address) => str_ends_with($address, '@entry.example.invalid'));
            if ($personal !== []) {
                $user = get_user_by('email', reset($personal));
                self::assertInstanceOf(\WP_User::class, $user);
                self::assertNotNull((new WpdbAccountPreparationRepository($this->database, $this->tableNames))->find(new UserId((string) $user->ID))?->libraryId);
                if (!$this->acceptMail) { return false; }
                $this->mail[] = $message;
            }
            do_action('wp_mail_succeeded', $message);
            return true;
        };
        add_filter('pre_wp_mail', $this->capture, 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->capture, 10);
        $_POST = [];
        wp_set_current_user(0);
        parent::tearDown();
        foreach ($this->users as $id) { wp_delete_user($id); }
    }

    public function testNativeNewUserFormPreparesBeforeSinglePasswordNotification(): void
    {
        $id = $this->create('native-new');
        $target = new UserId((string) $id);
        $core = (new ProductionComposition($this->database))->application();
        $state = $core->accountPreparation()->diagnose($target);
        self::assertSame('ready', $state['state']);
        self::assertTrue($state['needs_name']);
        self::assertTrue($state['notified']);
        self::assertSame('Zichtbare testnaam', get_userdata($id)->display_name);
        self::assertCount(1, $this->mail);
        self::assertStringContainsString('action=rp', $this->mail[0]['message']);
        $core->accountPreparation()->prepare($target);
        wp_new_user_notification($id, null, 'user');
        self::assertCount(1, $this->mail);
        wp_set_current_user($id);
        self::assertInstanceOf(\WP_User::class, wp_authenticate('native-new', 'test-password-not-secret'));
        self::assertTrue($core->accountPreparation()->myStatus()['needs_name']);
        try { $core->libraryContexts()->myLibraries(); self::fail('Unnamed account bypassed Core gate.'); }
        catch (AuthorizationException) { self::assertTrue(true); }
        $core->accountPreparation()->saveName(new \Biblio\Core\Library\LibraryId($state['library_id']), 'Mijn Bibliotheek');
        self::assertCount(1, $core->libraryContexts()->myLibraries());
        self::assertFalse($core->accountPreparation()->myStatus()['needs_name']);
    }

    public function testMailFailureLeavesReadyAccountForNotificationRetry(): void
    {
        $this->acceptMail = false;
        $id = $this->create('native-mail-fail');
        $service = (new ProductionComposition($this->database))->application()->accountPreparation();
        $state = $service->diagnose(new UserId((string) $id));
        self::assertSame('ready', $state['state']);
        self::assertFalse($state['notified']);
        self::assertCount(1, $state['owned_libraries']);
        self::assertCount(0, $this->mail);
        $this->acceptMail = true;
        do_action('edit_user_created_user', $id, 'both');
        self::assertTrue($service->diagnose(new UserId((string) $id))['notified']);
        self::assertCount(1, $this->mail);
        self::assertSame($state['library_id'], $service->diagnose(new UserId((string) $id))['library_id']);
    }

    public function testFirstCoreWriteFailureBlocksAuthenticationAndResetUntilResume(): void
    {
        $table = $this->tableNames->accountPreparations();
        $this->database->query("CREATE TRIGGER biblio_test_prepare_first_fail BEFORE INSERT ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='first preparation write failed'");
        $old = $this->database->suppress_errors(true);
        try { $id = $this->create('native-pending'); }
        finally { $this->database->query('DROP TRIGGER biblio_test_prepare_first_fail'); $this->database->suppress_errors($old); }
        self::assertSame('1', get_user_meta($id, WordPressAccountDirectory::INTENT_KEY, true));
        self::assertInstanceOf(WP_Error::class, wp_authenticate('native-pending', 'test-password-not-secret'));
        self::assertInstanceOf(WP_Error::class, get_password_reset_key(get_userdata($id)));
        self::assertCount(0, $this->mail);
        $service = (new ProductionComposition($this->database))->application()->accountPreparation();
        $target = new UserId((string) $id);
        self::assertSame('pending', $service->diagnose($target)['state']);
        $library = $service->prepare($target);
        do_action('edit_user_created_user', $id, 'both');
        self::assertInstanceOf(\WP_User::class, wp_authenticate('native-pending', 'test-password-not-secret'));
        self::assertCount(1, $this->mail);
        self::assertSame($library->value(), $service->diagnose($target)['library_id']);
    }

    public function testInvalidNonceAndPrivilegedPersonalRoleAreRejectedBeforeUserCreation(): void
    {
        $_POST = ['biblio_account' => '1', '_wpnonce_create-user' => 'invalid'];
        $errors = new WP_Error();
        $user = (object) ['role' => 'subscriber'];
        do_action_ref_array('user_profile_update_errors', [&$errors, false, &$user]);
        self::assertTrue($errors->has_errors());
        $_POST['_wpnonce_create-user'] = wp_create_nonce('create-user');
        $user->role = 'administrator';
        $errors = new WP_Error();
        do_action_ref_array('user_profile_update_errors', [&$errors, false, &$user]);
        self::assertTrue($errors->has_errors());
    }

    public function testLegacyMissingPersonalLibraryCannotCreateOneThroughOrdinaryEnsure(): void
    {
        $id = wp_insert_user(['user_login' => 'legacy-no-library', 'user_pass' => 'test-only', 'role' => 'subscriber']);
        self::assertIsInt($id); $this->users[] = $id;
        wp_set_current_user($id);
        $core = (new ProductionComposition($this->database))->application();
        self::assertFalse($core->accountPreparation()->myStatus()['needs_name']);
        $this->expectException(AuthorizationException::class);
        $core->personalLibraries()->ensure();
    }

    public function testRestNamingRequiresAuthenticationAndExactOwnership(): void
    {
        $id = $this->create('native-rest-owner');
        $core = (new ProductionComposition($this->database))->application();
        $libraryId = $core->accountPreparation()->diagnose(new UserId((string) $id))['library_id'];
        $server = rest_get_server();
        $api = new \Biblio\Core\Infrastructure\WordPress\Rest\RestApi(static fn () => $core);
        $api->registerRoutes();
        $request = new \WP_REST_Request('POST', '/biblio/v1/libraries/' . $libraryId . '/name');
        $request->set_param('name', 'Gewijzigd via REST');
        wp_set_current_user(0);
        self::assertSame(401, $server->dispatch($request)->get_status());
        wp_set_current_user($id);
        $invalid = clone $request; $invalid->set_param('name', ' ');
        self::assertSame(422, $server->dispatch($invalid)->get_status());
        self::assertTrue($core->accountPreparation()->myStatus()['needs_name']);
        self::assertSame(200, $server->dispatch($request)->get_status());
        self::assertFalse($core->accountPreparation()->myStatus()['needs_name']);
        $other = wp_insert_user(['user_login' => 'rest-foreign', 'user_pass' => 'test-only', 'role' => 'subscriber']);
        self::assertIsInt($other); $this->users[] = $other; wp_set_current_user($other);
        self::assertSame(404, $server->dispatch($request)->get_status());
        wp_set_current_user($id);
        self::assertSame('Gewijzigd via REST', $core->accountPreparation()->myStatus()['name']);
    }

    public function testMailFailureRemainsVisibleAfterOrdinaryCheckAndCannotBeClearedByUrl(): void
    {
        $this->acceptMail = false;
        $id = $this->create('native-visible-mail-fail');
        $core = (new ProductionComposition($this->database))->application();
        $adapter = new \Biblio\Core\Infrastructure\WordPress\Identity\AccountPreparationAdapter(static fn () => $core);
        $originalGet = $_GET;
        try {
            $_GET = ['page' => 'biblio-accounts', 'user_id' => $id];
            ob_start(); $adapter->notice(); $notice = (string) ob_get_clean();
            self::assertStringContainsString('notice-error', $notice);
            self::assertStringContainsString('maak geen tweede account', $notice);
            ob_start(); $adapter->render(); $page = (string) ob_get_clean();
            self::assertStringContainsString('Wachtwoordmelding: nog niet verstuurd.', $page);
            $_GET['biblio_result'] = 'ready';
            ob_start(); $adapter->notice(); $notice = (string) ob_get_clean();
            self::assertStringContainsString('notice-error', $notice);
            self::assertStringNotContainsString('notice-success', $notice);
            self::assertFalse($core->accountPreparation()->diagnose(new UserId((string) $id))['notified']);
            self::assertCount(0, $this->mail);
        } finally { $_GET = $originalGet; }
    }

    public function testRepairedMailShowsCurrentStateEvenWithOldFailureUrl(): void
    {
        $this->acceptMail = false;
        $id = $this->create('native-visible-mail-retry');
        $core = (new ProductionComposition($this->database))->application();
        $target = new UserId((string) $id);
        $before = $core->accountPreparation()->diagnose($target);
        $this->acceptMail = true;
        do_action('edit_user_created_user', $id, 'both');
        $adapter = new \Biblio\Core\Infrastructure\WordPress\Identity\AccountPreparationAdapter(static fn () => $core);
        $originalGet = $_GET;
        try {
            $_GET = ['page' => 'biblio-accounts', 'user_id' => $id, 'biblio_result' => 'incomplete'];
            ob_start(); $adapter->notice(); $notice = (string) ob_get_clean();
            self::assertStringContainsString('notice-success', $notice);
            self::assertStringNotContainsString('notice-error', $notice);
            unset($_GET['biblio_result']);
            ob_start(); $adapter->notice(); $notice = (string) ob_get_clean();
            self::assertSame('', $notice);
            ob_start(); $adapter->render(); $page = (string) ob_get_clean();
            self::assertStringContainsString('Wachtwoordmelding: verstuurd.', $page);
            self::assertStringNotContainsString('nog niet verstuurd', $page);
            $after = $core->accountPreparation()->diagnose($target);
            self::assertSame($before['library_id'], $after['library_id']);
            self::assertCount(1, $after['owned_libraries']);
            self::assertTrue($after['notified']);
            self::assertCount(1, $this->mail);
        } finally { $_GET = $originalGet; }
    }

    public function testAccountNoticesRequireManagementAndStayOnAccountPage(): void
    {
        $id = $this->create('native-private-mail-status');
        $core = (new ProductionComposition($this->database))->application();
        $adapter = new \Biblio\Core\Infrastructure\WordPress\Identity\AccountPreparationAdapter(static fn () => $core);
        $originalGet = $_GET;
        try {
            $_GET = ['page' => 'other-page', 'user_id' => $id, 'biblio_result' => 'ready'];
            ob_start(); $adapter->notice(); self::assertSame('', ob_get_clean());
            $_GET['page'] = 'biblio-accounts';
            wp_set_current_user($id);
            ob_start(); $adapter->notice(); self::assertSame('', ob_get_clean());
        } finally { $_GET = $originalGet; }
    }

    private function create(string $login): int
    {
        $_POST = [
            'user_login' => $login, 'email' => $login . '@entry.example.invalid',
            'pass1' => 'test-password-not-secret', 'pass2' => 'test-password-not-secret',
            'role' => 'subscriber', 'send_user_notification' => '1',
            'biblio_account' => '1', 'biblio_display_name' => 'Zichtbare testnaam',
            '_wpnonce_create-user' => wp_create_nonce('create-user'),
        ];
        $id = edit_user();
        self::assertIsInt($id, $id instanceof WP_Error ? $id->get_error_message() : '');
        $this->users[] = $id;
        return $id;
    }
}
