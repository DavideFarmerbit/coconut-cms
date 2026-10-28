<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenFieldReason;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestor;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestorReason;

/**
 * An editor-created type whose chain is cut, set aside: each field the levels
 * cut off gave it is set aside (SetAsideField), it takes the nearest ancestor
 * left that allows it as its parent, or none, and a lost-ancestor record is
 * kept for each level cut off, fields or not. What a looser policy does with
 * a cut chain.
 */
final readonly class CutChain implements Fixup, MovesValues
{
    /** @var list<SetAsideField> */
    private array $lostFields;

    /** @var list<LostAncestor> */
    private array $records;

    /**
     * @param string|null $newParent The ancestor it falls back to; null for none.
     * @param list<array{string, LostAncestorReason}> $lost Each ancestor cut off, nearest first, and why.
     * @param list<array{FieldName, string}> $lostFields Each field it loses, with the ancestor declaring it.
     */
    public function __construct(
        public string $type,
        public ?string $newParent,
        array $lost,
        array $lostFields,
    ) {
        $this->lostFields = array_map(
            fn (array $field): SetAsideField => new SetAsideField($type, (string) $field[0], BrokenFieldReason::ChainCut, $field[1]),
            $lostFields,
        );
        $this->records = array_map(
            static fn (array $ancestor): LostAncestor => new LostAncestor(EntityIds::generate(), $ancestor[0], $ancestor[1], $newParent),
            $lost,
        );
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        // The fields are set aside while the chain still gives them, so each record knows what it was.
        foreach ($this->lostFields as $field) {
            $schemas = $field->applyTo($schemas);
        }

        $schema = $schemas->get($this->type)->withParent($this->newParent);
        foreach ($this->records as $record) {
            $schema = $schema->withNoTypeRecord($record);
        }

        return $schemas->with($schema);
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach ($this->lostFields as $field) {
            $field->moveValues($values, $before, $after);
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('CutChain(%s → %s)', $this->type, $this->newParent ?? 'no parent');
    }
}
