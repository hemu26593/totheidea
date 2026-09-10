<?php

declare(strict_types=1);

namespace Tests\Unit\Concerns;

use App\Domain\Shared\Concerns\HasActorTriple;
use App\Enums\ActorSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Invariant I10 as a pure rule, testable without a table.
 *
 * Conditional NOT NULL is not portably expressible across SQLite and MySQL,
 * so this invariant is application-enforced. That makes the test the only
 * thing standing between the rule and a silently actor-less row.
 */
class ActorTripleRuleTest extends TestCase
{
    #[Test]
    public function an_internal_user_write_names_the_user_and_no_grant(): void
    {
        $this->assertTrue(Subject::actorTripleIsCoherent(ActorSource::InternalUser, 7, null));
        $this->assertFalse(Subject::actorTripleIsCoherent(ActorSource::InternalUser, null, null));
        $this->assertFalse(Subject::actorTripleIsCoherent(ActorSource::InternalUser, 7, 3));
    }

    #[Test]
    public function an_external_grant_write_names_the_grant_and_no_user(): void
    {
        $this->assertTrue(Subject::actorTripleIsCoherent(ActorSource::ExternalGrant, null, 3));
        $this->assertFalse(Subject::actorTripleIsCoherent(ActorSource::ExternalGrant, null, null));
        $this->assertFalse(Subject::actorTripleIsCoherent(ActorSource::ExternalGrant, 7, 3));
    }

    #[Test]
    public function a_system_write_names_neither(): void
    {
        $this->assertTrue(Subject::actorTripleIsCoherent(ActorSource::System, null, null));
        $this->assertFalse(Subject::actorTripleIsCoherent(ActorSource::System, 7, null));
        $this->assertFalse(Subject::actorTripleIsCoherent(ActorSource::System, null, 3));
    }

    #[Test]
    public function an_absent_or_unknown_source_is_never_coherent(): void
    {
        $this->assertFalse(Subject::actorTripleIsCoherent(null, 7, null));
        $this->assertFalse(Subject::actorTripleIsCoherent('anonymous', 7, null));
    }

    #[Test]
    public function the_source_may_be_supplied_as_its_backing_value(): void
    {
        $this->assertTrue(Subject::actorTripleIsCoherent('internal_user', 7, null));
        $this->assertTrue(Subject::actorTripleIsCoherent('external_grant', null, 3));
        $this->assertTrue(Subject::actorTripleIsCoherent('system', null, null));
    }

    #[Test]
    public function guarding_an_incoherent_triple_throws(): void
    {
        $this->expectException(RuntimeException::class);

        Subject::guardActorTriple(ActorSource::InternalUser, null, null);
    }
}

/**
 * A bare consumer of the trait. Nothing here touches the database.
 */
class Subject
{
    use HasActorTriple;
}
