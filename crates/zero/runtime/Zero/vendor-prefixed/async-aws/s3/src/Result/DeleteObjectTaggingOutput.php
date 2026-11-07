<?php

namespace Zero\Vendor\AsyncAws\S3\Result;

use Zero\Vendor\AsyncAws\Core\Response;
use Zero\Vendor\AsyncAws\Core\Result;

class DeleteObjectTaggingOutput extends Result
{
    /**
     * The versionId of the object the tag-set was removed from.
     *
     * @var string|null
     */
    private $versionId;

    public function getVersionId(): ?string
    {
        $this->initialize();

        return $this->versionId;
    }

    protected function populateResult(Response $response): void
    {
        $headers = $response->getHeaders();

        $this->versionId = $headers['x-amz-version-id'][0] ?? null;
    }
}
