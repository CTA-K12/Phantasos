<?php

namespace App\Search;

use App\Enum\MediaStatus;
use App\Enum\MediaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** Filters for the admin media browser, parsed from the query string. Invalid values are ignored. */
final class MediaSearch
{
    public const PER_PAGE = 50;

    public ?Uuid $clientId = null;
    /** Full or partial (prefix) media ID or media-file ID. */
    public string $id = '';
    public ?MediaType $type = null;
    public ?MediaStatus $status = null;
    /** Include never-uploaded tickets (hidden by default; they are noise). */
    public bool $tickets = false;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $q = $request->query;
        $s = new self();

        $client = $q->getString('client');
        $s->clientId = Uuid::isValid($client) ? Uuid::fromString($client) : null;
        $s->id       = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $q->getString('id')) ?? '');
        $s->type     = MediaType::tryFrom($q->getString('type'));
        $s->status   = MediaStatus::tryFrom($q->getString('status'));
        $s->tickets  = $q->getBoolean('tickets');
        $s->from     = self::date($q->getString('from'));
        $s->to       = self::date($q->getString('to'));
        $s->page     = max(1, $q->getInt('page', 1));

        return $s;
    }

    /** @return array<string, string|int> query parameters for links (pagination keeps the filters) */
    public function toQuery(): array
    {
        return array_filter([
            'client'  => $this->clientId?->toRfc4122() ?? '',
            'id'      => $this->id,
            'type'    => $this->type !== null ? $this->type->value : '',
            'status'  => $this->status !== null ? $this->status->value : '',
            'tickets' => $this->tickets ? '1' : '',
            'from'    => $this->from?->format('Y-m-d') ?? '',
            'to'      => $this->to?->format('Y-m-d') ?? '',
        ], static fn ($v) => $v !== '');
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d === false ? null : $d;
    }
}
