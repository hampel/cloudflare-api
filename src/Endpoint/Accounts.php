<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Entity\Account;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Result\Page;
use Hampel\Cloudflare\Api\Support\Identifier;

/**
 * The accounts this token can see.
 *
 * https://developers.cloudflare.com/api/resources/accounts/
 *
 * A ZONE-SCOPED TOKEN IS NOT REFUSED HERE - IT IS ANSWERED WITH NOTHING. Measured on
 * 2026-09-12: a token holding only zone permissions calls this successfully and gets
 * `200` with an empty collection and a `total_count` of 0. No 403, no error code.
 *
 * That is the trap this class documents. An empty list reads as "this user has no accounts",
 * which is never true - every zone belongs to one, and the zone payload itself names the
 * account the token supposedly cannot see. What it actually means is that the token's
 * resources include no account, so the collection is filtered to nothing. So an empty answer
 * here says something about the CREDENTIAL and not about the account, and nothing in a
 * consuming application should conclude otherwise from it.
 *
 * The 403 branch is kept because a differently-scoped token may still produce one, and
 * because absorbing a refusal is what lets a diagnostic report what it can rather than
 * stopping at the first thing it may not see.
 *
 * Either way this is for diagnostics rather than routine work: it answers "what does this
 * credential actually reach", which on an API that publishes no permissions is as close to a
 * capability report as there is.
 *
 * PAGE SIZES HERE ARE 5 TO 50, as on zones and unlike DNS records.
 */
final class Accounts extends Endpoint
{
    public const MIN_PAGE_SIZE = 5;

    public const MAX_PAGE_SIZE = 50;

    /**
     * The API's own default when a request does not ask for a size.
     */
    public const DEFAULT_PAGE_SIZE = 20;

    /**
     * One page of accounts.
     *
     * @return Page<Account>
     */
    public function list(int $page = 1, ?int $pageSize = null): Page
    {
        return $this->apiPaginate('accounts', Account::fromArray(...), $page, $pageSize);
    }

    /**
     * Every account, a page at a time.
     *
     * @return \Generator<int, Account>
     */
    public function each(?int $pageSize = null): \Generator
    {
        return $this->apiEach('accounts', Account::fromArray(...), $pageSize);
    }

    /**
     * Every account, as a list. There are rarely more than a handful.
     *
     * @return list<Account>
     */
    public function all(): array
    {
        return iterator_to_array($this->each(), false);
    }

    /**
     * One account by id.
     */
    public function get(string $accountId): Account
    {
        return Account::fromArray(
            $this->apiGet('accounts/' . Identifier::for($accountId, 'account'))->object()
        );
    }

    /**
     * One account by id, or null when it is not there or not visible.
     */
    public function find(string $accountId): ?Account
    {
        try {
            return $this->get($accountId);
        } catch (NotFoundException | NotPermittedException) {
            return null;
        }
    }

    /**
     * The first account this token can see, or null when it can see none.
     *
     * For a diagnostic reporting as much as it can. Both "the token may not read accounts"
     * and "it can, and the list came back empty" return null, because for this purpose they
     * are the same answer: nothing to report. On the evidence of 2026-09-12 the second
     * is much the likelier of the two - see the note on this class. Anything needing to tell
     * them apart should call list() and catch NotPermittedException itself.
     */
    public function first(): ?Account
    {
        try {
            $page = $this->list(1, self::MIN_PAGE_SIZE);
        } catch (NotPermittedException) {
            $this->logger->info('Cloudflare accounts are not readable by this token', [
                'permission' => 'Account Settings:Read',
            ]);

            return null;
        }

        return $page->items[0] ?? null;
    }

    protected function minimumPageSize(): int
    {
        return self::MIN_PAGE_SIZE;
    }

    protected function maximumPageSize(): int
    {
        return self::MAX_PAGE_SIZE;
    }

    protected function collectionName(): string
    {
        return 'accounts';
    }
}
