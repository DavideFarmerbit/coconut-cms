<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures;

use XyloIsCoding\CoconutCms\Storage\Embed;
use XyloIsCoding\CoconutCms\Storage\Field;
use XyloIsCoding\CoconutCms\Storage\Validator\MaxLengthValidator;
use XyloIsCoding\CoconutCms\Storage\Validator\RequiredValidator;

/** A Phase 1 entity fixture: queryable + unique + blob-only scalars, plus an embed. */
final readonly class Product
{
    public function __construct(
        #[Field(queryable: true, unique: true, validators: [new RequiredValidator()])]
        public string $sku,
        #[Field(queryable: true, validators: [new MaxLengthValidator(50)])]
        public string $name,
        #[Field]
        public string $description,
        #[Field]
        #[Embed]
        public Address $address,
        #[Field(queryable: true)]
        public bool $active,
    ) {
    }
}
