<?php

declare(strict_types=1);

namespace Hampel\Cloudflare\Api\Endpoint;

use Hampel\Cloudflare\Api\Entity\Registration;
use Hampel\Cloudflare\Api\Exception\InvalidArgumentException;
use Hampel\Cloudflare\Api\Exception\NotFoundException;
use Hampel\Cloudflare\Api\Support\Identifier;

/**
 * The domains an account holds through Cloudflare Registrar.
 *
 * https://developers.cloudflare.com/api/resources/registrar/
 *
 * READ-ONLY, DELIBERATELY, as with zones. `POST registrations` registers a domain, which costs
 * money, and `PATCH` changes renewal and lock settings on a domain somebody owns. Neither belongs
 * where a script that meant to read can reach it. Connection is the way out if one is needed.
 *
 * NEEDS `Account / Registrar: Domains / Read` ON THE TOKEN - an account-level permission, so the
 * token's account resources must include the account. Without it both paths here answer
 * `403, code 10000, Authentication error`, measured on 2026-09-14 before the token was granted it.
 * Cloudflare's own permissions reference does not list it; the name is the dashboard's.
 *
 * CURSOR-PAGED. `per_page` is 1 to 50 and `page` is ignored - measured: `page=2` and `page=7`
 * return the first registration again - so each() walks with apiEachByCursor().
 *
 * THIS ENDPOINT, NOT `registrar/domains`, which the specification marks deprecated and which
 * answers with a different object.
 */
final class Registrations extends Endpoint
{
    public const MIN_PAGE_SIZE = 1;

    public const MAX_PAGE_SIZE = 50;

    /**
     * The API's own default when a request does not ask for a size.
     */
    public const DEFAULT_PAGE_SIZE = 20;

    /**
     * Every registration in the account, a page at a time, fetched only as far as it is consumed.
     *
     * @return \Generator<int, Registration>
     */
    public function each(string $accountId, ?int $pageSize = null): \Generator
    {
        return $this->apiEachByCursor($this->path($accountId), Registration::fromArray(...), $pageSize);
    }

    /**
     * Every registration in the account, as a list.
     *
     * @return list<Registration>
     */
    public function all(string $accountId): array
    {
        return iterator_to_array($this->each($accountId, self::MAX_PAGE_SIZE), false);
    }

    /**
     * One registration by domain name. Raises NotFoundException when the account does not hold it.
     *
     * THE LOOKUP IS CASE-SENSITIVE AT CLOUDFLARE'S END, so the name is lower-cased here. Measured on
     * 2026-09-14: a registered domain asked for in capitals answered `404, Domain not found`, the
     * same reply as a domain the account has never held. DNS is case-insensitive and nobody means
     * a different domain by capitalising it.
     *
     * A domain the account does not hold answers `404` with code 10000 - the code that means
     * "Authentication error" on a 403 - so this is classified by its status, which is unambiguous,
     * and never by the code.
     */
    public function get(string $accountId, string $domainName): Registration
    {
        return Registration::fromArray($this->apiGet($this->path($accountId, $domainName))->object());
    }

    /**
     * One registration by domain name, or null when the account does not hold it.
     *
     * Only a 404 becomes null. A token without Registrar access answers 403 and still raises,
     * because "you may not see registrations" and "this domain is not registered here" send
     * whoever reads the answer to different places.
     */
    public function find(string $accountId, string $domainName): ?Registration
    {
        try {
            return $this->get($accountId, $domainName);
        } catch (NotFoundException) {
            return null;
        }
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
        return 'registrations';
    }

    private function path(string $accountId, ?string $domainName = null): string
    {
        $path = 'accounts/' . Identifier::for($accountId, 'account') . '/registrar/registrations';

        if ($domainName === null) {
            return $path;
        }

        $domainName = strtolower(trim($domainName, ". \t\n\r\0\x0B"));

        if ($domainName === '') {
            throw new InvalidArgumentException('A domain name is required to look a registration up.');
        }

        return $path . '/' . Identifier::for($domainName, 'domain');
    }
}
