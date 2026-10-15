<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Access\IssuedLink;
use App\Models\Customer;
use App\Models\Enrollment;

/**
 * Everything one programme entry produced.
 *
 * Programme entry is a single action that writes several rows - the business,
 * the person we deal with, their place in a batch, and the questionnaire link
 * that follows from it - so the result is more than a Customer. It is returned
 * as one object rather than fetched back piecemeal, because the link in
 * particular CANNOT be fetched back: the plaintext token exists once.
 *
 * $questionnaireProblem carries a refusal the questionnaire link hit, if it
 * hit one. It is deliberately not an exception: the customer, the contact and
 * the batch assignment are committed by then, and throwing would either lose
 * them or claim they were lost. The operator is told what did not happen and
 * the record they created is still there.
 */
final readonly class ProgrammeEntry
{
    public function __construct(
        public Customer $customer,
        public ?Enrollment $enrollment,
        public ?IssuedLink $questionnaireLink,
        public ?string $questionnaireProblem = null,
    ) {}
}
