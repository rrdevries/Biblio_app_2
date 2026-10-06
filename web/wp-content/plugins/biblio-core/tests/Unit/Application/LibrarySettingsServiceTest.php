<?php

declare(strict_types=1);
namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Library\{ActorLibraryContext,ActorLibraryContextRepository,LibraryContextQueryService};
use Biblio\Core\Application\Settings\LibrarySettingsService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Exception\{AuthorizationException,ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{AdditionalPermissions,Library,LibraryId,LibraryMembership,LibraryMembershipAssignment,LibraryName,ManagementRole,MembershipStatus,UseAccess};
use Biblio\Core\Settings\{SettingState,SettingsRepository,SettingStale};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\TestCase;

final class SettingsMemoryRepository implements SettingsRepository
{
    public array $rows = [];
    public function read(LibraryId $library, ?UserId $owner, string $setting): SettingState
    { return $this->rows[$library->value().':'.($owner?->value() ?? 'shared').':'.$setting] ?? new SettingState(null, 0); }
    public function save(LibraryId $library, ?UserId $owner, string $setting, string|bool|null $value, int $expectedVersion): void
    {
        $current = $this->read($library, $owner, $setting);
        if ($current->version !== $expectedVersion) { throw new SettingStale(); }
        $this->rows[$library->value().':'.($owner?->value() ?? 'shared').':'.$setting] = new SettingState($value, $expectedVersion + 1);
    }
}
final class SettingsContextRepository implements ActorLibraryContextRepository
{
    public ManagementRole $role = ManagementRole::Owner;
    public MembershipStatus $status = MembershipStatus::Active;
    public array $permissions = [];
    public function findForActor(LibraryId $id, UserId $actor): ?ActorLibraryContext
    {
        if (!in_array($id->value(), ['a','b'], true) || $actor->value() === 'outsider') return null;
        return new ActorLibraryContext(Library::privateLibrary($id, new LibraryName('Bibliotheek '.$id->value())),
            new LibraryMembershipAssignment($id,$actor,new LibraryMembership($this->role,$this->role === ManagementRole::Owner ? UseAccess::Direct : UseAccess::ViewOnly,$this->status,AdditionalPermissions::fromValues(...$this->permissions))), false);
    }
    public function listForActor(UserId $actor): array { return []; }
}
final class LibrarySettingsServiceTest extends TestCase
{
    private SettingsMemoryRepository $rows;
    private SettingsContextRepository $contexts;
    private ControllableAuthenticatedUser $actor;
    private LibrarySettingsService $service;
    protected function setUp(): void
    {
        $this->rows=new SettingsMemoryRepository(); $this->contexts=new SettingsContextRepository();
        $this->actor=new ControllableAuthenticatedUser(new UserId('one'));
        $this->service=new LibrarySettingsService($this->actor,new LibraryContextQueryService($this->actor,$this->contexts,new LibraryAuthorizationPolicy()),$this->rows);
    }
    public function testInheritanceExplicitEqualChoiceAndResetPreserveSemantics(): void
    {
        $id=new LibraryId('a');
        self::assertSame(['value'=>null,'version'=>0,'effective'=>'grid','source'=>'biblio','default_version'=>0,'default_effective'=>'grid','default_source'=>'biblio'],$this->service->preferences($id)['preferences']['catalog_view']);
        $this->service->changeDefault($id,'catalog_view','list',0);
        self::assertSame('library',$this->service->preferences($id)['preferences']['catalog_view']['source']);
        $this->service->changePersonal($id,'catalog_view','list',0);
        $this->service->changeDefault($id,'catalog_view','grid',1);
        self::assertSame('list',$this->service->preferences($id)['preferences']['catalog_view']['effective']);
        $this->service->changePersonal($id,'catalog_view',null,1);
        self::assertSame('grid',$this->service->preferences($id)['preferences']['catalog_view']['effective']);
        $this->service->changeDefault($id,'catalog_view',null,2);
        self::assertSame('biblio',$this->service->preferences($id)['preferences']['catalog_view']['source']);
    }
    public function testArchiveFalseIsExplicitAndUserLibraryScopesAreIndependent(): void
    {
        $id=new LibraryId('a');$this->service->changePersonal($id,'catalog_archive_visible',false,0);
        self::assertSame('personal',$this->service->preferences($id)['preferences']['catalog_archive_visible']['source']);
        $this->actor->authenticateAs(new UserId('two'));
        self::assertSame('biblio',$this->service->preferences($id)['preferences']['catalog_archive_visible']['source']);
        $this->actor->authenticateAs(new UserId('one'));
        self::assertSame('biblio',$this->service->preferences(new LibraryId('b'))['preferences']['catalog_archive_visible']['source']);
        $this->service->changePersonal($id,'catalog_archive_visible',null,1);
        self::assertFalse($this->service->preferences($id)['preferences']['catalog_archive_visible']['effective']);
    }
    public function testManagerNeedsExactPermissionAndAccessIsRechecked(): void
    {
        $id=new LibraryId('a');$this->contexts->role=ManagementRole::Manager;$this->contexts->permissions=['collections','catalog.classification_manage'];
        self::assertFalse($this->service->preferences($id)['capabilities']['manage_defaults']);
        try {$this->service->changeDefault($id,'catalog_view','list',0);self::fail('Unrelated rights must not authorize defaults.');} catch (AuthorizationException) {}
        $this->contexts->permissions=['library.defaults_manage'];$this->service->changeDefault($id,'catalog_view','list',0);
        $this->contexts->status=MembershipStatus::Inactive;
        try {$this->service->preferences($id);self::fail('Inactive membership must deny reads.');} catch (AuthorizationException) {}
        $this->contexts->status=MembershipStatus::Active;
        self::assertSame('list',$this->service->preferences($id)['preferences']['catalog_view']['effective']);
    }
    public function testStaleWriteAfterResetCannotOverwriteNewChoice(): void
    {
        $id=new LibraryId('a');$this->service->changePersonal($id,'catalog_view','list',0);
        $this->service->changePersonal($id,'catalog_view',null,1);
        $this->service->changePersonal($id,'catalog_view','grid',2);
        $this->expectException(SettingStale::class);$this->service->changePersonal($id,'catalog_view','list',1);
    }
    public function testInvalidTypeAndSharedArchiveAreRejected(): void
    {
        try {$this->service->changePersonal(new LibraryId('a'),'catalog_archive_visible','false',0);self::fail('Boolean required.');} catch (ValidationException) {}
        $this->expectException(ValidationException::class);$this->service->changeDefault(new LibraryId('a'),'catalog_archive_visible',true,0);
    }
}
