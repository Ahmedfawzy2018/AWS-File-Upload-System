<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use Aws\Signature\S3SignatureV4;

/**
 * SigV4 signer for presigned URLs that keeps Content-Type, Content-Length and If-None-Match
 * in the signed headers. The SDK drops these from presigned signatures by default, which would
 * let a client change the type or size, or skip the conditional write, without invalidating the URL.
 */
final class HeaderBindingSignatureV4 extends S3SignatureV4
{
    /** @return array<string, bool> */
    protected function getHeaderBlacklist()
    {
        return array_diff_key(parent::getHeaderBlacklist(), ['content-length' => true, 'if-none-match' => true]);
    }

    /** @return array<string, bool> */
    protected function getPresignHeaderDenyList()
    {
        return array_diff_key(parent::getPresignHeaderDenyList(), ['content-type' => true]);
    }
}
