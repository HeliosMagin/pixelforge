<?php

namespace App\Enums;

enum TransactionType: string
{
    case Purchase = 'purchase';
    /** Reserves the worst-case price while a generation is in flight. */
    case Hold = 'hold';
    /** Returns the hold once the generation finishes (succeeded or failed). */
    case HoldRelease = 'hold_release';
    /** The actual price of a succeeded generation. */
    case Charge = 'charge';
}
