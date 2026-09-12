<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Entity\Account;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Exception\NotPermittedException;
use Hampel\Cloudflare\Api\Result\Page;

/**
 * The accounts this token can see.
 *
 * https://developers.cloudflare.com/api/resources/accounts/
 *
 * NEEDS `Account Settings:Read`, WHICH A DNS TOKEN WILL NOT HAVE - and that is correct rather
 * than a limitation to work around. A credential that manages one zone's DNS has no business
 * enumerating the account it sits in, and a zone-scoped token is refused here.
 *
 * So this is for diagnostics rather than for routine work: it answers "what does this
 * credential actually reach", which on an API that publishes no permissions is the closest
 * thing to a capability report there is. find() and first() absorb the refusal so a
 * diagnostic can report what it can rather than stopping at the first thing it may not see.
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
            $this->apiGet('accounts/' . Zones::identifier($accountId, 'account'))->object()
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
     * and "it can, and there are none" come back as null, because for this purpose they are
     * the same answer: nothing to report. Anything that needs to tell them apart should call
     * list() and catch NotPermittedException itself.
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
