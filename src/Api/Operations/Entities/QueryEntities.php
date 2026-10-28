<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Api\Error\NotFound;
use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Query\Condition;

/**
 * A page of a type's standalone entities, its subtypes' included, filtered
 * and sorted, as the caller may read them. A subtype the caller may not read
 * is left out; a path through a field it may not read is refused.
 */
#[Operation('coconut-cms:entities.query')]
final readonly class QueryEntities
{
    /** The most entities one page holds. */
    public const MAX_LIMIT = 100;

    public function run(QueryEntitiesInput $input, OperationContext $context): EntityPage {
        if ($input->limit < 1 || $input->limit > self::MAX_LIMIT) {
            throw InvalidInput::at('limit', sprintf('Must be from 1 to %d.', self::MAX_LIMIT));
        }

        if (!$context->session->schemas()->has($input->type)) {
            throw new NotFound('type', $input->type);
        }

        $query = $context->session->queryType($input->type)->limit($input->limit)->after($input->after);
        foreach ($input->where as $index => $condition) {
            $wrong = $condition->operator->wrongCount(count($condition->values));
            if ($wrong !== null) {
                throw InvalidInput::at(sprintf('where.%d.values', $index), $wrong);
            }

            $query = $query->where($condition->path, Condition::ofStored($condition->operator->operator(), $condition->values));
        }

        foreach ($input->orderBy as $sort) {
            $query = $query->orderBy($sort->path, $sort->direction->order());
        }

        try {
            $page = $query->idPage();
        } catch (InvalidArgumentException $invalid) {
            // A path, a value or a cursor the query refused: its message names which.
            throw InvalidInput::at('', $invalid->getMessage());
        }

        $items = [];
        foreach ($page->ids as $id) {
            // One deleted since the page was found is skipped.
            $record = $context->session->read($id);
            if ($record !== null) {
                $items[] = EntityData::of($record);
            }
        }

        return new EntityPage($items, $page->nextCursor);
    }
}
