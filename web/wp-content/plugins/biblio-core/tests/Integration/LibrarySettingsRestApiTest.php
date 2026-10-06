<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class LibrarySettingsRestApiTest extends PersistenceIntegrationTestCase
{
    private int $actorId;
    private int $otherId;
    private WP_REST_Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actorId = $this->createUser("actor");
        $this->otherId = $this->createUser("other");
        $this->server = new WP_REST_Server();

        global $wp_rest_server;
        $wp_rest_server = $this->server;

        $api = new RestApi(static fn () =>
            (new ProductionComposition($GLOBALS["wpdb"]))->application());
        $previousHook = $GLOBALS["wp_filter"]["rest_api_init"] ?? null;
        unset($GLOBALS["wp_filter"]["rest_api_init"]);

        try {
            $api->boot();
            do_action("rest_api_init");
        } finally {
            unset($GLOBALS["wp_filter"]["rest_api_init"]);

            if ($previousHook !== null) {
                $GLOBALS["wp_filter"]["rest_api_init"] = $previousHook;
            }
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        unset($_SERVER["HTTP_X_WP_NONCE"], $GLOBALS["wp_rest_auth_cookie"]);

        require_once ABSPATH . "wp-admin/includes/user.php";
        wp_delete_user($this->actorId);
        wp_delete_user($this->otherId);
        unset($GLOBALS["wp_rest_server"]);

        parent::tearDown();
    }

    public function testPreferencesAreOwnedLibraryScopedVersionedAndPrivate(): void
    {
        $path='/biblio/v1/libraries/settings-a/preferences';
        self::assertSame(401,$this->server->dispatch($this->request('GET',$path))->get_status());
        $this->seedLibrary('settings-a','Instellingen A',$this->actorId);
        $this->seedLibrary('settings-b','Instellingen B',$this->actorId);
        $initial=$this->dispatchAs($this->actorId,$this->request('GET',$path));
        self::assertSame('private, no-store',$initial->get_headers()['Cache-Control']);
        $data=$this->data($initial);self::assertSame('grid',$data['preferences']['catalog_view']['effective']);
        $change=['setting'=>'catalog_view','operation'=>'set','value'=>'list','expected_version'=>0];
        $saved=$this->data($this->dispatchAs($this->actorId,$this->jsonRequest('PATCH',$path,$change)));
        self::assertSame('list',$saved['preferences']['catalog_view']['effective']);
        self::assertSame(409,$this->dispatchAs($this->actorId,$this->jsonRequest('PATCH',$path,$change))->get_status());
        $other=$this->data($this->dispatchAs($this->actorId,$this->request('GET','/biblio/v1/libraries/settings-b/preferences')));
        self::assertNull($other['preferences']['catalog_view']['value']);
        self::assertSame(404,$this->dispatchAs($this->otherId,$this->request('GET',$path))->get_status());
        foreach ([$change+['user_id'=>(string)$this->otherId],array_replace($change,['value'=>true]),array_replace($change,['setting'=>'theme'])] as $invalid) {
            self::assertSame(422,$this->dispatchAs($this->actorId,$this->jsonRequest('PATCH',$path,$invalid))->get_status());
        }
        $request=$this->request('GET',$path);$request->set_query_params(['user_id'=>$this->otherId]);
        self::assertSame(422,$this->dispatchAs($this->actorId,$request)->get_status());
        $reset=['setting'=>'catalog_view','operation'=>'reset','expected_version'=>1];
        $cleared=$this->data($this->dispatchAs($this->actorId,$this->jsonRequest('PATCH',$path,$reset)));
        self::assertNull($cleared['preferences']['catalog_view']['value']);
        self::assertSame(2,$cleared['preferences']['catalog_view']['version']);
    }

    public function testDefaultPermissionDoesNotFollowWordPressOrCatalogRolesAndPreferencesSurviveAccessLoss(): void
    {
        $this->seedLibrary('settings-a','Instellingen A',$this->actorId);
        $member=['library_id'=>'settings-a','user_id'=>(string)$this->otherId,'membership_status'=>'active','management_role'=>'manager','use_access'=>'view_only','additional_permissions'=>'["catalog.manage"]'];
        self::assertSame(1,$this->database->insert($this->tableNames->memberships(),$member));
        $path='/biblio/v1/libraries/settings-a/defaults';
        $personal='/biblio/v1/libraries/settings-a/preferences';
        (new \WP_User($this->otherId))->set_role('administrator');
        self::assertSame(404,$this->dispatchAs($this->otherId,$this->request('GET',$path))->get_status());
        $own=['setting'=>'catalog_view','operation'=>'set','value'=>'grid','expected_version'=>0];
        self::assertSame(200,$this->dispatchAs($this->otherId,$this->jsonRequest('PATCH',$personal,$own))->get_status());
        self::assertSame(1,$this->database->update($this->tableNames->memberships(),['additional_permissions'=>'["library.defaults_manage"]'],['library_id'=>'settings-a','user_id'=>(string)$this->otherId]));
        $shared=$this->data($this->dispatchAs($this->otherId,$this->jsonRequest('PATCH',$path,array_replace($own,['value'=>'list']))));
        self::assertSame('library',$shared['defaults']['catalog_view']['source']);
        self::assertSame('list',$this->data($this->dispatchAs($this->actorId,$this->request('GET',$personal)))['preferences']['catalog_view']['effective']);
        self::assertSame('grid',$this->data($this->dispatchAs($this->otherId,$this->request('GET',$personal)))['preferences']['catalog_view']['effective']);
        self::assertSame(1,$this->database->update($this->tableNames->memberships(),['membership_status'=>'inactive'],['library_id'=>'settings-a','user_id'=>(string)$this->otherId]));
        self::assertSame(404,$this->dispatchAs($this->otherId,$this->request('GET',$personal))->get_status());
        self::assertSame(404,$this->dispatchAs($this->otherId,$this->jsonRequest('PATCH',$path,array_replace($own,['value'=>'grid','expected_version'=>1])))->get_status());
        self::assertSame(1,$this->database->update($this->tableNames->memberships(),['membership_status'=>'active'],['library_id'=>'settings-a','user_id'=>(string)$this->otherId]));
        self::assertSame('grid',$this->data($this->dispatchAs($this->otherId,$this->request('GET',$personal)))['preferences']['catalog_view']['value']);
    }

    public function testLibraryNavigationProjectsOnlyCurrentDefaultManagementPermission(): void
    {
        $this->seedLibrary('settings-a','Instellingen A',$this->actorId);
        $path='/biblio/v1/me/libraries';
        $owner=$this->data($this->dispatchAs($this->actorId,$this->request('GET',$path)));
        self::assertTrue($owner['libraries'][0]['capabilities']['manage_defaults']);
        self::assertSame(1,$this->database->insert($this->tableNames->memberships(),[
            'library_id'=>'settings-a','user_id'=>(string)$this->otherId,
            'membership_status'=>'active','management_role'=>'manager','use_access'=>'view_only',
            'additional_permissions'=>'["catalog.manage"]']));
        (new \WP_User($this->otherId))->set_role('administrator');
        $other=$this->data($this->dispatchAs($this->otherId,$this->request('GET',$path)));
        self::assertFalse($other['libraries'][0]['capabilities']['manage_defaults']);
        self::assertSame(1,$this->database->update($this->tableNames->memberships(),
            ['additional_permissions'=>'["library.defaults_manage"]'],
            ['library_id'=>'settings-a','user_id'=>(string)$this->otherId]));
        $other=$this->data($this->dispatchAs($this->otherId,$this->request('GET',$path)));
        self::assertTrue($other['libraries'][0]['capabilities']['manage_defaults']);
        self::assertSame(1,$this->database->update($this->tableNames->memberships(),
            ['additional_permissions'=>'[]'],['library_id'=>'settings-a','user_id'=>(string)$this->otherId]));
        $other=$this->data($this->dispatchAs($this->otherId,$this->request('GET',$path)));
        self::assertFalse($other['libraries'][0]['capabilities']['manage_defaults']);
        self::assertSame(404,$this->dispatchAs($this->otherId,
            $this->request('GET','/biblio/v1/libraries/settings-a/defaults'))->get_status());
    }

    private function request(string $method, string $path): WP_REST_Request
    {
        return new WP_REST_Request($method, $path);
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(
        string $method,
        string $path,
        array $body
    ): WP_REST_Request {
        $request = $this->request($method, $path);
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode($body));

        return $request;
    }

    /** @param array<string, mixed> $query */
    private function queryRequest(string $path, array $query): WP_REST_Request
    {
        $request = $this->request("GET", $path);
        $request->set_query_params($query);

        return $request;
    }

    private function dispatchAs(
        int $userId,
        WP_REST_Request $request
    ): WP_REST_Response {
        wp_set_current_user($userId);
        $GLOBALS["wp_rest_auth_cookie"] = true;
        $_SERVER["HTTP_X_WP_NONCE"] = wp_create_nonce("wp_rest");
        $authentication = rest_cookie_check_errors(null);

        if (is_wp_error($authentication)) {
            return rest_convert_error_to_response($authentication);
        }

        return $this->server->dispatch($request);
    }

    /** @return array<string, mixed> */
    private function data(WP_REST_Response $response): array
    {
        self::assertContains($response->get_status(), [200, 201]);
        $payload = $response->get_data();
        self::assertIsArray($payload);
        self::assertIsArray($payload["data"] ?? null);

        return $payload["data"];
    }

    private function createUser(string $role): int
    {
        $suffix = bin2hex(random_bytes(6));
        $id = wp_create_user(
            "biblio-c7-{$role}-{$suffix}",
            bin2hex(random_bytes(12)),
            "{$role}-{$suffix}@example.test"
        );

        if ($id instanceof WP_Error) {
            throw new RuntimeException($id->get_error_message());
        }

        return $id;
    }

    private function seedLibrary(
        string $libraryId,
        string $name,
        int $userId,
        string $access = "direct"
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraries(),
            [
                "library_id" => $libraryId,
                "library_name" => $name,
                "library_type" => "private_library",
                "library_status" => "active",
            ]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->memberships(),
            [
                "library_id" => $libraryId,
                "user_id" => (string) $userId,
                "membership_status" => "active",
                "management_role" => "owner",
                "use_access" => $access,
                "additional_permissions" => "[]",
            ]
        ), $this->database->last_error);
    }

}
