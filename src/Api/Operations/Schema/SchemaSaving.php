<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Error\SchemaSaveRefused;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Migration\BreakResolution;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\BreaksNotAccepted;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EntriesFailToMove;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationBlocked;

/**
 * What schema.preview and schema.save share: reading the input's
 * operations, and turning a refused save into SchemaSaveRefused.
 */
final readonly class SchemaSaving
{
    private OperationForm $form;

    public function __construct(
        private OperationContext $context,
    ) {
        $persistence = $context->persistence;
        $this->form = new OperationForm(new FieldForm($persistence->enums(), $persistence->valueObjects()), $persistence->conversions());
    }

    /** $input's save, its operations read. Throws InvalidInput naming the path at fault. */
    public function saveOf(SchemaSaveInput $input): EditorSave {
        return new EditorSave($input->revision, $this->form->readAll($input->operations, 'operations'), $input->acceptBreaks);
    }

    /** A preview of $input, as the caller. */
    public function preview(SchemaSaveInput $input): SchemaReport {
        return SchemaReport::of($this->context->persistence->previewSchemas($this->saveOf($input), $this->context->actor), $this->form);
    }

    /** $input saved, as the caller. Throws SchemaSaveRefused when the save is refused before it changes anything. */
    public function save(SchemaSaveInput $input): SchemaReport {
        $save = $this->saveOf($input);
        try {
            return SchemaReport::of($this->context->persistence->saveSchemas($save, $this->context->actor), $this->form);
        } catch (MigrationBlocked $blocked) {
            throw new SchemaSaveRefused($blocked->getMessage(), ['reason' => 'blocked', 'blocked' => array_map(static fn (Difference $each): string => $each->describe(), $blocked->differences)]);
        } catch (BreaksNotAccepted $refused) {
            throw new SchemaSaveRefused($refused->getMessage(), ['reason' => 'breaks_not_accepted', 'breaks' => array_map(static fn (BreakResolution $each): array => (array) SchemaBreak::of($each), $refused->breaks)]);
        } catch (EntriesFailToMove $refused) {
            throw new SchemaSaveRefused($refused->getMessage(), ['reason' => 'entries_fail', 'failures' => $refused->failures]);
        }
    }
}
