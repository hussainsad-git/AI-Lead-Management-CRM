<?php

namespace App\Enums;

/**
 * Stable meaning of a pipeline stage. Organizations rename/reorder/add stages freely;
 * metrics and automatic transitions key off these semantics, never display names.
 */
enum StatusSemantic: string
{
    case New = 'new';
    case ContactPending = 'contact_pending';
    case ContactAttempted = 'contact_attempted';
    case NoResponse = 'no_response';
    case Responded = 'responded';
    case Interested = 'interested';
    case Qualified = 'qualified';
    case Appointment = 'appointment';
    case Proposal = 'proposal';
    case Won = 'won';
    case Lost = 'lost';
    case NotInterested = 'not_interested';
    case DoNotContact = 'do_not_contact';
    case Custom = 'custom';

    /** Rank used to make automatic transitions forward-only. Closed stages rank highest. */
    public function rank(): int
    {
        return match ($this) {
            self::New => 0, self::ContactPending => 1, self::ContactAttempted => 2, self::NoResponse => 3,
            self::Responded => 4, self::Interested => 5, self::Qualified => 6, self::Appointment => 7,
            self::Proposal => 8, self::Custom => 4,
            self::Won, self::Lost, self::NotInterested, self::DoNotContact => 100,
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::Won => 'won',
            self::Lost, self::NotInterested, self::DoNotContact => 'lost',
            default => 'open',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
