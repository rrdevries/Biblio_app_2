<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Catalog\AcquisitionMethod;
use Biblio\Core\Catalog\DustJacketState;
use Biblio\Core\Catalog\Iso4217Currency;
use Biblio\Core\Catalog\ItemAcquisitionDate;
use Biblio\Core\Catalog\ItemCondition;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemLocalDetails;
use Biblio\Core\Catalog\ItemLocalDetailsStale;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Catalog\ItemLocalDetailsVersion;
use Biblio\Core\Catalog\PaidAmount;
use Biblio\Core\Catalog\WritableItemLocalDetailsRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\TransactionException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbItemLocalDetailsRepository implements WritableItemLocalDetailsRepository
{
    private WpdbTransactionConnection $connection;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->connection = new WpdbTransactionConnection($database);
    }

    public function find(LibraryId $libraryId, ItemId $itemId): ?ItemLocalDetails
    {
        return $this->findOne($libraryId, $itemId, false);
    }

    public function findForUpdate(LibraryId $libraryId, ItemId $itemId): ?ItemLocalDetails
    {
        $this->assertTransactionActive();
        return $this->findOne($libraryId, $itemId, true);
    }

    /**
     * @param list<ItemId> $itemIds
     * @return array<string, ItemLocalDetails|null>
     */
    public function findMany(LibraryId $libraryId, array $itemIds): array
    {
        $result = [];
        foreach ($itemIds as $itemId) {
            $result[$itemId->value()] = null;
        }
        if ($itemIds === []) {
            return $result;
        }

        $table = $this->tables->itemLocalDetails();
        $placeholders = implode(",", array_fill(0, count($itemIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT {$this->columns()} FROM `{$table}` WHERE library_id=%s "
                . "AND item_id IN ({$placeholders}) ORDER BY item_id",
            $libraryId->value(),
            ...array_map(static fn (ItemId $id): string => $id->value(), $itemIds)
        ));

        foreach ($rows as $row) {
            $details = $this->hydrate($row);
            $result[$details->itemId()->value()] = $details;
        }
        return $result;
    }

    public function add(ItemLocalDetails $details): void
    {
        $this->assertTransactionActive();
        $previous = $this->database->suppress_errors(true);
        try {
            $result = $this->database->insert(
                $this->tables->itemLocalDetails(),
                $this->fields($details),
                ["%s", "%s", "%s", "%d", "%d", "%d", "%s", "%s", "%s", "%s", "%d", "%s", "%s", "%s", "%d", "%s", "%s", "%d"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }

        if ($result === 1) {
            return;
        }
        if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
            throw new ItemLocalDetailsStale();
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist Item-local details.",
            $this->database->last_error
        );
    }

    public function replaceIfVersionMatches(
        ItemLocalDetails $replacement,
        ItemLocalDetailsVersion $expectedVersion
    ): bool {
        $this->assertTransactionActive();
        if ($replacement->version()->value() !== $expectedVersion->value() + 1) {
            throw new PersistenceException(
                "Item-local details replacement must increment version once.",
                failureReason: FailureReason::PersistenceWriteFailed
            );
        }

        $fields = $this->fields($replacement);
        unset($fields["library_id"], $fields["item_id"]);
        $result = $this->database->update(
            $this->tables->itemLocalDetails(),
            $fields,
            [
                "library_id" => $replacement->libraryId()->value(),
                "item_id" => $replacement->itemId()->value(),
                "details_version" => $expectedVersion->value(),
            ],
            ["%s", "%d", "%d", "%d", "%s", "%s", "%s", "%s", "%d", "%s", "%s", "%s", "%d", "%s", "%s", "%d"],
            ["%s", "%s", "%d"]
        );
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update Item-local details.",
                $this->database->last_error
            );
        }
        return $result === 1;
    }

    private function findOne(
        LibraryId $libraryId,
        ItemId $itemId,
        bool $forUpdate
    ): ?ItemLocalDetails {
        $table = $this->tables->itemLocalDetails();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT {$this->columns()} FROM `{$table}` WHERE library_id=%s AND item_id=%s"
                . ($forUpdate ? " FOR UPDATE" : ""),
            $libraryId->value(),
            $itemId->value()
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string, mixed> */
    private function fields(ItemLocalDetails $details): array
    {
        $state = $details->state();
        return [
            "library_id" => $details->libraryId()->value(),
            "item_id" => $details->itemId()->value(),
            "condition_code" => $state->condition()?->value,
            "acquisition_year" => $state->inLibrarySince()?->year(),
            "acquisition_month" => $state->inLibrarySince()?->month(),
            "acquisition_day" => $state->inLibrarySince()?->day(),
            "acquisition_method" => $state->acquisitionMethod()?->value,
            "acquired_via" => $state->acquiredVia(),
            "paid_amount" => $state->paidAmount()?->decimal(),
            "paid_currency" => $state->paidAmount()?->currency()->value(),
            "signed" => $state->signed() === null ? null : (int) $state->signed(),
            "signed_by" => $state->signedBy(),
            "copy_limitation" => $state->copyLimitation(),
            "dust_jacket" => $state->dustJacket()?->value,
            "inscription" => $state->inscription() === null ? null : (int) $state->inscription(),
            "provenance" => $state->provenance(),
            "completeness" => $state->completeness(),
            "details_version" => $details->version()->value(),
        ];
    }

    private function columns(): string
    {
        return "library_id,item_id,condition_code,acquisition_year,acquisition_month,"
            . "acquisition_day,acquisition_method,acquired_via,paid_amount,paid_currency,"
            . "signed,signed_by,copy_limitation,dust_jacket,inscription,provenance,"
            . "completeness,details_version";
    }

    private function hydrate(object $row): ItemLocalDetails
    {
        try {
            $date = $row->acquisition_year === null ? null : new ItemAcquisitionDate(
                (int) $row->acquisition_year,
                $row->acquisition_month === null ? null : (int) $row->acquisition_month,
                $row->acquisition_day === null ? null : (int) $row->acquisition_day
            );
            $amount = $row->paid_amount === null ? null : new PaidAmount(
                (string) $row->paid_amount,
                new Iso4217Currency((string) $row->paid_currency)
            );
            return new ItemLocalDetails(
                new LibraryId((string) $row->library_id),
                new ItemId((string) $row->item_id),
                new ItemLocalDetailsState(
                    $row->condition_code === null ? null : ItemCondition::from((string) $row->condition_code),
                    $date,
                    $row->acquisition_method === null ? null : AcquisitionMethod::from((string) $row->acquisition_method),
                    $row->acquired_via === null ? null : (string) $row->acquired_via,
                    $amount,
                    $row->signed === null ? null : (bool) $row->signed,
                    $row->signed_by === null ? null : (string) $row->signed_by,
                    $row->copy_limitation === null ? null : (string) $row->copy_limitation,
                    $row->dust_jacket === null ? null : DustJacketState::from((string) $row->dust_jacket),
                    $row->inscription === null ? null : (bool) $row->inscription,
                    $row->provenance === null ? null : (string) $row->provenance,
                    $row->completeness === null ? null : (string) $row->completeness
                ),
                new ItemLocalDetailsVersion((int) $row->details_version)
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Item-local details are invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function assertTransactionActive(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new TransactionException(
                "Item-local details mutation requires an active transaction.",
                FailureReason::TransactionBeginFailed
            );
        }
    }
}
