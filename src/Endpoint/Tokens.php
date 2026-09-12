<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Result\TokenVerification;

/**
 * The credential itself: does it work, and is it still usable.
 *
 * https://developers.cloudflare.com/api/resources/user/subresources/tokens/methods/verify/
 *
 * NEEDS NO PERMISSION. That is what makes it the right endpoint for a token check - it
 * succeeds for any token that is valid at all, so a failure here means the credential, and
 * never the credential's scope.
 *
 * It is also the only endpoint in this package that is not about DNS, and it earns its place
 * for a reason particular to this API: Cloudflare reports a token's permissions nowhere, so
 * "is this configured correctly" cannot be answered in one call the way it can elsewhere.
 * This answers the half that can be - the credential is real and live - and the other half is
 * answered by trying, which is what the Accounts and Zones endpoints degrade gracefully for.
 */
final class Tokens extends Endpoint
{
    /**
     * Check the credential.
     *
     * Raises NotAuthenticatedException when the token is not valid - see TokenVerification
     * for why that is a throw rather than a flag on the returned object.
     */
    public function verify(): TokenVerification
    {
        $response = $this->apiGet('user/tokens/verify');
        $verification = TokenVerification::fromArray($response->object(), $response->meta);

        $this->logger->info('Cloudflare token verified', [
            'token_id' => $verification->id,
            'status' => $verification->status?->value,
            'expires_on' => $verification->expiresOn?->format(\DateTimeInterface::ATOM),
        ]);

        return $verification;
    }

    /**
     * The same check, for an ACCOUNT-scoped token.
     *
     * A token whose resources are accounts rather than user-level has to verify against the
     * account path; the user path answers 400 for one. Which of the two applies is a property
     * of how the token was created, and nothing on the wire says which - so if verify()
     * refuses a token you are sure of, this is the other one to try.
     */
    public function verifyForAccount(string $accountId): TokenVerification
    {
        $path = 'accounts/' . Zones::identifier($accountId, 'account') . '/tokens/verify';

        $response = $this->apiGet($path);

        return TokenVerification::fromArray($response->object(), $response->meta);
    }

    // This endpoint has no collection, so the pagination bounds below are never consulted.
    // They are still stated rather than left to a default, because a default would be a
    // guess that some later method on this class could silently inherit.

    protected function minimumPageSize(): int
    {
        return 1;
    }

    protected function maximumPageSize(): int
    {
        return 1;
    }

    protected function collectionName(): string
    {
        return 'tokens';
    }
}
